<?php

declare(strict_types=1);

namespace ReviewLayer;

use RuntimeException;
use Throwable;

final class JsonStorage implements StorageInterface
{
    private string $lockPath;

    public function __construct(private readonly string $path, private readonly string $adminLogPath)
    {
        $this->lockPath = $path . '.lock';
    }

    public function mode(): string
    {
        return 'json';
    }

    public function listPins(string $projectKey, string $pageKey): array
    {
        $pageKeys = Validation::compatiblePageKeys($pageKey);
        return $this->read(function (array $data) use ($projectKey, $pageKeys): array {
            $messages = $data['messages'];
            $pins = array_values(array_filter($data['pins'], static fn (array $pin): bool =>
                $pin['project_key'] === $projectKey && in_array($pin['page_key'], $pageKeys, true) && $pin['deleted_at'] === null
            ));
            foreach ($pins as &$pin) {
                $pin['author_color_index'] = $this->projectUserColor($data, $projectKey, (string) $pin['author_id']);
                $first = array_values(array_filter($messages, static fn (array $message): bool =>
                    $message['pin_id'] === $pin['id'] && $message['deleted_at'] === null
                ));
                usort($first, static fn (array $a, array $b): int => [$a['created_at'], $a['id']] <=> [$b['created_at'], $b['id']]);
                $pin['first_message'] = $first[0]['message'] ?? '';
            }
            unset($pin);
            usort($pins, static fn (array $a, array $b): int => $a['pin_number'] <=> $b['pin_number']);
            return $pins;
        });
    }

    public function listProjectPins(string $projectKey): array
    {
        return $this->read(function (array $data) use ($projectKey): array {
            $messages = $data['messages'];
            $pins = array_values(array_filter($data['pins'], static fn (array $pin): bool =>
                $pin['project_key'] === $projectKey && $pin['deleted_at'] === null
            ));
            foreach ($pins as &$pin) {
                $pin['author_color_index'] = $this->projectUserColor($data, $projectKey, (string) $pin['author_id']);
                $first = array_values(array_filter($messages, static fn (array $message): bool =>
                    $message['pin_id'] === $pin['id'] && $message['deleted_at'] === null
                ));
                usort($first, static fn (array $a, array $b): int => [$a['created_at'], $a['id']] <=> [$b['created_at'], $b['id']]);
                $pin['first_message'] = $first[0]['message'] ?? '';
            }
            unset($pin);
            $pins = array_map(static fn (array $pin): array => [
                'id' => $pin['id'],
                'page_key' => $pin['page_key'],
                'page_url' => $pin['page_url'],
                'pin_number' => (int) $pin['pin_number'],
                'status' => $pin['status'],
                'author_name' => $pin['author_name'],
                'author_color_index' => (int) $pin['author_color_index'],
                'created_at' => $pin['created_at'],
                'updated_at' => $pin['updated_at'],
                'first_message' => $pin['first_message'],
                'viewport' => ['device_type' => (string) ($pin['viewport']['device_type'] ?? '')],
            ], $pins);
            usort($pins, static fn (array $a, array $b): int => $b['pin_number'] <=> $a['pin_number']);
            return $pins;
        });
    }

    public function listProjectUsers(string $projectKey): array
    {
        return $this->read(function (array $data) use ($projectKey): array {
            $users = array_values(array_filter($data['users'], static fn (array $user): bool =>
                $user['project_key'] === $projectKey
            ));
            usort($users, static fn (array $a, array $b): int =>
                (int) $a['sequence_number'] <=> (int) $b['sequence_number']
            );
            return array_map(static fn (array $user): array => [
                'author_name' => (string) $user['author_name'],
                'color_index' => (int) $user['color_index'],
                'created_at' => (string) $user['created_at'],
                'updated_at' => (string) $user['updated_at'],
            ], $users);
        });
    }

