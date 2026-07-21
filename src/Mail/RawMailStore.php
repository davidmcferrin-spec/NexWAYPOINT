<?php

declare(strict_types=1);

namespace NexWaypoint\Mail;

use NexWaypoint\Core\Logger;

/**
 * Short-lived raw .eml storage under storage/mail_raw for system-admin debug.
 * Files expire after MAIL_RAW_RETENTION_DAYS; never written into the DB.
 */
final class RawMailStore
{
    public function __construct(
        private readonly string $directory,
        private readonly int $retentionDays,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @return array{raw_path: string, raw_expires_at: string}|null
     */
    public function write(int $parseLogId, EmailMessage $message): ?array
    {
        if ($parseLogId <= 0) {
            return null;
        }
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0750, true) && !is_dir($this->directory)) {
            $this->logger->warning('Could not create mail_raw directory', ['dir' => $this->directory]);
            return null;
        }

        $filename = $parseLogId . '.eml';
        $absolute = rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . $filename;
        $eml = $this->buildEml($message);
        if (@file_put_contents($absolute, $eml) === false) {
            $this->logger->warning('Could not write raw mail file', ['path' => $absolute]);
            return null;
        }

        $days = max(1, $this->retentionDays);
        $expires = (new \DateTimeImmutable('now'))->modify("+{$days} days");

        return [
            'raw_path' => 'mail_raw/' . $filename,
            'raw_expires_at' => $expires->format('Y-m-d H:i:s'),
        ];
    }

    public function absolutePath(?string $relativePath): ?string
    {
        if ($relativePath === null || trim($relativePath) === '') {
            return null;
        }
        $relativePath = str_replace(['\\', '..'], ['/', ''], $relativePath);
        $relativePath = ltrim($relativePath, '/');
        if (!str_starts_with($relativePath, 'mail_raw/')) {
            return null;
        }
        $basename = basename($relativePath);
        if ($basename === '' || $basename === '.' || $basename === '..') {
            return null;
        }
        $absolute = rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . $basename;
        if (!is_file($absolute)) {
            return null;
        }
        $real = realpath($absolute);
        $dirReal = realpath($this->directory);
        if ($real === false || $dirReal === false || !str_starts_with($real, $dirReal)) {
            return null;
        }
        return $real;
    }

    public function isExpired(?string $expiresAt, ?\DateTimeImmutable $asOf = null): bool
    {
        if ($expiresAt === null || trim($expiresAt) === '') {
            return true;
        }
        $asOf ??= new \DateTimeImmutable('now');
        try {
            return new \DateTimeImmutable($expiresAt) <= $asOf;
        } catch (\Exception) {
            return true;
        }
    }

    /**
     * Delete expired files and return list of parse_log ids whose raw_path should clear.
     *
     * @param list<array{id: int|string, raw_path?: ?string, raw_expires_at?: ?string}> $rows
     * @return list<int>
     */
    public function purgeExpiredRows(array $rows, ?\DateTimeImmutable $asOf = null): array
    {
        $asOf ??= new \DateTimeImmutable('now');
        $cleared = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $path = isset($row['raw_path']) ? (string) $row['raw_path'] : null;
            $expires = isset($row['raw_expires_at']) ? (string) $row['raw_expires_at'] : null;
            if ($id <= 0 || $path === null || $path === '') {
                continue;
            }
            if (!$this->isExpired($expires, $asOf)) {
                continue;
            }
            $absolute = $this->absolutePath($path);
            if ($absolute !== null && is_file($absolute)) {
                @unlink($absolute);
            }
            $cleared[] = $id;
        }
        return $cleared;
    }

    private function buildEml(EmailMessage $message): string
    {
        $headers = [
            'From: ' . $this->headerSafe($message->fromAddress),
            'Subject: ' . $this->headerSafe($message->subject),
            'Date: ' . $message->receivedAt->format(\DateTimeInterface::RFC2822),
            'X-NexWAYPOINT-Mail-UID: ' . $this->headerSafe($message->uid),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];
        $body = trim($message->bodyPlain) !== ''
            ? $message->bodyPlain
            : trim(html_entity_decode(strip_tags($message->bodyHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n";
    }

    private function headerSafe(string $value): string
    {
        return str_replace(["\r", "\n"], '', $value);
    }
}
