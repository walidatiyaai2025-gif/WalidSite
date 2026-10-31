<?php

namespace Tests\Feature\AI;

use App\AI\ResearchSourceIntake;
use InvalidArgumentException;
use Tests\TestCase;

class ResearchSourceIntakeTest extends TestCase
{
    public function test_normalizes_and_deduplicates_public_https_sources_without_verifying_them(): void
    {
        $intake = new ResearchSourceIntake();

        $citations = $intake->candidateCitations([
            ' HTTPS://Docs.Example.org:443/guide#intro ',
            'https://docs.example.org/guide#intro',
        ]);

        $this->assertSame([
            ['source_url' => 'https://docs.example.org/guide#intro', 'verified' => false],
        ], $citations);
    }

    public function test_rejects_unsafe_urls_before_any_source_fetch(): void
    {
        $intake = new ResearchSourceIntake();

        foreach ([
            'http://docs.example.org/guide',
            'https://localhost/guide',
            'https://127.0.0.1/guide',
            'https://user:password@docs.example.org/guide',
            'https://docs.example.org:8443/guide',
            'https://docs.example.org/guide?api_key=secret',
            'https://docs.example.org/guide?api%5Fkey=secret',
            'https://docs.example.org/guide?next=%253Faccess_token%253Dstolen',
            'https://docs.example.org/guide?X-Amz-Signature=signed',
            'https://docs.example.org/guide?X-Goog-Credential=private',
            'https://docs.example.org/guide?refresh_token=secret',
            'https://docs.example.org/guide#token=private',
            'https://docs.example.org/guide#sessionid=private',
            'https://docs.example.org/guide?lang=en;client_secret=private',
            'https://storage.local/guide',
            'not a URL',
        ] as $url) {
            try {
                $intake->candidateCitations([$url]);
                $this->fail('Unsafe research source was accepted.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_public_noncredential_query_and_fragment_remain_allowed(): void
    {
        $this->assertSame([
            ['source_url' => 'https://docs.example.org/guide?lang=ar&page=2#chapter', 'verified' => false],
        ], (new ResearchSourceIntake())->candidateCitations([
            'https://docs.example.org/guide?lang=ar&page=2#chapter',
        ]));
    }

    public function test_requires_one_to_twenty_source_candidates(): void
    {
        $intake = new ResearchSourceIntake();

        foreach ([[], array_fill(0, 21, 'https://docs.example.org/guide')] as $urls) {
            try {
                $intake->candidateCitations($urls);
                $this->fail('Out-of-range research source count was accepted.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_rejects_non_string_source_candidates(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ResearchSourceIntake())->candidateCitations(['https://docs.example.org/guide', null]);
    }
}
