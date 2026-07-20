<?php

declare(strict_types=1);

namespace NexWaypoint\Tests;

use NexWaypoint\Core\Env;
use PHPUnit\Framework\TestCase;

final class EnvTest extends TestCase
{
    private string $tmpFile;

    protected function setUp(): void
    {
        parent::setUp();
        Env::resetForTesting();
        $this->tmpFile = tempnam(sys_get_temp_dir(), 'nxwp_env_');
        if ($this->tmpFile === false) {
            self::fail('Could not create temp env file');
        }
        file_put_contents($this->tmpFile, <<<'ENV'
# comment stays
IMAP_HOST=imap.example.com
IMAP_PORT=993
IMAP_PASSWORD=old_secret
FLIGHTAWARE_API_KEY=change_me
DB_PASSWORD=do_not_touch

ENV);
    }

    protected function tearDown(): void
    {
        Env::resetForTesting();
        if (is_file($this->tmpFile)) {
            unlink($this->tmpFile);
        }
        parent::tearDown();
    }

    public function testUpdateRewritesAllowlistedKeysAndPreservesComments(): void
    {
        Env::load($this->tmpFile);
        $changed = Env::update([
            'IMAP_HOST' => 'imap.dreamhost.com',
            'IMAP_PASSWORD' => 'new secret with spaces',
            'IMAP_PORT' => '993',
        ], Env::INTEGRATION_KEYS);

        self::assertSame(['IMAP_HOST', 'IMAP_PASSWORD', 'IMAP_PORT'], $changed);
        self::assertSame('imap.dreamhost.com', Env::get('IMAP_HOST'));
        self::assertSame('new secret with spaces', Env::get('IMAP_PASSWORD'));

        $contents = (string) file_get_contents($this->tmpFile);
        self::assertStringContainsString('# comment stays', $contents);
        self::assertStringContainsString('IMAP_HOST=imap.dreamhost.com', $contents);
        self::assertStringContainsString('IMAP_PASSWORD="new secret with spaces"', $contents);
        self::assertStringContainsString('DB_PASSWORD=do_not_touch', $contents);
    }

    public function testEmptySecretLeavesExistingValue(): void
    {
        Env::load($this->tmpFile);
        $changed = Env::update([
            'IMAP_HOST' => 'imap.dreamhost.com',
            'IMAP_PASSWORD' => '',
        ], Env::INTEGRATION_KEYS);

        self::assertSame(['IMAP_HOST'], $changed);
        self::assertSame('old_secret', Env::get('IMAP_PASSWORD'));
    }

    public function testRejectsNonAllowlistedKeys(): void
    {
        Env::load($this->tmpFile);
        $this->expectException(\InvalidArgumentException::class);
        Env::update(['DB_PASSWORD' => 'hacked'], Env::INTEGRATION_KEYS);
    }
}
