<?php

declare(strict_types=1);

namespace ReviewLayer;

use PDO;
use PDOException;
use Throwable;

final class Database implements StorageInterface
{
    private PDO $pdo;

    public function __construct(string $path)
    {
        $this->pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        $this->initializeSchema();
    }

    public function mode(): string
    {
        return 'sqlite';
    }

    public function listPins(string $projectKey, string $pageKey): array
    {
        $statement = $this->pdo->prepare(
            'SELECT p.*, (SELECT m.message FROM messages m WHERE m.pin_id = p.id AND m.deleted_at IS NULL ORDER BY m.created_at ASC LIMIT 1) AS first_message
             FROM pins p
             WHERE p.project_key = :project_key AND p.page_key = :page_key AND p.deleted_at IS NULL
             ORDER BY p.pin_number ASC'
        );
        $statement->execute(['project_key' => $projectKey, 'page_key' => $pageKey]);
        return array_map(fn (array $row): array => $this->hydratePin($row), $statement->fetchAll());
    }

    public function listProjectPins(string $projectKey): array
    {
        $statement = $this->pdo->prepare(
            'SELECT p.id, p.page_key, p.page_url, p.pin_number, p.status, p.author_name, p.created_at, p.updated_at, p.viewport_json,
                    (SELECT m.message FROM messages m WHERE m.pin_id = p.id AND m.deleted_at IS NULL ORDER BY m.created_at ASC LIMIT 1) AS first_message
             FROM pins p
             WHERE p.project_key = :project_key AND p.deleted_at IS NULL
             ORDER BY p.pin_number DESC'
        );
        $statement->execute(['project_key' => $projectKey]);
        return array_map(static function (array $row): array {
            $row['pin_number'] = (int) $row['pin_number'];
            $viewport = json_decode((string) $row['viewport_json'], true, 64, JSON_THROW_ON_ERROR);
            $row['viewport'] = ['device_type' => is_array($viewport) ? (string) ($viewport['device_type'] ?? '') : ''];
            unset($row['viewport_json']);
            return $row;
        }, $statement->fetchAll());
    }

