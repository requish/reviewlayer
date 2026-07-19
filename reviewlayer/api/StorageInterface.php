<?php

declare(strict_types=1);

namespace ReviewLayer;

interface StorageInterface
{
    public function mode(): string;

    /** @return array<int, array<string, mixed>> */
    public function listPins(string $projectKey, string $pageKey): array;

    /** @return array<int, array<string, mixed>> */
    public function listProjectPins(string $projectKey): array;

    /** @return array<string, mixed>|null */
    public function getPin(string $id, string $projectKey): ?array;

    /** @return array<string, mixed>|null */
    public function getMessage(string $id, string $projectKey): ?array;

    /** @param array<string, mixed> $pin @param array<string, mixed> $message @return array<string, mixed> */
    public function createPin(array $pin, array $message): array;

    /** @param array<string, mixed> $message @return array<string, mixed> */
    public function addMessage(array $message): array;

    public function updatePinStatus(string $id, string $projectKey, string $status, string $updatedAt): bool;

    public function softDeletePin(string $id, string $projectKey, string $deletedAt): bool;

    public function softDeleteMessage(string $id, string $projectKey, string $deletedAt): bool;

    /** @return array<string, mixed> */
    public function exportAll(): array;

    /** @param array<string, mixed> $backup */
    public function restoreAll(array $backup): void;

    /** @return array{pins:int,messages:int} */
    public function clear(string $scope, string $mode, string $projectKey, string $pageKey, string $timestamp): array;

    /** @param array<string, mixed> $event */
    public function logAdmin(array $event): void;
}
