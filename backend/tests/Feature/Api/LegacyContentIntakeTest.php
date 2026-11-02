<?php

namespace Tests\Feature\Api;

use App\Domain\LegacyImport\LegacyContentIntake;
use InvalidArgumentException;
use Tests\TestCase;

class LegacyContentIntakeTest extends TestCase
{
    public function test_dry_run_preserves_arabic_and_never_publishes(): void
    {
        $payload = json_encode(['version' => 1, 'tables' => [
            'articles' => [['slug' => 'arabic-story', 'title_en' => 'Story', 'title_ar' => 'قصة']],
        ]], JSON_UNESCAPED_UNICODE);
        $result = (new LegacyContentIntake)->inspect($payload);
        $this->assertTrue($result['dry_run']);
        $this->assertSame('قصة', $result['accepted'][0]['data']['title_ar']);
        $this->assertSame('draft', $result['accepted'][0]['status']);
        $this->assertCount(0, $result['rejected']);
        $this->assertSame($result, (new LegacyContentIntake)->inspect($payload));
    }

    public function test_unknown_tables_and_hostile_rows_are_rejected(): void
    {
        $payload = json_encode(['version' => 1, 'tables' => [
            'users' => [['email' => 'private@example.com']],
            'articles' => [
                ['slug' => '../escape', 'title_en' => 'bad'],
                ['slug' => 'xss', 'title_en' => '<script>alert(1)</script>'],
                ['slug' => 'secret', 'title_en' => 'api_key=leaked'],
                ['slug' => 'url', 'title_en' => 'https://evil.example'],
                ['slug' => 'columns', 'password' => 'hidden'],
            ],
        ]]);
        $result = (new LegacyContentIntake)->inspect($payload);
        $this->assertCount(0, $result['accepted']);
        $this->assertCount(6, $result['rejected']);
    }

    public function test_sql_dump_and_oversized_payload_are_not_accepted(): void
    {
        foreach (['INSERT INTO users VALUES (1);', str_repeat('x', 1048577)] as $payload) {
            try {
                (new LegacyContentIntake)->inspect($payload);
                $this->fail('Untrusted input was accepted');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }
}
