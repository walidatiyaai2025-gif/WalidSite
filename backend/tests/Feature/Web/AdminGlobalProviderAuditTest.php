<?php

namespace Tests\Feature\Web;

use App\Models\AiProvider;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminGlobalProviderAuditTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'owner'): User
    {
        $user = User::create([
            'email' => Str::random(15).'@example.test',
            'status' => 'active',
            'password_hash' => null,
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role])->id);

        return $user;
    }

    private function provider(string $name): AiProvider
    {
        return AiProvider::create([
            'display_name' => $name,
            'provider_type' => 'openai-compatible',
            'base_url' => 'https://PRIVATE_ENDPOINT_NOT_FOR_UI.example.test',
            'secret_ciphertext' => 'PRIVATE_TOKEN_NOT_FOR_UI',
            'enabled' => true,
            'health_status' => 'unverified',
            'routing_priority' => 10,
            'daily_budget_minor' => 100,
            'currency' => 'USD',
            'concurrent_limit' => 1,
            'timeout_seconds' => 5,
        ]);
    }

    private function event(AiProvider $provider, User $actor, string $action,
        array $fields, \DateTimeInterface $at): string
    {
        $id = (string) Str::uuid();
        DB::table('ai_provider_audit_events')->insert([
            'id' => $id,
            'provider_id' => $provider->id,
            'actor_id' => $actor->id,
            'action' => $action,
            'changed_fields_json' => json_encode($fields, JSON_THROW_ON_ERROR),
            'created_at' => $at,
        ]);
        return $id;
    }

    public function test_overview_to_audit_is_bilingual_admin_only_and_empty_state_is_honest(): void
    {
        Http::fake();
        $this->get('/admin/audit')->assertRedirect('/admin/login');
        $this->actingAs($this->user('reader'), 'web')
            ->get('/admin/audit')->assertForbidden();
        $this->actingAs($this->user('admin'), 'web');

        $this->get('/admin?lang=en')->assertOk()
            ->assertSee('href="'.route('admin.audit.index', ['lang'=>'en']).'"', false);
        $this->get('/admin/ai/providers?lang=ar')->assertOk()
            ->assertSee('href="'.route('admin.audit.index', ['lang'=>'ar']).'"', false);

        $english = $this->get('/admin/audit?lang=en')->assertOk()
            ->assertSee('AI provider configuration audit')
            ->assertSee('No configuration events match these filters.')
            ->assertSee('All providers')
            ->assertSee('Export CSV')
            ->assertSee('noindex,nofollow', false)
            ->assertSee('id="content" tabindex="-1"', false);
        $this->assertStringContainsString('no-store',
            $english->headers->get('Cache-Control'));

        $this->get('/admin/audit?lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('سجل تدقيق إعدادات مزودي AI')
            ->assertSee('لا توجد أحداث إعدادات مطابقة للتصفية الحالية.')
            ->assertSee('تصدير CSV');
        Http::assertNothingSent();
    }

    public function test_persisted_provider_history_filters_paginates_and_never_discloses_values(): void
    {
        Http::fake();
        $actor = $this->user('owner');
        $this->actingAs($actor, 'web');
        $primary = $this->provider('Primary audited service');
        $archived = $this->provider('Archived audited service');
        for ($index = 0; $index < 25; $index++) {
            $this->event($primary, $actor, $index % 2 === 0 ? 'updated' : 'tested',
                ['routing_priority', 'secret_rotated', 'PRIVATE_OLD_PASSWORD'],
                now('UTC')->subMinutes($index));
        }
        $this->event($archived, $actor, 'deleted',
            ['secret_removed', 'PRIVATE_DELETION_REASON'],
            now('UTC')->subDays(3));
        $archived->delete();

        $first = $this->get('/admin/audit?lang=en')->assertOk()
            ->assertSee('Recorded events (26)')
            ->assertSee('Primary audited service')
            ->assertDontSee('(Archived)')
            ->assertSee('page=2', false)
            ->assertSee('routing_priority')
            ->assertSee('secret_rotated')
            ->assertDontSee('PRIVATE_OLD_PASSWORD')
            ->assertDontSee('PRIVATE_DELETION_REASON')
            ->assertDontSee('PRIVATE_ENDPOINT_NOT_FOR_UI')
            ->assertDontSee('PRIVATE_TOKEN_NOT_FOR_UI')
            ->assertDontSee($actor->email);
        $this->assertSame(20, substr_count($first->getContent(), '<tr>') - 1);

        $second = $this->get('/admin/audit?lang=en&page=2')->assertOk()
            ->assertSee('Recorded events (26)')
            ->assertSee('Archived audited service')
            ->assertSee('(Archived)');
        $this->assertSame(6, substr_count($second->getContent(), '<tr>' )
            - 1); // One table header row.

        $this->get('/admin/audit?lang=ar&provider='.$archived->id)
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('الأحداث المسجلة (1)')
            ->assertSee('Archived audited service')
            ->assertSee('مؤرشف')
            ->assertSee('secret_removed')
            ->assertDontSee('PRIVATE_DELETION_REASON');
        $this->get('/admin/audit?lang=en&provider='.$primary->id.'&action=updated')
            ->assertOk()
            ->assertSee('Recorded events (13)')
            ->assertDontSee('(Archived)')
            ->assertSee('Updated');
        $this->get('/admin/audit?lang=en&action=deleted')
            ->assertOk()
            ->assertSee('Recorded events (1)')
            ->assertSee('Archived audited service');
        Http::assertNothingSent();
    }

    public function test_day_filter_rejects_untrusted_inputs_and_export_redacts_spreadsheet_cells(): void
    {
        Http::fake();
        $actor = $this->user();
        $this->actingAs($actor, 'web');
        $name = '=HYPERLINK("https://malicious.example.test","bad")';
        $provider = $this->provider($name);
        $this->event($provider, $actor, 'updated',
            ['display_name', 'base_url', 'secret_ciphertext', 'RAW_PRIVATE_TOKEN'],
            now('UTC')->startOfDay()->addHours(12));
        $this->event($provider, $actor, 'tested', ['enabled'],
            now('UTC')->subDay()->startOfDay()->addHours(12));

        $today = now('UTC')->toDateString();
        $this->get('/admin/audit?lang=ar&day='.$today.'&provider='.$provider->id)
            ->assertOk()
            ->assertSee('الأحداث المسجلة (1)')
            ->assertSee('base_url')
            ->assertDontSee('RAW_PRIVATE_TOKEN')
            ->assertDontSee('PRIVATE_ENDPOINT_NOT_FOR_UI');
        $this->get('/admin/audit?lang=en&day='.now('UTC')->subDay()->toDateString())
            ->assertOk()->assertSee('Recorded events (1)')
            ->assertSee('Connection test');

        foreach ([
            'provider[]=bad', 'provider=not-a-uuid', 'action=removed',
            'action[]=created', 'page[]=2', 'page=0', 'page=10001',
            'lang[]=ar', 'lang=de', 'day[]=2026-11-01', 'day=invalid',
            'day='.now('UTC')->addDay()->toDateString(),
            'format=html',
        ] as $query) {
            $this->get('/admin/audit?'.$query)
                ->assertRedirect()->assertSessionHasErrors();
        }

        $csv = $this->get('/admin/audit?format=csv&lang=en&provider='.$provider->id
            .'&action=updated&day='.$today)->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Export-Truncated', 'false')
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $data = $csv->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $data);
        $this->assertStringContainsString('"UTC time",Provider,Action', $data);
        $this->assertStringContainsString('Changed field names', $data);
        $this->assertStringContainsString("'=HYPERLINK(", $data);
        $this->assertStringContainsString('display_name', $data);
        $this->assertStringContainsString('base_url', $data);
        $this->assertStringNotContainsString('secret_ciphertext', $data);
        $this->assertStringNotContainsString('RAW_PRIVATE_TOKEN', $data);
        $this->assertStringNotContainsString('PRIVATE_ENDPOINT_NOT_FOR_UI', $data);
        $this->assertStringNotContainsString('PRIVATE_TOKEN_NOT_FOR_UI', $data);
        $this->assertStringNotContainsString($actor->email, $data);
        $this->assertSame(2, substr_count($data, "\n")); // Header + one event.
        Http::assertNothingSent();
    }

    public function test_csv_export_caps_records_without_altering_persisted_audit(): void
    {
        $actor = $this->user();
        $this->actingAs($actor, 'web');
        $provider = $this->provider('Batch export provider');
        $rows = [];
        for ($index = 1; $index <= 1002; $index++) {
            $rows[] = [
                'id' => sprintf('00000000-0000-4000-8000-%012d', $index),
                'provider_id' => $provider->id,
                'actor_id' => $actor->id,
                'action' => 'updated',
                'changed_fields_json' => json_encode(['enabled']),
                'created_at' => now('UTC')->subSeconds($index),
            ];
        }
        foreach (array_chunk($rows, 150) as $chunk) {
            DB::table('ai_provider_audit_events')->insert($chunk);
        }

        $csv = $this->get('/admin/audit?format=csv')->assertOk()
            ->assertHeader('X-Export-Truncated', 'true');
        $this->assertSame(1001, substr_count($csv->streamedContent(), "\n"));
        $this->assertDatabaseCount('ai_provider_audit_events', 1002);
    }
}
