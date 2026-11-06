<?php

namespace Tests\Unit;

use App\Domain\Deployment\CpanelConsoleWriterLease;
use App\Http\Middleware\CpanelHttpWriterBarrier;
use DomainException;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CpanelHttpWriterBarrierTest extends TestCase
{
    private string $home;

    protected function setUp(): void
    {
        parent::setUp();
        $this->home = sys_get_temp_dir().'/walidsite-http-drain-'.bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($this->home, 0700));
        $this->assertTrue(mkdir($this->home.'/application', 0700));
        $this->assertTrue(mkdir($this->home.'/public_html', 0700));
    }

    protected function tearDown(): void
    {
        if (isset($this->home) && is_dir($this->home)) {
            foreach (scandir($this->home) ?: [] as $name) {
                if ($name !== '.' && $name !== '..') {
                    $path = $this->home.'/'.$name;
                    if (is_dir($path) && ! is_link($path)) {
                        @rmdir($path);
                    } else {
                        @unlink($path);
                    }
                }
            }
            @rmdir($this->home);
        }
        parent::tearDown();
    }

    private function middleware(): CpanelHttpWriterBarrier
    {
        return new CpanelHttpWriterBarrier(
            new CpanelConsoleWriterLease(),
            $this->home.'/application',
        );
    }

    public function test_dynamic_http_request_excludes_owner_update_until_response_is_ready(): void
    {
        $hit = false;
        $response = $this->middleware()->handle(
            Request::create('/contact', 'POST'),
            function () use (&$hit): Response {
                $hit = true;
                $exclusive = new CpanelConsoleWriterLease();
                try {
                    $exclusive->beginExclusiveForUpdate($this->home);
                    $this->fail('Exclusive update overtook an in-flight HTTP request.');
                } catch (DomainException) {
                    // The shared writer lease is still held by middleware.
                }
                return new Response('ok');
            },
        );
        $this->assertTrue($hit);
        $this->assertSame(200, $response->getStatusCode());
        $exclusive = new CpanelConsoleWriterLease();
        $exclusive->beginExclusiveForUpdate($this->home);
        $exclusive->assertExclusiveHeld();
        $exclusive->end();
    }

    public function test_exception_does_not_strand_http_writer_lock(): void
    {
        $middleware = $this->middleware();
        try {
            $middleware->handle(
                Request::create('/admin/operations', 'POST'),
                static function (): Response {
                    throw new RuntimeException('Simulated controller fault.');
                },
            );
            $this->fail('Simulated failure was swallowed.');
        } catch (RuntimeException) {
            $exclusive = new CpanelConsoleWriterLease();
            $exclusive->beginExclusiveForUpdate($this->home);
            $exclusive->end();
        }
    }

    public function test_streamed_http_response_holds_lock_until_stream_callback_finishes(): void
    {
        $middleware = $this->middleware();
        $streamed = false;
        $response = $middleware->handle(
            Request::create('/admin/audit/export', 'GET'),
            function () use (&$streamed): Response {
                return new StreamedResponse(function () use (&$streamed): void {
                    $streamed = true;
                    $exclusive = new CpanelConsoleWriterLease();
                    try {
                        $exclusive->beginExclusiveForUpdate($this->home);
                        $this->fail('An owner update overtook a still-running streamDownload callback.');
                    } catch (DomainException) {
                        echo 'safe';
                    }
                });
            },
        );

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $exclusive = new CpanelConsoleWriterLease();
        try {
            $exclusive->beginExclusiveForUpdate($this->home);
            $this->fail('Streamed response released the lock before streaming started.');
        } catch (DomainException) {
            // Lock intentionally retained until streamed PHP completes.
        }

        ob_start();
        try {
            $response->sendContent();
            $this->assertSame('safe', ob_get_contents());
        } finally {
            ob_end_clean();
        }
        $this->assertTrue($streamed);
        $exclusive->beginExclusiveForUpdate($this->home);
        $exclusive->end();
    }

    public function test_binary_file_response_holds_writer_lock_until_http_termination(): void
    {
        $file = $this->home.'/download.bin';
        self::assertSame(13, file_put_contents($file, 'private bytes'));
        $request = Request::create('/admin/reports/download', 'GET');
        $middleware = $this->middleware();
        $response = $middleware->handle(
            $request,
            static fn (): Response => new BinaryFileResponse($file),
        );

        self::assertInstanceOf(BinaryFileResponse::class, $response);
        $exclusive = new CpanelConsoleWriterLease();
        try {
            $exclusive->beginExclusiveForUpdate($this->home);
            self::fail('Owner update overtook pending binary-file response.');
        } catch (DomainException) {
            // File has not yet been sent: the request still owns its lease.
        }

        ob_start();
        try {
            $response->sendContent();
            self::assertSame('private bytes', ob_get_contents());
        } finally {
            ob_end_clean();
        }

        // Kernel::terminate() runs only AFTER sendContent(), so the lock
        // must still exclude a cutover until that lifecycle callback.
        try {
            $exclusive->beginExclusiveForUpdate($this->home);
            self::fail('Binary response released its lease before termination.');
        } catch (DomainException) {
            // Still held pending HTTP kernel termination.
        }
        $middleware->terminate($request, $response);
        $middleware->terminate($request, $response); // Idempotent.
        $exclusive->beginExclusiveForUpdate($this->home);
        $exclusive->assertExclusiveHeld();
        $exclusive->end();
    }

    public function test_expired_private_maintenance_marker_returns_503_before_request_handler(): void
    {
        $marker = $this->home.'/.walidsite-update-maintenance.json';
        file_put_contents($marker, '{"schema":1,"expires_at":0}');
        $hit = false;
        $response = $this->middleware()->handle(
            Request::create('/contact', 'POST'),
            static function () use (&$hit): Response {
                $hit = true;
                return new Response('should never run');
            },
        );
        $this->assertFalse($hit);
        $this->assertSame(503, $response->getStatusCode());
        $this->assertContains('no-store', array_map('trim', explode(',', (string) $response->headers->get('Cache-Control'))));
        $this->assertSame('30', $response->headers->get('Retry-After'));
        $this->assertFileExists($marker);
    }

    public function test_unsafe_preexisting_shared_lock_fails_closed_without_repair(): void
    {
        $lease = new CpanelConsoleWriterLease();
        $lease->beginForConsole($this->home.'/application');
        $lease->end();
        $path = $this->home.'/.walidsite-writer-drain.lock';
        chmod($path, 0666);
        clearstatcache(true, $path);

        $hit = false;
        $response = $this->middleware()->handle(
            Request::create('/admin/login', 'POST'),
            static function () use (&$hit): Response {
                $hit = true;
                return new Response('bad');
            },
        );
        $this->assertFalse($hit);
        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame(0666, fileperms($path) & 0777);
    }
}
