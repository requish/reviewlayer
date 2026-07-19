<?php

declare(strict_types=1);

namespace ReviewLayer;

use Throwable;

final class ClearService
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly BackupService $backupService,
        private readonly array $config
    ) {
    }

    /** @return array{deleted_pins:int,deleted_messages:int,backup_file:?string} */
    public function execute(
        string $scope,
        string $mode,
        string $projectKey,
        string $pageKey,
        bool $continueWithoutBackup,
        string $actorHash
    ): array {
        $backupFile = null;
        if ($mode === 'purge' && (bool) $this->config['CREATE_BACKUP_BEFORE_PURGE']) {
            try {
                $backupFile = $this->backupService->create($this->storage);
            } catch (Throwable $error) {
                if (!$continueWithoutBackup) {
                    throw new BackupException('Backup creation failed.', 0, $error);
                }
            }
        }

        $timestamp = gmdate('c');
        $counts = $this->storage->clear($scope, $mode, $projectKey, $pageKey, $timestamp);
        $result = [
            'deleted_pins' => $counts['pins'],
            'deleted_messages' => $counts['messages'],
            'backup_file' => $backupFile,
        ];
        $this->storage->logAdmin([
            'id' => uuidV4(),
            'action' => 'clear',
            'scope' => $scope,
            'mode' => $mode,
            'project_key' => $projectKey,
            'page_key' => $pageKey,
            'result' => $result,
            'actor_hash' => $actorHash,
            'created_at' => $timestamp,
        ]);
        return $result;
    }
}

final class BackupException extends \RuntimeException
{
}
