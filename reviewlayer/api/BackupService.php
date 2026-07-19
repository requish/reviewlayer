<?php

declare(strict_types=1);

namespace ReviewLayer;

use RuntimeException;

final class BackupService
{
    public function __construct(
        private readonly string $directory,
        private readonly int $maxFiles = 0
    ) {
    }

    public function create(StorageInterface $storage): string
    {
        if (!is_dir($this->directory) || !is_writable($this->directory)) {
            throw new RuntimeException('The backup directory is not writable.');
        }
        $timestamp = gmdate('Y-m-d\THis\Z');
        $filename = 'reviewlayer-backup-' . $timestamp . '-' . bin2hex(random_bytes(3)) . '.json';
        $target = $this->directory . '/' . $filename;
        $temporary = $this->directory . '/.' . $filename . '.tmp';
        $payload = $storage->exportAll();
        $payload['reviewlayer_version'] = REVIEWLAYER_VERSION;
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents($temporary, $encoded, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write the backup.');
        }
        if (!rename($temporary, $target)) {
            if (is_file($temporary)) {
                unlink($temporary);
            }
            throw new RuntimeException('Unable to finalize the backup.');
        }
        $this->pruneOldBackups($target);
        return $filename;
    }

    private function pruneOldBackups(string $currentFile): void
    {
        if ($this->maxFiles <= 0) {
            return;
        }
        $files = glob($this->directory . '/reviewlayer-backup-*.json') ?: [];
        $files = array_values(array_filter($files, static fn (string $file): bool => $file !== $currentFile));
        rsort($files, SORT_STRING);
        foreach (array_slice($files, max(0, $this->maxFiles - 1)) as $file) {
            if (is_file($file) && !unlink($file)) {
                throw new RuntimeException('Unable to remove an old backup.');
            }
        }
    }
}
