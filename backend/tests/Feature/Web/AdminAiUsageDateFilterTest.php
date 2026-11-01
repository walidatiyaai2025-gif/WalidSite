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

final class AdminAiUsageDateFilterTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role = 'owner'): User
    {
        $user = User::create([
            'email' => Str::random(14).'@example.test',
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
            'base_url' => 'https://api.example.test/v1',
            'enabled' => false,
            'health_status' => 'unverified',
            'routing_priority' => 100,
            'concurrent_limit' => 1,
            'timeout_seconds' => 5,
            'daily_budget_minor' => 4000,
            'currency' => 'USD',
        ]);
    }

    private function receipt(AiProvider $provider, string $time, string $currency, int $cost): void
    {
        $jobId = (string) Str::uuid();
        DB::table('ai_jobs')->insert([
            'id' => $jobId,
            'provider_id' => $provider->id,
            'job_type' => 'test-usage',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => str_repeat('a', 64),
            'status' => 'completed',
            'created_at' => $time,
            'updated_at' => $time,
        ]);
        DB::table('ai_usage')->insert([
            'id' => (string) Str::uuid(),
            'usage_key' => (string) Str::uuid(),
            'job_id' => $jobId,
            'provider_id' => $provider->id,
            'cost_minor' => $cost,
            'cost_currency' => $currency,
            'created_at' => $time,
        ]);
    }

    public function test_historical_utc_day_preserves_currency_and_archived_receipts(): void
    {
        Http::fake();
        $this->actingAs($this->account(), 'web');
        $active = $this->provider('Active receipt vendor');
        $archived = $this->provider('Archived receipt vendor');

        $today = now('UTC')->startOfDay();
        $yesterday = $today->copy()->subDay();
        $day = $yesterday->toDateString();

        $this->receipt($active, $yesterday->copy()->addHours(2)->toDateTimeString(), 'USD', 110);
        $this->receipt($active, $yesterday->copy()->addHours(6)->toDateTimeString(), 'KWD', 40);
        $this->receipt($archived, $yesterday->copy()->addHours(7)->toDateTimeString(), 'USD', 15);
        $this->receipt($active, $today->copy()->addHours(2)->toDateTimeString(), 'USD', 900);
        $this->receipt($active, $yesterday->copy()->subDay()->addHours(2)->toDateTimeString(), 'EUR', 87);
        $archived->delete();

        $response = $this->get('/admin/ai/usage?lang=ar&day='.$day)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('dir="rtl"', false)
            ->assertSee('إيصالات اليوم المحدد')
            ->assertSee('تاريخ الإيصالات (UTC)')
            ->assertSee($day)
            ->assertSee('Active receipt vendor')
            ->assertSee('Archived receipt vendor')
            ->assertSee('مؤرشف')
            ->assertSee('<td>110</td>', false)
            ->assertSee('<td>40</td>', false)
            ->assertSee('<td>15</td>', false)
            ->assertDontSee('<td>900</td>', false)
            ->assertDontSee('<td>87</td>', false);
        $this->assertSame(3, substr_count($response->getContent(), '<td>1</td>'));

        $this->get('/admin/ai/usage?lang=en')->assertOk()
            ->assertSee('Only persisted usage receipts for today (UTC).')
            ->assertSee('<td>900</td>', false)
            ->assertDontSee('<td>110</td>', false)
            ->assertDontSee('Archived receipt vendor');

        $this->get('/admin/ai/usage?lang=en&day='.$day)->assertOk()
            ->assertSee('Selected day receipts')
            ->assertSee('Receipt day (UTC)')
            ->assertSee('day='.$day, false)
            ->assertSee('Today (UTC)');
        Http::assertNothingSent();
    }

    public function test_usage_day_is_validated_and_admin_access_is_required(): void
    {
        $this->get('/admin/ai/usage')->assertRedirect('/admin/login');

        $this->actingAs($this->account('reader'), 'web')
            ->get('/admin/ai/usage')->assertForbidden();

        $this->actingAs($this->account(), 'web');
        $today = now('UTC')->startOfDay();
        $earliest = $today->copy()->subDays(90)->toDateString();
        $this->get('/admin/ai/usage?day='.$earliest)->assertOk()
            ->assertSee('Selected audit day:');

        $invalid = [
            'day='.$today->copy()->addDay()->toDateString(),
            'day='.$today->copy()->subDays(91)->toDateString(),
            'day=2026-02-30',
            'day=not-a-date',
            'day[]=2026-11-01',
            'day=2026/11/01',
            'lang=fr',
            'page[]=2',
        ];
        foreach ($invalid as $query) {
            $this->get('/admin/ai/usage?'.$query)
                ->assertRedirect()->assertSessionHasErrors();
        }
    }

    public function test_usage_pagination_keeps_selected_day_and_locale(): void
    {
        $this->actingAs($this->account(), 'web');
        $day = now('UTC')->subDay()->toDateString();
        for ($i = 0; $i < 21; $i++) {
            $this->provider('Vendor '.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
        }

        $this->get('/admin/ai/usage?lang=ar&day='.$day)->assertOk()
            ->assertSee('page=2', false)
            ->assertSee('day='.$day, false)
            ->assertSee('Vendor 00')
            ->assertDontSee('Vendor 20');

        $this->get('/admin/ai/usage?lang=ar&day='.$day.'&page=2')->assertOk()
            ->assertSee('Vendor 20')
            ->assertDontSee('Vendor 00')
            ->assertSee('dir="rtl"', false);
    }
}
