<?php

namespace Tests\Feature\Web;

use App\Models\AiProvider;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class VerifiedAdminOverviewTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $role): User
    {
        $user = User::create([
            'email' => Str::random(16).'@example.test',
            'status' => 'active',
            'password_hash' => null,
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role])->id);

        return $user;
    }

    public function test_guest_cannot_see_admin_metrics_and_reader_is_forbidden(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->actingAs($this->userWithRole('reader'), 'web')
            ->get('/admin')->assertForbidden();
    }

    public function test_overview_counts_are_real_and_secrets_do_not_appear(): void
    {
        $this->actingAs($this->userWithRole('owner'), 'web');

        $this->get('/admin')->assertOk()
            ->assertSee('Administration overview')
            ->assertSee('Configured providers')
            ->assertSee('AI providers');

        AiProvider::create([
            'display_name' => 'Healthy Fixture',
            'provider_type' => 'ollama-compatible',
            'base_url' => 'http://127.0.0.1:11434',
            'secret_ciphertext' => 'private-fixture-token',
            'enabled' => true,
            'health_status' => 'healthy',
        ]);
        AiProvider::create([
            'display_name' => 'Unverified Fixture',
            'provider_type' => 'openai-compatible',
            'base_url' => 'https://example.test/v1',
            'enabled' => false,
            'health_status' => 'unverified',
        ]);

        $this->get('/admin')->assertOk()
            ->assertSee('Configured providers')
            ->assertSee('>2</strong>', false)
            ->assertSee('Enabled')
            ->assertSee('Healthy')
            ->assertSee('Unverified')
            ->assertSee('>1</strong>', false)
            ->assertDontSee('private-fixture-token')
            ->assertDontSee('Healthy Fixture')
            ->assertDontSee('Unverified Fixture')
            ->assertSee('href="'.route('admin.providers.index', ['lang' => 'en']).'"', false);

        $this->get('/admin?lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('نظرة عامة');
    }

    public function test_admin_role_also_has_access_without_api_token(): void
    {
        $this->actingAs($this->userWithRole('admin'), 'web')
            ->get('/admin')->assertOk();
    }
}