    public function getPin(string $id, string $projectKey): ?array
    {
        return $this->read(function (array $data) use ($id, $projectKey): ?array {
            foreach ($data['pins'] as $pin) {
                if ($pin['id'] !== $id || $pin['project_key'] !== $projectKey || $pin['deleted_at'] !== null) {
                    continue;
                }
                $pin['author_color_index'] = $this->projectUserColor($data, $projectKey, (string) $pin['author_id']);
                $pin['messages'] = array_values(array_filter($data['messages'], static fn (array $message): bool =>
                    $message['pin_id'] === $id && $message['deleted_at'] === null
                ));
                foreach ($pin['messages'] as &$message) {
                    $message['author_color_index'] = $this->projectUserColor($data, $projectKey, (string) $message['author_id']);
                }
                unset($message);
                usort($pin['messages'], static fn (array $a, array $b): int => [$a['created_at'], $a['id']] <=> [$b['created_at'], $b['id']]);
                return $pin;
            }
            return null;
        });
    }

    public function getMessage(string $id, string $projectKey): ?array
    {
        return $this->read(function (array $data) use ($id, $projectKey): ?array {
            foreach ($data['messages'] as $message) {
                if ($message['id'] !== $id || $message['deleted_at'] !== null) {
                    continue;
                }
                foreach ($data['pins'] as $pin) {
                    if ($pin['id'] === $message['pin_id'] && $pin['project_key'] === $projectKey && $pin['deleted_at'] === null) {
                        return $message;
                    }
                }
            }
            return null;
        });
    }

    public function createPin(array $pin, array $message): array
    {
        return $this->mutate(function (array &$data) use ($pin, $message): array {
            $user = $this->ensureProjectUser(
                $data,
                (string) $pin['project_key'],
                (string) $pin['author_id'],
                (string) $pin['author_name'],
                (string) $pin['created_at']
            );
            $next = (int) ($data['counters'][$pin['project_key']] ?? 1);
            $pin['pin_number'] = $next;
            $pin['deleted_at'] = null;
            $message['deleted_at'] = null;
            $data['counters'][$pin['project_key']] = $next + 1;
            $data['pins'][] = $pin;
            $data['messages'][] = $message;
            $pin['first_message'] = $message['message'];
            $pin['author_color_index'] = $user['color_index'];
            return $pin;
        });
    }

    public function addMessage(array $message): array
    {
        return $this->mutate(function (array &$data) use ($message): array {
            $found = false;
            foreach ($data['pins'] as &$pin) {
                if ($pin['id'] === $message['pin_id'] && $pin['project_key'] === $message['project_key'] && $pin['deleted_at'] === null) {
                    $pin['updated_at'] = $message['created_at'];
                    $found = true;
                    break;
                }
            }
            unset($pin);
            if (!$found) {
                throw new StorageNotFoundException('Pin not found.');
            }
            $user = $this->ensureProjectUser(
                $data,
                (string) $message['project_key'],
                (string) $message['author_id'],
                (string) $message['author_name'],
                (string) $message['created_at']
            );
            unset($message['project_key']);
            $message['deleted_at'] = null;
            $message['author_color_index'] = $user['color_index'];
            $data['messages'][] = $message;
            return $message;
        });
    }

    public function updatePinStatus(string $id, string $projectKey, string $status, string $updatedAt): bool
    {
        return $this->mutate(function (array &$data) use ($id, $projectKey, $status, $updatedAt): bool {
            foreach ($data['pins'] as &$pin) {
                if ($pin['id'] === $id && $pin['project_key'] === $projectKey && $pin['deleted_at'] === null) {
                    $pin['status'] = $status;
                    $pin['updated_at'] = $updatedAt;
                    return true;
                }
            }
            unset($pin);
            return false;
        });
    }

    public function softDeletePin(string $id, string $projectKey, string $deletedAt): bool
    {
        return $this->mutate(function (array &$data) use ($id, $projectKey, $deletedAt): bool {
            $found = false;
            foreach ($data['pins'] as &$pin) {
                if ($pin['id'] === $id && $pin['project_key'] === $projectKey && $pin['deleted_at'] === null) {
                    $pin['deleted_at'] = $deletedAt;
                    $pin['updated_at'] = $deletedAt;
                    $found = true;
                    break;
                }
            }
            unset($pin);
            if (!$found) return false;
            foreach ($data['messages'] as &$message) {
                if ($message['pin_id'] === $id && $message['deleted_at'] === null) {
                    $message['deleted_at'] = $deletedAt;
                    $message['updated_at'] = $deletedAt;
                }
            }
            unset($message);
            return true;
        });
    }

