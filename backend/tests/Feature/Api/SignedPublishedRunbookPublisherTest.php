<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Support\Runbooks\SignedPublishedRunbookPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class SignedPublishedRunbookPublisherTest extends TestCase
{
    use RefreshDatabase;

    private string $secret;
    private string $public;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('sodium_crypto_sign_detached')) {
            $this->markTestSkipped('libsodium Ed25519 signing is required.');
        }

        $pair = sodium_crypto_sign_keypair();
        $this->secret = base64_encode(sodium_crypto_sign_secretkey($pair));
        $this->public = sodium_crypto_sign_publickey($pair);
        sodium_memzero($pair);
    }

    private function guide(string $slug = 'safe-recovery'): string
    {
        $id = (string) Str::uuid();
        $body = '# Reviewed recovery'.PHP_EOL.'Confirm the backup.';
        DB::table('runbooks')->insert([
            'id' => $id,
            'slug' => $slug,
            'title' => 'Reviewed recovery',
            'body_md' => $body,
            'version' => 3,
            'validated_at' => now()->subMinutes(5),
            'offline_ok' => true,
        ]);
        DB::table('runbook_versions')->insert([
            'id' => (string) Str::uuid(),
            'runbook_id' => $id,
            'version' => 3,
            'body_md' => $body,
            'reviewed_title' => 'Reviewed recovery',
            'checksum' => hash('sha256', $body),
            'created_at' => now()->subMinutes(6),
        ]);

        return $id;
    }

    private function publisher(): SignedPublishedRunbookPublisher
    {
        return app(SignedPublishedRunbookPublisher::class);
    }

    public function test_publisher_creates_mobile_compatible_signed_exact_live_revision_without_unapproved_translation(): void
    {
        $id = $this->guide();
        $raw = $this->publisher()->prepare([$id], $this->secret);
        $this->assertLessThan(800 * 1024, strlen($raw));

        $envelope = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('walidsite.signed-runbook-pack.v1', $envelope['schema']);
        $this->assertSame(['schema', 'payload_base64', 'signature_base64'], array_keys($envelope));
        $payload = base64_decode($envelope['payload_base64'], true);
        $signature = base64_decode($envelope['signature_base64'], true);
        $this->assertIsString($payload);
        $this->assertIsString($signature);
        $this->assertTrue(sodium_crypto_sign_verify_detached($signature, $payload, $this->public));

        $pack = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('walidsite.offline-runbook-pack.v1', $pack['schema']);
        $this->assertSame(trim((string) file_get_contents(base_path('VERSION'))), $pack['version']);
        $this->assertCount(1, $pack['items']);
        $item = $pack['items'][0];
        $live = $this->getJson('/api/v1/mobile/solutions/'.$id)->assertOk()->json('data');
        $this->assertSame($live['id'], $item['id']);
        $this->assertSame($live['title'], $item['title_en']);
        $this->assertSame($live['title'], $item['title_ar']);
        $this->assertSame($live['version'], $item['published_version']);
        $this->assertSame($live['markdown'], $item['published_markdown']);
        $this->assertSame($live['validated_at'], $item['published_validated_at']);
        $this->assertSame(hash('sha256', $live['markdown']), $item['published_body_sha256']);
        $this->assertTrue((bool) preg_match('/\A[a-f0-9]{64}\z/', $item['published_body_sha256']));
        $this->assertNotSame('', $pack['verified_at']);
        $this->assertSame($pack['verified_at'], $pack['last_synced_at']);
        $this->assertStringNotContainsString($this->secret, $raw);
        $this->assertArrayNotHasKey('publisher_private_key', $pack);
        $this->assertArrayNotHasKey('slug', $item);
    }

    public function test_revoked_rewritten_and_unreviewed_revisions_fail_closed_without_signing(): void
    {
        $id = $this->guide();
        $publisher = $this->publisher();

        $changes = [
            ['validated_at' => null],
            ['offline_ok' => false],
            ['body_md' => '# silently modified body'],
            ['title' => 'Unreviewed changed title'],
            ['version' => 4],
            ['validated_at' => now()->addDay()],
        ];
        foreach ($changes as $change) {
            $original = (array) DB::table('runbooks')->where('id', $id)->first();
            DB::table('runbooks')->where('id', $id)->update($change);
            try {
                $publisher->prepare([$id], $this->secret);
                $this->fail('Revoked, changed or unreviewed runbook must never be signed.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Current reviewed publication unavailable.', $exception->getMessage());
            } finally {
                DB::table('runbooks')->where('id', $id)->update($original);
            }
        }
    }

    public function test_rejects_bad_private_keys_duplicates_foreign_ids_and_large_public_body(): void
    {
        $id = $this->guide();
        $publisher = $this->publisher();
        foreach ([
            [[$id], ''],
            [[$id], base64_encode(str_repeat('x', 63))],
            [[$id, $id], $this->secret],
            [['not-a-uuid'], $this->secret],
            [[strtoupper($id)], $this->secret],
            [[], $this->secret],
            [array_fill(0, 21, $id), $this->secret],
        ] as [$ids, $key]) {
            try {
                $publisher->prepare($ids, $key);
                $this->fail('Invalid private key or bounded selection must fail closed.');
            } catch (RuntimeException $exception) {
                $this->assertNotSame('', $exception->getMessage());
                $this->assertStringNotContainsString($this->secret, $exception->getMessage());
            }
        }

        DB::table('runbooks')->where('id', $id)->update(['body_md' => str_repeat('x', 66000)]);
        DB::table('runbook_versions')->where('runbook_id', $id)->update([
            'body_md' => str_repeat('x', 66000),
            'checksum' => hash('sha256', str_repeat('x', 66000)),
        ]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Reviewed publication exceeds signed pack limits.');
        $publisher->prepare([$id], $this->secret);
    }

    public function test_private_candidate_is_immutable_sha_named_and_never_overwrites(): void
    {
        $id = $this->guide();
        $publisher = $this->publisher();
        $envelope = $publisher->prepare([$id], $this->secret);
        $dir = storage_path('framework/testing/runbook-publisher-'.Str::random(12));
        $this->assertTrue(mkdir($dir, 0700, true));
        try {
            $receipt = $publisher->storePrivate($envelope, $dir);
            $this->assertSame(hash('sha256', $envelope), $receipt['sha256']);
            $this->assertSame('reviewed-runbooks-'.$receipt['sha256'].'.json', $receipt['file']);
            $path = $dir.DIRECTORY_SEPARATOR.$receipt['file'];
            $this->assertSame($envelope, file_get_contents($path));
            $this->assertSame(0600, fileperms($path) & 0777);
            try {
                $publisher->storePrivate($envelope, $dir);
                $this->fail('Immutable published candidate must refuse overwrite.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('already exists', $exception->getMessage());
            }
            $this->assertSame($envelope, file_get_contents($path));
        } finally {
            foreach (glob($dir.DIRECTORY_SEPARATOR.'*') ?: [] as $entry) {
                unlink($entry);
            }
            @unlink($dir.DIRECTORY_SEPARATOR.'.publisher.lock');
            @rmdir($dir);
        }
    }

    public function test_public_web_root_is_never_accepted_as_a_private_signed_publisher_destination(): void
    {
        $id = $this->guide();
        $envelope = $this->publisher()->prepare([$id], $this->secret);
        $expected = public_path('reviewed-runbooks-'.hash('sha256', $envelope).'.json');
        $this->assertFileDoesNotExist($expected);
        try {
            $this->publisher()->storePrivate($envelope, public_path());
            $this->fail('Signed private publisher output was written into public_html.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Private publisher output directory', $error->getMessage());
        }
        $this->assertFileDoesNotExist($expected);
    }

    public function test_hardlinked_publisher_lock_is_rejected_without_changing_unrelated_file(): void
    {
        $id = $this->guide();
        $envelope = $this->publisher()->prepare([$id], $this->secret);
        $dir = storage_path('framework/testing/runbook-publisher-'.Str::random(12));
        $this->assertTrue(mkdir($dir, 0700, true));
        $unrelated = $dir.'/unrelated-data';
        $lock = $dir.'/.publisher.lock';
        $this->assertSame(strlen('untouched-private-data'),
            file_put_contents($unrelated, 'untouched-private-data'));
        if (! @link($unrelated, $lock)) {
            @unlink($unrelated);
            @rmdir($dir);
            $this->markTestSkipped('Host does not support hardlinks.');
        }
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Unsafe publisher output inode');
            $this->publisher()->storePrivate($envelope, $dir);
        } finally {
            $this->assertSame('untouched-private-data', file_get_contents($unrelated));
            $this->assertSame([], glob($dir.'/reviewed-runbooks-*') ?: []);
            @unlink($lock);
            @unlink($unrelated);
            @rmdir($dir);
        }
    }

    public function test_existing_symlink_destination_preserves_original_data_and_cannot_be_published(): void
    {
        $id = $this->guide();
        $envelope = $this->publisher()->prepare([$id], $this->secret);
        $dir = storage_path('framework/testing/runbook-publisher-'.Str::random(12));
        $this->assertTrue(mkdir($dir, 0700, true));
        $outside = $dir.'/unrelated-data';
        $this->assertSame(11, file_put_contents($outside, 'do not touch'));
        $target = $dir.'/reviewed-runbooks-'.hash('sha256', $envelope).'.json';
        if (! @symlink($outside, $target)) {
            @unlink($outside);
            @rmdir($dir);
            $this->markTestSkipped('Host does not support symlinks.');
        }
        try {
            $this->publisher()->storePrivate($envelope, $dir);
            $this->fail('A signed publisher must never overwrite a symlink target.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('already exists', $error->getMessage());
        } finally {
            $this->assertSame('do not touch', file_get_contents($outside));
            $this->assertTrue(is_link($target));
            $this->assertSame([], glob($dir.'/.signed-runbook-*') ?: []);
            @unlink($target);
            @unlink($outside);
            @unlink($dir.'/.publisher.lock');
            @rmdir($dir);
        }
    }

    public function test_no_network_publication_or_production_signer_is_synthesized(): void
    {
        $id = $this->guide();
        $raw = $this->publisher()->prepare([$id], $this->secret);
        $this->assertStringNotContainsString('latest.json', $raw);
        $this->assertStringNotContainsString('http://', $raw);
        $this->assertStringNotContainsString('https://', $raw);
        $this->assertFalse(is_file(public_path('reviewed-runbooks.json')));
    }

    public function test_cli_uses_only_process_secret_and_produces_recoverable_private_artifact(): void
    {
        $id = $this->guide();
        $directory = storage_path('app/private/runbook-publisher');
        $previous = getenv('WALIDSITE_RUNBOOK_SIGNING_KEY_B64');
        $existing = glob($directory.DIRECTORY_SEPARATOR.'reviewed-runbooks-*.json') ?: [];
        putenv('WALIDSITE_RUNBOOK_SIGNING_KEY_B64='.$this->secret);

        try {
            $this->artisan('runbooks:prepare-signed', ['--id' => [$id]])
                ->expectsOutput('Private signed runbook candidate prepared; NOT published.')
                ->assertExitCode(0);

            $after = glob($directory.DIRECTORY_SEPARATOR.'reviewed-runbooks-*.json') ?: [];
            $new = array_values(array_diff($after, $existing));
            $this->assertCount(1, $new);
            $this->assertSame(0600, fileperms($new[0]) & 0777);
            $envelope = json_decode((string) file_get_contents($new[0]), true, flags: JSON_THROW_ON_ERROR);
            $payload = base64_decode($envelope['payload_base64'], true);
            $signature = base64_decode($envelope['signature_base64'], true);
            $this->assertTrue(sodium_crypto_sign_verify_detached($signature, $payload, $this->public));
            $data = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($id, $data['items'][0]['id']);
        } finally {
            $after = glob($directory.DIRECTORY_SEPARATOR.'reviewed-runbooks-*.json') ?: [];
            foreach (array_diff($after, $existing) as $candidate) {
                @unlink($candidate);
            }
            if ($previous === false) {
                putenv('WALIDSITE_RUNBOOK_SIGNING_KEY_B64');
            } else {
                putenv('WALIDSITE_RUNBOOK_SIGNING_KEY_B64='.$previous);
            }
        }
    }

    public function test_cli_rejects_missing_key_without_disclosing_reviewed_text(): void
    {
        $id = $this->guide();
        $previous = getenv('WALIDSITE_RUNBOOK_SIGNING_KEY_B64');
        putenv('WALIDSITE_RUNBOOK_SIGNING_KEY_B64');

        try {
            $this->artisan('runbooks:prepare-signed', ['--id' => [$id]])
                ->expectsOutput('Publisher signing unavailable; supply reviewed UUIDs and a private process key.')
                ->assertExitCode(1);
        } finally {
            if ($previous !== false) {
                putenv('WALIDSITE_RUNBOOK_SIGNING_KEY_B64='.$previous);
            }
        }
    }

}
