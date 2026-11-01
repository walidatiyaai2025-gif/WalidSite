<?php

namespace Tests\Feature\Api;

use App\Models\ArticleRevision;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ArticleCitationNestedCredentialTest extends TestCase
{
    private function safe(string $url): bool
    {
        $method = new \ReflectionMethod(ArticleRevision::class, 'isSafePublicSourceUrl');

        return $method->invoke(null, $url);
    }

    public static function unsafeSources(): array
    {
        return [
            'plain token array' => ['https://docs.example.org/guide?token[0]=secret'],
            'nested API key' => ['https://docs.example.org/guide?source[api_key]=secret'],
            'deep nested access token' => ['https://docs.example.org/guide?filter[outer][access_token]=secret'],
            'nested signature' => ['https://docs.example.org/guide?source[x-amz-signature]=secret'],
            'encoded bracket' => ['https://docs.example.org/guide?source%5Bapi_key%5D=secret'],
            'double encoded bracket' => ['https://docs.example.org/guide?source%255Bapi_key%255D=secret'],
            'fragment token' => ['https://docs.example.org/guide#token[0]=secret'],
            'fragment nested secret' => ['https://docs.example.org/guide#filter[secret]=secret'],
            'triple encoded token' => ['https://docs.example.org/guide?token%25255B0%25255D=secret'],
        ];
    }

    #[DataProvider('unsafeSources')]
    public function test_nested_credentials_are_not_public_citations(string $url): void
    {
        $this->assertFalse($this->safe($url));
    }

    public function test_benign_nested_and_localized_public_sources_stay_available(): void
    {
        foreach ([
            'https://docs.example.org/guide?lang=ar',
            'https://docs.example.org/guide?topic[0]=backup',
            'https://docs.example.org/guide?source[topic]=backup',
            'https://docs.example.org/guide?tokenized=public',
        ] as $url) {
            $this->assertTrue($this->safe($url), $url);
        }
    }
}