    public function softDeleteMessage(string $id, string $projectKey, string $deletedAt): bool
    {
        return $this->mutate(function (array &$data) use ($id, $projectKey, $deletedAt): bool {
            $projectPins = [];
            foreach ($data['pins'] as $pin) {
                if ($pin['project_key'] === $projectKey && $pin['deleted_at'] === null) {
                    $projectPins[$pin['id']] = true;
                }
            }
            foreach ($data['messages'] as &$message) {
                if ($message['id'] === $id && isset($projectPins[$message['pin_id']]) && $message['deleted_at'] === null) {
                    $message['deleted_at'] = $deletedAt;
                    $message['updated_at'] = $deletedAt;
                    return true;
                }
            }
            unset($message);
            return false;
        });
    }

    public function exportAll(): array
    {
        return $this->read(static function (array $data): array {
            $projects = [];
            foreach ($data['counters'] as $projectKey => $nextNumber) {
                $projects[] = ['project_key' => $projectKey, 'next_number' => $nextNumber];
            }
            return [
                'format_version' => 2,
                'exported_at' => gmdate('c'),
                'projects' => $projects,
                'users' => $data['users'],
                'pins' => $data['pins'],
                'messages' => $data['messages'],
            ];
        });
    }

    public function restoreAll(array $backup): void
    {
        if (!isset($backup['projects'], $backup['pins'], $backup['messages']) || !is_array($backup['projects']) || !is_array($backup['pins']) || !is_array($backup['messages'])) {
            throw new \InvalidArgumentException('Backup structure is invalid.');
        }
        $this->mutate(function (array &$data) use ($backup): void {
            $counters = [];
            foreach ($backup['projects'] as $project) {
                if (!is_array($project)) throw new \InvalidArgumentException('Backup project is invalid.');
                $projectKey = Validation::projectKey($project['project_key'] ?? null);
                $counters[$projectKey] = max(1, (int) ($project['next_number'] ?? 1));
            }
            foreach ($backup['pins'] as $pin) {
                if (!is_array($pin)) throw new \InvalidArgumentException('Backup pin is invalid.');
                Validation::uuid($pin['id'] ?? null);
                Validation::projectKey($pin['project_key'] ?? null);
            }
            foreach ($backup['messages'] as $message) {
                if (!is_array($message)) throw new \InvalidArgumentException('Backup message is invalid.');
                Validation::uuid($message['id'] ?? null);
                Validation::uuid($message['pin_id'] ?? null, 'pin_id');
            }
            $users = [];
            foreach (($backup['users'] ?? []) as $user) {
                if (!is_array($user)) throw new \InvalidArgumentException('Backup user is invalid.');
                $projectKey = Validation::projectKey($user['project_key'] ?? null);
                $authorId = Validation::uuid($user['author_id'] ?? null, 'author_id');
                $colorIndex = (int) ($user['color_index'] ?? 0);
                $sequenceNumber = (int) ($user['sequence_number'] ?? 0);
                if ($colorIndex < 1 || $colorIndex > 10 || $sequenceNumber < 1) {
                    throw new \InvalidArgumentException('Backup user color assignment is invalid.');
                }
                $users[] = [
                    'project_key' => $projectKey,
                    'author_id' => $authorId,
                    'author_name' => Validation::string($user['author_name'] ?? null, 'author_name', 1, 80),
                    'color_index' => $colorIndex,
                    'sequence_number' => $sequenceNumber,
                    'created_at' => Validation::string($user['created_at'] ?? null, 'created_at', 1, 64),
                    'updated_at' => Validation::string($user['updated_at'] ?? null, 'updated_at', 1, 64),
                ];
            }
            $data = [
                'format_version' => 2,
                'counters' => $counters,
                'users' => $users,
                'pins' => array_values($backup['pins']),
                'messages' => array_values($backup['messages']),
            ];
            $this->backfillProjectUsers($data);
        });
    }

