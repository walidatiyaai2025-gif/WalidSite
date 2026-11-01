<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_landing_renders_bilingual_project_portal(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Waleed Atiya')
            ->assertSee('Explore projects');
    }

    public function test_laravel_health_endpoint_is_available(): void
    {
        $this->get('/up')->assertOk();
    }
}
