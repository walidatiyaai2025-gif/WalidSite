<?php

namespace Tests\Feature\AI;

use App\AI\ResearchSourceIntake;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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
            'https://docs.example.org/guide?key=private-download-key',
            'https://docs.example.org/guide?code=single-use-auth-code',
            'https://docs.example.org/guide?ticket=session-ticket',
            'https://docs.example.org/guide#key=private',
            'https://docs.example.org/guide?redirect=%3Fcode%3Dprivate',
            'https://docs.example.org/guide?X-Amz-Expires=900',
            'https://docs.example.org/guide?X_Goog_Signature=private',
            'https://longhiddenservice.onion/guide',
            'https://docs.example.org/guide?next=%2525253Fkey%2525253Dprivate',
            'https://docs.example.org/guide?next=%2525253Fcode%2525253Dprivate',
            'https://docs.example.org/guide?next=%2525253Fticket%2525253Dprivate',
            'https://docs.example.org/guide?next=%2525252525252525private',
            'https://storage.local/guide',
            'https://service.localdomain/guide',
            'https://kubernetes.default.svc/runbook',
            'https://router.lan/guide',
            'https://device.home/guide',
            'https://gateway.home.arpa/metadata',
            'https://10.2.0.192.in-addr.arpa/guide',
            'https://docs.example.internal/guide',
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

    public function test_nested_signed_query_and_fragment_keys_are_rejected_before_prompt_export(): void
    {
        $intake = new ResearchSourceIntake();

        foreach ([
            'https://docs.example.org/guide?token[]=private',
            'https://docs.example.org/guide?token[0]=private',
            'https://docs.example.org/guide?api_key[0][secret]=private',
            'https://docs.example.org/guide?source[api_key]=private',
            'https://docs.example.org/guide?source[outer][access_token]=private',
            'https://docs.example.org/guide?filter[0][refresh_token]=private',
            'https://docs.example.org/guide?source[signature][0]=private',
            'https://docs.example.org/guide?X-Amz-Signature[0]=private',
            'https://docs.example.org/guide?token%5B0%5D=private',
            'https://docs.example.org/guide?source%5Bapi_key%5D=private',
            'https://docs.example.org/guide?source%255Bapi_key%255D=private',
            'https://docs.example.org/guide#state[client_secret]=private',
            'https://docs.example.org/guide#sessionid%5B0%5D=private',
        ] as $url) {
            try {
                $intake->candidateCitations([$url]);
                $this->fail('Nested credential URL was accepted for AI prompt export.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame(
                    'Research sources must use a safe public HTTPS URL.',
                    $exception->getMessage()
                );
            }
        }
    }

    public function test_harmless_nested_public_filters_are_preserved(): void
    {
        $url = 'https://docs.example.org/guide?topic[0]=backup&lang=ar&tokenized=public';
        $this->assertSame([
            ['source_url' => $url, 'verified' => false],
        ], (new ResearchSourceIntake())->candidateCitations([$url]));
    }

    public function test_signed_url_path_segments_never_enter_unverified_source_candidates(): void
    {
        $intake = new ResearchSourceIntake();
        foreach ([
            'https://docs.example.org/guide/token=private',
            'https://docs.example.org/guide/api_key=private',
            'https://docs.example.org/guide/access_token=private',
            'https://docs.example.org/guide/X-Amz-Signature=private',
            'https://docs.example.org/guide/source[token]=private',
            'https://docs.example.org/guide/token%3Dprivate',
            'https://docs.example.org/guide/%74oken%3Dprivate',
            'https://docs.example.org/guide/%2574oken%253Dprivate',
            'https://docs.example.org/guide/source%5Bapi_key%5D%3Dprivate',
        ] as $url) {
            try {
                $intake->candidateCitations([$url]);
                $this->fail('Signed URL path was accepted as an AI prompt citation.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame(
                    'Research sources must use a safe public HTTPS URL.',
                    $exception->getMessage()
                );
            }
        }
    }

    public function test_public_noncredential_url_paths_are_unchanged(): void
    {
        $url = 'https://docs.example.org/reference/tokenized=public/keynote=guide?lang=ar';
        $this->assertSame([
            ['source_url' => $url, 'verified' => false],
        ], (new ResearchSourceIntake())->candidateCitations([$url]));
    }

    public function test_public_noncredential_query_and_fragment_remain_allowed(): void
    {
        $this->assertSame([
            ['source_url' => 'https://docs.example.org/guide?lang=ar&page=2#chapter', 'verified' => false],
        ], (new ResearchSourceIntake())->candidateCitations([
            'https://docs.example.org/guide?lang=ar&page=2#chapter',
        ]));
    }

    public function test_github_citations_require_allowlist_and_live_public_visibility(): void
    {
        Cache::flush();
        config(['portfolio.github_public_repos' => ['owner/public-repo']]);
        Http::fake([
            'https://api.github.com/repos/owner/public-repo' => Http::response([
                'private' => false,
                'visibility' => 'public',
                'full_name' => 'owner/public-repo',
                'html_url' => 'https://github.com/owner/public-repo',
                'description' => 'Verified public repository',
                'language' => 'PHP',
            ], 200),
        ]);

        $url = 'https://github.com/owner/public-repo/blob/main/README.md';
        $this->assertSame([
            ['source_url' => $url, 'verified' => false],
        ], (new ResearchSourceIntake())->candidateCitations([$url]));

        Http::assertSentCount(1);
    }

    public function test_private_unallowlisted_and_direct_github_capability_urls_never_enter_ai_sources(): void
    {
        Cache::flush();
        config(['portfolio.github_public_repos' => ['owner/private-repo']]);
        Http::fake([
            'https://api.github.com/repos/owner/private-repo' => Http::response([
                'private' => true,
                'visibility' => 'private',
                'full_name' => 'owner/private-repo',
                'html_url' => 'https://github.com/owner/private-repo',
            ], 200),
        ]);

        $intake = new ResearchSourceIntake();
        foreach ([
            'https://github.com/owner/private-repo/blob/main/README.md',
            'https://github.com/other/not-allowlisted',
            'https://api.github.com/repos/owner/private-repo',
            'https://raw.githubusercontent.com/owner/private-repo/main/secret.txt',
            'https://gist.github.com/owner/0123456789abcdef',
        ] as $url) {
            try {
                $intake->candidateCitations([$url]);
                $this->fail('Unverified GitHub source entered the AI citation set.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame(
                    'Research sources must use a safe public HTTPS URL.',
                    $exception->getMessage()
                );
            }
        }

        Http::assertSentCount(2);
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
