<?php

declare(strict_types=1);

namespace NexWaypoint\Receipts;

use NexWaypoint\Core\Logger;

/**
 * Durable receipt files under storage/receipts/ (outside the web root).
 */
final class ReceiptFileStore
{
    public function __construct(
        private readonly string $directory,
        private readonly Logger $logger,
    ) {
    }

    public function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0750, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Could not create receipts directory: ' . $this->directory);
        }
    }

    /**
     * @return array{file_path: string, absolute: string, file_size: int}
     */
    public function writeBytes(string $bytes, string $extension = 'pdf'): array
    {
        $this->ensureDirectory();
        $extension = strtolower(preg_replace('/[^a-z0-9]/', '', $extension) ?? 'pdf') ?: 'pdf';
        $token = bin2hex(random_bytes(16));
        $filename = $token . '.' . $extension;
        $absolute = rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . $filename;
        if (@file_put_contents($absolute, $bytes) === false) {
            throw new \RuntimeException('Could not write receipt file');
        }
        return [
            'file_path' => 'receipts/' . $filename,
            'absolute' => $absolute,
            'file_size' => (int) filesize($absolute),
        ];
    }

    public function absolutePath(?string $relativePath): ?string
    {
        if ($relativePath === null || trim($relativePath) === '') {
            return null;
        }
        $relativePath = str_replace(['\\', '..'], ['/', ''], $relativePath);
        $relativePath = ltrim($relativePath, '/');
        if (!str_starts_with($relativePath, 'receipts/')) {
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

    public function deleteRelative(?string $relativePath): void
    {
        $absolute = $this->absolutePath($relativePath);
        if ($absolute !== null && is_file($absolute)) {
            @unlink($absolute);
        }
    }

    /**
     * @param list<array{id: int|string, file_path?: ?string}> $rows
     * @return list<int> deleted receipt ids
     */
    public function deleteFilesForRows(array $rows): array
    {
        $deleted = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $path = isset($row['file_path']) ? (string) $row['file_path'] : null;
            if ($id <= 0) {
                continue;
            }
            $this->deleteRelative($path);
            $deleted[] = $id;
        }
        return $deleted;
    }
}