    public function getPin(string $id, string $projectKey): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM pins WHERE id = :id AND project_key = :project_key AND deleted_at IS NULL LIMIT 1');
        $statement->execute(['id' => $id, 'project_key' => $projectKey]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }
        $pin = $this->hydratePin($row);
        $messages = $this->pdo->prepare('SELECT * FROM messages WHERE pin_id = :pin_id AND deleted_at IS NULL ORDER BY created_at ASC, id ASC');
        $messages->execute(['pin_id' => $id]);
        $pin['messages'] = $messages->fetchAll();
        return $pin;
    }

    public function getMessage(string $id, string $projectKey): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT m.* FROM messages m
             INNER JOIN pins p ON p.id = m.pin_id
             WHERE m.id = :id AND p.project_key = :project_key AND p.deleted_at IS NULL AND m.deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute(['id' => $id, 'project_key' => $projectKey]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    public function createPin(array $pin, array $message): array
    {
        $this->pdo->beginTransaction();
        try {
            $counter = $this->pdo->prepare('INSERT OR IGNORE INTO project_counters (project_key, next_number) VALUES (:project_key, 1)');
            $counter->execute(['project_key' => $pin['project_key']]);
            $select = $this->pdo->prepare('SELECT next_number FROM project_counters WHERE project_key = :project_key');
            $select->execute(['project_key' => $pin['project_key']]);
            $pinNumber = (int) $select->fetchColumn();
            $increment = $this->pdo->prepare('UPDATE project_counters SET next_number = next_number + 1 WHERE project_key = :project_key');
            $increment->execute(['project_key' => $pin['project_key']]);
            $pin['pin_number'] = $pinNumber;

            $insertPin = $this->pdo->prepare(
                'INSERT INTO pins (
                    id, project_key, page_key, page_url, pin_number, status, author_id, author_name,
                    target_selector, target_fingerprint_json, anchor_json, viewport_json, browser_json,
                    created_at, updated_at, deleted_at
                 ) VALUES (
                    :id, :project_key, :page_key, :page_url, :pin_number, :status, :author_id, :author_name,
                    :target_selector, :target_fingerprint_json, :anchor_json, :viewport_json, :browser_json,
                    :created_at, :updated_at, NULL
                 )'
            );
            $insertPin->execute($this->serializePin($pin));
            $this->insertMessage($message);
            $this->pdo->commit();
            $pin['first_message'] = $message['message'];
            return $pin;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function addMessage(array $message): array
    {
        $this->pdo->beginTransaction();
        try {
            $exists = $this->pdo->prepare('SELECT 1 FROM pins WHERE id = :id AND project_key = :project_key AND deleted_at IS NULL');
            $exists->execute(['id' => $message['pin_id'], 'project_key' => $message['project_key']]);
            if ($exists->fetchColumn() === false) {
                $this->pdo->rollBack();
                throw new StorageNotFoundException('Pin not found.');
            }
            unset($message['project_key']);
            $this->insertMessage($message);
            $updated = $this->pdo->prepare('UPDATE pins SET updated_at = :updated_at WHERE id = :id');
            $updated->execute(['updated_at' => $message['created_at'], 'id' => $message['pin_id']]);
            $this->pdo->commit();
            return $message;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function updatePinStatus(string $id, string $projectKey, string $status, string $updatedAt): bool
    {
        $statement = $this->pdo->prepare('UPDATE pins SET status = :status, updated_at = :updated_at WHERE id = :id AND project_key = :project_key AND deleted_at IS NULL');
        $statement->execute(['status' => $status, 'updated_at' => $updatedAt, 'id' => $id, 'project_key' => $projectKey]);
        return $statement->rowCount() > 0;
    }

    public function softDeletePin(string $id, string $projectKey, string $deletedAt): bool
    {
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare('UPDATE pins SET deleted_at = :deleted_at, updated_at = :deleted_at WHERE id = :id AND project_key = :project_key AND deleted_at IS NULL');
            $statement->execute(['deleted_at' => $deletedAt, 'id' => $id, 'project_key' => $projectKey]);
            if ($statement->rowCount() === 0) {
                $this->pdo->rollBack();
                return false;
            }
            $messages = $this->pdo->prepare('UPDATE messages SET deleted_at = :deleted_at, updated_at = :deleted_at WHERE pin_id = :pin_id AND deleted_at IS NULL');
            $messages->execute(['deleted_at' => $deletedAt, 'pin_id' => $id]);
            $this->pdo->commit();
            return true;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function softDeleteMessage(string $id, string $projectKey, string $deletedAt): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE messages SET deleted_at = :deleted_at, updated_at = :deleted_at
             WHERE id = :id AND deleted_at IS NULL
             AND pin_id IN (SELECT id FROM pins WHERE project_key = :project_key AND deleted_at IS NULL)'
        );
        $statement->execute(['deleted_at' => $deletedAt, 'id' => $id, 'project_key' => $projectKey]);
        return $statement->rowCount() > 0;
    }

    public function exportAll(): array
    {
        return [
            'format_version' => 1,
            'exported_at' => gmdate('c'),
            'projects' => $this->pdo->query('SELECT project_key, next_number FROM project_counters ORDER BY project_key')->fetchAll(),
            'pins' => array_map(fn (array $row): array => $this->hydratePin($row), $this->pdo->query('SELECT * FROM pins ORDER BY project_key, pin_number')->fetchAll()),
            'messages' => $this->pdo->query('SELECT * FROM messages ORDER BY created_at, id')->fetchAll(),
        ];
    }

    public function restoreAll(array $backup): void
    {
        if (!isset($backup['projects'], $backup['pins'], $backup['messages']) || !is_array($backup['projects']) || !is_array($backup['pins']) || !is_array($backup['messages'])) {
            throw new \InvalidArgumentException('Backup structure is invalid.');
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('DELETE FROM messages; DELETE FROM pins; DELETE FROM project_counters;');
            $insertCounter = $this->pdo->prepare('INSERT INTO project_counters (project_key, next_number) VALUES (:project_key, :next_number)');
            foreach ($backup['projects'] as $project) {
                if (!is_array($project)) throw new \InvalidArgumentException('Backup project is invalid.');
                $insertCounter->execute([
                    'project_key' => Validation::projectKey($project['project_key'] ?? null),
                    'next_number' => max(1, (int) ($project['next_number'] ?? 1)),
                ]);
            }
            $insertPin = $this->pdo->prepare(
                'INSERT INTO pins (id, project_key, page_key, page_url, pin_number, status, author_id, author_name, target_selector, target_fingerprint_json, anchor_json, viewport_json, browser_json, created_at, updated_at, deleted_at)
                 VALUES (:id, :project_key, :page_key, :page_url, :pin_number, :status, :author_id, :author_name, :target_selector, :target_fingerprint_json, :anchor_json, :viewport_json, :browser_json, :created_at, :updated_at, :deleted_at)'
            );
            foreach ($backup['pins'] as $pin) {
                if (!is_array($pin)) throw new \InvalidArgumentException('Backup pin is invalid.');
                $serialized = $this->serializePin($pin);
                $serialized['deleted_at'] = $pin['deleted_at'] ?? null;
                $insertPin->execute($serialized);
            }
            $insertMessage = $this->pdo->prepare(
                'INSERT INTO messages (id, pin_id, author_id, author_name, message, created_at, updated_at, deleted_at)
                 VALUES (:id, :pin_id, :author_id, :author_name, :message, :created_at, :updated_at, :deleted_at)'
            );
            foreach ($backup['messages'] as $message) {
                if (!is_array($message)) throw new \InvalidArgumentException('Backup message is invalid.');
                $insertMessage->execute([
                    'id' => Validation::uuid($message['id'] ?? null),
                    'pin_id' => Validation::uuid($message['pin_id'] ?? null, 'pin_id'),
                    'author_id' => Validation::uuid($message['author_id'] ?? null, 'author_id'),
                    'author_name' => Validation::string($message['author_name'] ?? null, 'author_name', 1, 80),
                    'message' => Validation::string($message['message'] ?? null, 'message', 1, 5000),
                    'created_at' => (string) ($message['created_at'] ?? ''),
                    'updated_at' => (string) ($message['updated_at'] ?? ''),
                    'deleted_at' => isset($message['deleted_at']) && is_string($message['deleted_at']) ? $message['deleted_at'] : null,
                ]);
            }
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function clear(string $scope, string $mode, string $projectKey, string $pageKey, string $timestamp): array
    {
        [$where, $parameters] = $this->clearWhere($scope, $projectKey, $pageKey);
        $this->pdo->beginTransaction();
        try {
            $select = $this->pdo->prepare('SELECT id FROM pins WHERE ' . $where . ($mode === 'soft' ? ' AND deleted_at IS NULL' : ''));
            $select->execute($parameters);
            $ids = array_map('strval', $select->fetchAll(PDO::FETCH_COLUMN));
            if ($ids === []) {
                $this->pdo->commit();
                return ['pins' => 0, 'messages' => 0];
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $messageCount = $this->pdo->prepare('SELECT COUNT(*) FROM messages WHERE pin_id IN (' . $placeholders . ')' . ($mode === 'soft' ? ' AND deleted_at IS NULL' : ''));
            $messageCount->execute($ids);
            $messages = (int) $messageCount->fetchColumn();

            if ($mode === 'soft') {
                $messageUpdate = $this->pdo->prepare('UPDATE messages SET deleted_at = ?, updated_at = ? WHERE pin_id IN (' . $placeholders . ') AND deleted_at IS NULL');
                $messageUpdate->execute([$timestamp, $timestamp, ...$ids]);
                $pinUpdate = $this->pdo->prepare('UPDATE pins SET deleted_at = ?, updated_at = ? WHERE id IN (' . $placeholders . ') AND deleted_at IS NULL');
                $pinUpdate->execute([$timestamp, $timestamp, ...$ids]);
            } else {
                $deleteMessages = $this->pdo->prepare('DELETE FROM messages WHERE pin_id IN (' . $placeholders . ')');
                $deleteMessages->execute($ids);
                $deletePins = $this->pdo->prepare('DELETE FROM pins WHERE id IN (' . $placeholders . ')');
                $deletePins->execute($ids);
                if ($scope === 'all_projects') {
                    $this->pdo->exec('DELETE FROM project_counters');
                } elseif ($scope === 'current_project') {
                    $reset = $this->pdo->prepare('DELETE FROM project_counters WHERE project_key = :project_key');
                    $reset->execute(['project_key' => $projectKey]);
                }
            }
            $this->pdo->commit();
            return ['pins' => count($ids), 'messages' => $messages];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function logAdmin(array $event): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO admin_log (id, action, scope, mode, project_key, page_key, result_json, actor_hash, created_at)
             VALUES (:id, :action, :scope, :mode, :project_key, :page_key, :result_json, :actor_hash, :created_at)'
        );
        $statement->execute([
            'id' => $event['id'],
            'action' => $event['action'],
            'scope' => $event['scope'],
            'mode' => $event['mode'],
            'project_key' => $event['project_key'],
            'page_key' => $event['page_key'],
            'result_json' => json_encode($event['result'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'actor_hash' => $event['actor_hash'],
            'created_at' => $event['created_at'],
        ]);
    }

    private function initializeSchema(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS pins (
                id TEXT PRIMARY KEY,
                project_key TEXT NOT NULL,
                page_key TEXT NOT NULL,
                page_url TEXT NOT NULL,
                pin_number INTEGER NOT NULL,
                status TEXT NOT NULL CHECK (status IN (\'open\', \'resolved\')),
                author_id TEXT NOT NULL,
                author_name TEXT NOT NULL,
                target_selector TEXT NOT NULL,
                target_fingerprint_json TEXT NOT NULL,
                anchor_json TEXT NOT NULL,
                viewport_json TEXT NOT NULL,
                browser_json TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                deleted_at TEXT NULL,
                UNIQUE (project_key, pin_number)
            );
            CREATE INDEX IF NOT EXISTS pins_page_idx ON pins (project_key, page_key, deleted_at);
            CREATE TABLE IF NOT EXISTS messages (
                id TEXT PRIMARY KEY,
                pin_id TEXT NOT NULL,
                author_id TEXT NOT NULL,
                author_name TEXT NOT NULL,
                message TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                deleted_at TEXT NULL,
                FOREIGN KEY (pin_id) REFERENCES pins(id) ON DELETE CASCADE
            );
            CREATE INDEX IF NOT EXISTS messages_pin_idx ON messages (pin_id, deleted_at, created_at);
            CREATE TABLE IF NOT EXISTS project_counters (
                project_key TEXT PRIMARY KEY,
                next_number INTEGER NOT NULL
            );
            CREATE TABLE IF NOT EXISTS admin_log (
                id TEXT PRIMARY KEY,
                action TEXT NOT NULL,
                scope TEXT NOT NULL,
                mode TEXT NOT NULL,
                project_key TEXT NOT NULL,
                page_key TEXT NOT NULL,
                result_json TEXT NOT NULL,
                actor_hash TEXT NOT NULL,
                created_at TEXT NOT NULL
            );'
        );
    }

    /** @param array<string, mixed> $message */
    private function insertMessage(array $message): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO messages (id, pin_id, author_id, author_name, message, created_at, updated_at, deleted_at)
             VALUES (:id, :pin_id, :author_id, :author_name, :message, :created_at, :updated_at, NULL)'
        );
        $statement->execute([
            'id' => $message['id'],
            'pin_id' => $message['pin_id'],
            'author_id' => $message['author_id'],
            'author_name' => $message['author_name'],
            'message' => $message['message'],
            'created_at' => $message['created_at'],
            'updated_at' => $message['updated_at'],
        ]);
    }

    /** @param array<string, mixed> $pin @return array<string, mixed> */
    private function serializePin(array $pin): array
    {
        return [
            'id' => $pin['id'],
            'project_key' => $pin['project_key'],
            'page_key' => $pin['page_key'],
            'page_url' => $pin['page_url'],
            'pin_number' => $pin['pin_number'],
            'status' => $pin['status'],
            'author_id' => $pin['author_id'],
            'author_name' => $pin['author_name'],
            'target_selector' => $pin['target_selector'],
            'target_fingerprint_json' => json_encode($pin['target_fingerprint'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'anchor_json' => json_encode($pin['anchor'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'viewport_json' => json_encode($pin['viewport'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'browser_json' => json_encode($pin['browser'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created_at' => $pin['created_at'],
            'updated_at' => $pin['updated_at'],
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function hydratePin(array $row): array
    {
        $row['pin_number'] = (int) $row['pin_number'];
        $row['target_fingerprint'] = json_decode((string) $row['target_fingerprint_json'], true, 64, JSON_THROW_ON_ERROR);
        $row['anchor'] = json_decode((string) $row['anchor_json'], true, 64, JSON_THROW_ON_ERROR);
        $row['viewport'] = json_decode((string) $row['viewport_json'], true, 64, JSON_THROW_ON_ERROR);
        $row['browser'] = json_decode((string) $row['browser_json'], true, 64, JSON_THROW_ON_ERROR);
        unset($row['target_fingerprint_json'], $row['anchor_json'], $row['viewport_json'], $row['browser_json']);
        return $row;
    }

    /** @return array{0:string,1:array<string,string>} */
    private function clearWhere(string $scope, string $projectKey, string $pageKey): array
    {
        return match ($scope) {
            'current_page' => ['project_key = :project_key AND page_key = :page_key', ['project_key' => $projectKey, 'page_key' => $pageKey]],
            'current_project' => ['project_key = :project_key', ['project_key' => $projectKey]],
            'resolved_in_project' => ['project_key = :project_key AND status = \'resolved\'', ['project_key' => $projectKey]],
            'all_projects' => ['1 = 1', []],
            default => throw new \InvalidArgumentException('Invalid clear scope.'),
        };
    }
}

final class StorageNotFoundException extends \RuntimeException
{
}