    public function clear(string $scope, string $mode, string $projectKey, string $pageKey, string $timestamp): array
    {
        $pageKeys = Validation::compatiblePageKeys($pageKey);
        return $this->mutate(function (array &$data) use ($scope, $mode, $projectKey, $pageKeys, $timestamp): array {
            $matchingIds = [];
            foreach ($data['pins'] as $pin) {
                $matches = match ($scope) {
                    'current_page' => $pin['project_key'] === $projectKey && in_array($pin['page_key'], $pageKeys, true),
                    'current_project' => $pin['project_key'] === $projectKey,
                    'resolved_in_project' => $pin['project_key'] === $projectKey && $pin['status'] === 'resolved',
                    'all_projects' => true,
                    default => throw new \InvalidArgumentException('Invalid clear scope.'),
                };
                if ($matches && ($mode === 'purge' || $pin['deleted_at'] === null)) {
                    $matchingIds[$pin['id']] = true;
                }
            }

            $messageCount = 0;
            foreach ($data['messages'] as $message) {
                if (isset($matchingIds[$message['pin_id']]) && ($mode === 'purge' || $message['deleted_at'] === null)) {
                    $messageCount++;
                }
            }

            if ($mode === 'soft') {
                foreach ($data['pins'] as &$pin) {
                    if (isset($matchingIds[$pin['id']]) && $pin['deleted_at'] === null) {
                        $pin['deleted_at'] = $timestamp;
                        $pin['updated_at'] = $timestamp;
                    }
                }
                unset($pin);
                foreach ($data['messages'] as &$message) {
                    if (isset($matchingIds[$message['pin_id']]) && $message['deleted_at'] === null) {
                        $message['deleted_at'] = $timestamp;
                        $message['updated_at'] = $timestamp;
                    }
                }
                unset($message);
            } else {
                $data['messages'] = array_values(array_filter($data['messages'], static fn (array $message): bool => !isset($matchingIds[$message['pin_id']])));
                $data['pins'] = array_values(array_filter($data['pins'], static fn (array $pin): bool => !isset($matchingIds[$pin['id']])));
                if ($scope === 'all_projects') {
                    $data['counters'] = [];
                    $data['users'] = [];
                } elseif ($scope === 'current_project') {
                    unset($data['counters'][$projectKey]);
                    $data['users'] = array_values(array_filter($data['users'], static fn (array $user): bool =>
                        $user['project_key'] !== $projectKey
                    ));
                }
            }
            return ['pins' => count($matchingIds), 'messages' => $messageCount];
        });
    }

