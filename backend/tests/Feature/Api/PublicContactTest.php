<?php

namespace Tests\Feature\Api;

use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class PublicContactTest extends TestCase
{
    private function contact(array $overrides = []): array
    {
        return array_replace([
            'name' => 'مهندس البرمجيات',
            'email' => 'visitor@example.test',
            'message' => 'A documented technical question about databases.',
            'privacy_consent' => true,
        ], $overrides);
    }

    private function enableDelivery(): void
    {
        config([
            'mail.contact_recipient' => 'configured-owner@example.test',
            'mail.default' => 'smtp',
        ]);
    }

    public function test_anonymous_json_submission_requires_real_configured_mail_and_never_claims_mock_delivery(): void
    {
        Mail::fake();

        foreach ([
            ['mail.contact_recipient' => null, 'mail.default' => 'smtp'],
            ['mail.contact_recipient' => 'not-an-email', 'mail.default' => 'smtp'],
            ['mail.contact_recipient' => 'owner@example.test', 'mail.default' => 'log'],
            ['mail.contact_recipient' => 'owner@example.test', 'mail.default' => 'array'],
            ['mail.contact_recipient' => 'owner@example.test', 'mail.default' => 'failover'],
        ] as $settings) {
            config($settings);
            $this->postJson('/api/v1/public/contact', $this->contact())
                ->assertStatus(503)
                ->assertDontSee('visitor@example.test')
                ->assertDontSee('owner@example.test');
        }
        Mail::assertNothingSent();
    }

    public function test_valid_consent_and_bilingual_unicode_message_are_handed_to_text_only_transport(): void
    {
        $this->enableDelivery();
        Mail::fake();

        $response = $this->postJson('/api/v1/public/contact', $this->contact())
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.accepted_by_transport', true)
            ->assertJsonStructure(['ok', 'data', 'timestamp', 'request_id'])
            ->assertDontSee('visitor@example.test')
            ->assertDontSee('configured-owner@example.test')
            ->assertDontSee('مهندس البرمجيات')
            ->assertDontSee('technical question');
        $this->assertTrue(\Illuminate\Support\Str::isUuid($response->json('request_id')));
        $this->assertNotEmpty($response->json('timestamp'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control', ''));

        Mail::assertSentCount(1);
        Mail::assertSent(Mailable::class, static fn (Mailable $mail): bool =>
            $mail->subject === 'WalidSite contact inquiry'
            && str_contains($mail->viewData['messageBody'] ?? '', 'مهندس البرمجيات')
            && str_contains($mail->viewData['messageBody'] ?? '', 'visitor@example.test')
        );
    }

    public function test_input_contract_rejects_unsafe_optional_fields_missing_consent_and_bad_values(): void
    {
        $this->enableDelivery();
        Mail::fake();

        foreach ([
            [['privacy_consent' => false], 'privacy_consent'],
            [['privacy_consent' => null], 'privacy_consent'],
            [['privacy_consent' => 'yes'], 'privacy_consent'],
            [['email' => 'not-an-email'], 'email'],
            [['name' => "Bad\r\nBcc: leak@example.test"], 'name'],
            [['message' => "A private message\0must not pass"], 'message'],
            [['message' => 'short'], 'message'],
            [['message' => str_repeat('x', 3001)], 'message'],
            [['email' => 'visitor@example.test', 'attachment' => 'private-data'], 'payload'],
            [['subject' => 'Header override'], 'payload'],
        ] as [$changes, $field]) {
            $this->postJson('/api/v1/public/contact', $this->contact($changes))
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$field]);
        }

        // The required boolean must be present, not just defaulted to true.
        $noConsent = $this->contact();
        unset($noConsent['privacy_consent']);
        $this->postJson('/api/v1/public/contact', $noConsent)
            ->assertUnprocessable()->assertJsonValidationErrors('privacy_consent');
        Mail::assertNothingSent();
    }

    public function test_failed_transport_is_503_and_logs_only_an_exception_type(): void
    {
        $this->enableDelivery();
        Log::spy();
        Mail::shouldReceive('to')->once()
            ->andThrow(new \RuntimeException('private-smtp-password-and-user-message'));

        $this->postJson('/api/v1/public/contact', $this->contact())
            ->assertStatus(503)
            ->assertDontSee('private-smtp-password-and-user-message')
            ->assertDontSee('visitor@example.test')
            ->assertDontSee('configured-owner@example.test');

        Log::shouldHaveReceived('warning')->once()->with(
            'public.contact.delivery_failed',
            ['type' => \RuntimeException::class]
        );
    }

    public function test_plain_http_is_rejected_before_contact_data_is_processed_in_production(): void
    {
        $this->enableDelivery();
        Mail::fake();
        $prior = $this->app['env'];
        $this->app->instance('env', 'production');

        try {
            $this->postJson('http://localhost/api/v1/public/contact', $this->contact())
                ->assertForbidden();
            Mail::assertNothingSent();

            $this->postJson('https://localhost/api/v1/public/contact', $this->contact())
                ->assertOk()
                ->assertJsonPath('data.accepted_by_transport', true);
            Mail::assertSentCount(1);
        } finally {
            $this->app->instance('env', $prior);
        }
    }

    public function test_public_api_contact_has_bounded_four_per_minute_spam_gate(): void
    {
        $this->enableDelivery();
        Mail::fake();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.187']);

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/v1/public/contact', $this->contact())
                ->assertOk();
        }
        $this->postJson('/api/v1/public/contact', $this->contact())
            ->assertStatus(429);
        Mail::assertSentCount(4);
    }
}
