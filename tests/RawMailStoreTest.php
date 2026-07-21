<?php

declare(strict_types=1);

namespace NexWaypoint\Tests;

use NexWaypoint\Mail\EmailMessage;
use NexWaypoint\Mail\RawMailStore;
use PHPUnit\Framework\TestCase;

final class RawMailStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/nx_mail_raw_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            foreach (glob($this->dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->dir);
        }
        parent::tearDown();
    }

    public function testWriteAndAbsolutePath(): void
    {
        $logger = new \NexWaypoint\Core\Logger($this->dir . '/test.log', 'error');
        $store = new RawMailStore($this->dir, 7, $logger);
        $msg = new EmailMessage(
            uid: 'uid-1',
            fromAddress: 'dave@example.com',
            subject: 'Fwd: Trip',
            receivedAt: new \DateTimeImmutable('2026-07-20 12:00:00'),
            bodyPlain: "Hello\nConfirmation code: ABC",
            bodyHtml: '',
        );

        $meta = $store->write(42, $msg);
        self::assertNotNull($meta);
        self::assertSame('mail_raw/42.eml', $meta['raw_path']);
        self::assertNotSame('', $meta['raw_expires_at']);

        $absolute = $store->absolutePath($meta['raw_path']);
        self::assertNotNull($absolute);
        self::assertFileExists($absolute);
        $contents = (string) file_get_contents($absolute);
        self::assertStringContainsString('From: dave@example.com', $contents);
        self::assertStringContainsString('Confirmation code: ABC', $contents);
    }

    public function testPurgeExpiredRows(): void
    {
        $logger = new \NexWaypoint\Core\Logger($this->dir . '/test.log', 'error');
        $store = new RawMailStore($this->dir, 7, $logger);
        $msg = new EmailMessage(
            uid: 'uid-2',
            fromAddress: 'dave@example.com',
            subject: 'Test',
            receivedAt: new \DateTimeImmutable('now'),
            bodyPlain: 'body',
            bodyHtml: '',
        );
        $meta = $store->write(7, $msg);
        self::assertNotNull($meta);
        self::assertFileExists($this->dir . '/7.eml');

        $cleared = $store->purgeExpiredRows([
            [
                'id' => 7,
                'raw_path' => $meta['raw_path'],
                'raw_expires_at' => (new \DateTimeImmutable('now'))->modify('-1 hour')->format('Y-m-d H:i:s'),
            ],
        ]);

        self::assertSame([7], $cleared);
        self::assertFileDoesNotExist($this->dir . '/7.eml');
    }

    public function testRejectsPathTraversal(): void
    {
        $logger = new \NexWaypoint\Core\Logger($this->dir . '/test.log', 'error');
        $store = new RawMailStore($this->dir, 7, $logger);
        self::assertNull($store->absolutePath('../secrets.txt'));
        self::assertNull($store->absolutePath('mail_raw/../../etc/passwd'));
    }
}
