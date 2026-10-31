<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_public_landing_renders_verified_bootstrap_status(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Waleed Atiya')
            ->assertSee('Platform foundation in development');
    }

    public function test_laravel_health_endpoint_is_available(): void
    {
        $this->get('/up')->assertOk();
    }
}