    public function logAdmin(array $event): void
    {
        $handle = fopen($this->adminLogPath, 'ab');
        if ($handle === false) {
            throw new RuntimeException('Unable to open the admin log.');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Unable to lock the admin log.');
            }
            $line = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
            if (fwrite($handle, $line) !== strlen($line)) {
                throw new RuntimeException('Unable to write the admin log.');
            }
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    /** @template T @param callable(array<string,mixed>):T $callback @return T */
    private function read(callable $callback): mixed
    {
        return $this->withLock(LOCK_SH, fn (): mixed => $callback($this->readData()));
    }

    /** @template T @param callable(array<string,mixed>&):T $callback @return T */
    private function mutate(callable $callback): mixed
    {
        return $this->withLock(LOCK_EX, function () use ($callback): mixed {
            $data = $this->readData();
            $result = $callback($data);
            $this->writeData($data);
            return $result;
        });
    }

    private function withLock(int $operation, callable $callback): mixed
    {
        $handle = fopen($this->lockPath, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Unable to open the JSON storage lock.');
        }
        try {
            if (!flock($handle, $operation)) {
                throw new RuntimeException('Unable to lock JSON storage.');
            }
            $result = $callback();
            flock($handle, LOCK_UN);
            return $result;
        } finally {
            fclose($handle);
        }
    }

    /** @return array<string, mixed> */
    private function readData(): array
    {
        if (!is_file($this->path)) {
            return ['format_version' => 2, 'counters' => [], 'users' => [], 'pins' => [], 'messages' => []];
        }
        $raw = file_get_contents($this->path);
        if ($raw === false) {
            throw new RuntimeException('Unable to read JSON storage.');
        }
        try {
            $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable $error) {
            throw new RuntimeException('JSON storage is corrupted.', 0, $error);
        }
        if (!is_array($data) || !isset($data['counters'], $data['pins'], $data['messages']) || !is_array($data['counters']) || !is_array($data['pins']) || !is_array($data['messages'])) {
            throw new RuntimeException('JSON storage has an invalid structure.');
        }
        $data['format_version'] = 2;
        $data['users'] = isset($data['users']) && is_array($data['users']) ? array_values($data['users']) : [];
        if ($data['users'] === []) {
            $this->backfillProjectUsers($data);
        }
        return $data;
    }

    /** @param array<string, mixed> $data @return array{color_index:int,sequence_number:int} */
    private function ensureProjectUser(
        array &$data,
        string $projectKey,
        string $authorId,
        string $authorName,
        string $createdAt,
        ?string $updatedAt = null
    ): array {
        $updatedAt ??= $createdAt;
        foreach ($data['users'] as &$user) {
            if ($user['project_key'] !== $projectKey || $user['author_id'] !== $authorId) {
                continue;
            }
            if ($user['author_name'] !== $authorName) {
                $user['author_name'] = $authorName;
                $user['updated_at'] = $updatedAt;
            }
            $result = [
                'color_index' => (int) $user['color_index'],
                'sequence_number' => (int) $user['sequence_number'],
            ];
            unset($user);
            return $result;
        }
        unset($user);

        $sequenceNumber = 1;
        foreach ($data['users'] as $user) {
            if ($user['project_key'] === $projectKey) {
                $sequenceNumber = max($sequenceNumber, (int) $user['sequence_number'] + 1);
            }
        }
        $colorIndex = (($sequenceNumber - 1) % 10) + 1;
        $data['users'][] = [
            'project_key' => $projectKey,
            'author_id' => $authorId,
            'author_name' => $authorName,
            'color_index' => $colorIndex,
            'sequence_number' => $sequenceNumber,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ];
        return ['color_index' => $colorIndex, 'sequence_number' => $sequenceNumber];
    }

    /** @param array<string, mixed> $data */
    private function projectUserColor(array $data, string $projectKey, string $authorId): int
    {
        foreach ($data['users'] as $user) {
            if ($user['project_key'] === $projectKey && $user['author_id'] === $authorId) {
                return (int) $user['color_index'];
            }
        }
        return 1;
    }

    /** @param array<string, mixed> $data */
    private function backfillProjectUsers(array &$data): void
    {
        $authors = [];
        $pinProjects = [];
        foreach ($data['pins'] as $pin) {
            $pinProjects[(string) $pin['id']] = (string) $pin['project_key'];
            $key = (string) $pin['project_key'] . "\0" . (string) $pin['author_id'];
            $authors[$key][] = [
                'project_key' => (string) $pin['project_key'],
                'author_id' => (string) $pin['author_id'],
                'author_name' => (string) $pin['author_name'],
                'created_at' => (string) $pin['created_at'],
            ];
        }
        foreach ($data['messages'] as $message) {
            $projectKey = $pinProjects[(string) $message['pin_id']] ?? '';
            if ($projectKey === '') continue;
            $key = $projectKey . "\0" . (string) $message['author_id'];
            $authors[$key][] = [
                'project_key' => $projectKey,
                'author_id' => (string) $message['author_id'],
                'author_name' => (string) $message['author_name'],
                'created_at' => (string) $message['created_at'],
            ];
        }

        $normalized = [];
        foreach ($authors as $entries) {
            usort($entries, static fn (array $a, array $b): int => $a['created_at'] <=> $b['created_at']);
            $first = $entries[0];
            $last = $entries[count($entries) - 1];
            $normalized[] = [
                'project_key' => $first['project_key'],
                'author_id' => $first['author_id'],
                'author_name' => $last['author_name'],
                'created_at' => $first['created_at'],
                'updated_at' => $last['created_at'],
            ];
        }
        usort($normalized, static fn (array $a, array $b): int =>
            [$a['project_key'], $a['created_at'], $a['author_id']] <=> [$b['project_key'], $b['created_at'], $b['author_id']]
        );
        foreach ($normalized as $author) {
            $this->ensureProjectUser(
                $data,
                $author['project_key'],
                $author['author_id'],
                $author['author_name'],
                $author['created_at'],
                $author['updated_at']
            );
        }
    }

    /** @param array<string, mixed> $data */
    private function writeData(array $data): void
    {
        $directory = dirname($this->path);
        $temporary = $directory . '/.' . basename($this->path) . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents($temporary, $encoded, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write temporary JSON storage.');
        }
        if (!rename($temporary, $this->path)) {
            if (is_file($temporary)) {
                unlink($temporary);
            }
            throw new RuntimeException('Unable to replace JSON storage atomically.');
        }
    }
}
