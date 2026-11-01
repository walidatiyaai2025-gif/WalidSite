<?php

namespace Tests\Feature\Web;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PublicSearchValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_malformed_oversized_and_unsupported_search_parameters(): void
    {
        $this->getJson('/search?q%5B%5D=php')->assertUnprocessable();
        $this->getJson('/search?q='.str_repeat('x', 81))->assertUnprocessable();
        $this->getJson('/search?lang%5B%5D=ar')->assertUnprocessable();
        $this->getJson('/search?lang=fr')->assertUnprocessable();
    }

    public function test_valid_bilingual_search_preserves_query_without_truncation(): void
    {
        $query = str_repeat('a', 80);
        $this->get('/search?'.http_build_query(['q' => $query, 'lang' => 'en']))
            ->assertOk()->assertSee($query)->assertSee('lang="en"', false);

        $this->get('/search?'.http_build_query(['q' => 'قواعد بيانات', 'lang' => 'ar']))
            ->assertOk()->assertSee('قواعد بيانات')->assertSee('dir="rtl"', false);

        $this->get('/search?lang=ar')->assertOk();
    }
}
