<?php

declare(strict_types=1);

namespace ReviewLayer;

use RuntimeException;

final class UsageLimits
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly array $config
    ) {
    }

    public function assertCanCreatePin(string $projectKey, string $authorId): void
    {
        $data = $this->storage->exportAll();
        $pins = isset($data['pins']) && is_array($data['pins']) ? $data['pins'] : [];
        $messages = isset($data['messages']) && is_array($data['messages']) ? $data['messages'] : [];

        $this->assertBelowLimit(count($pins), 'MAX_TOTAL_PINS');
        $this->assertBelowLimit(count($messages), 'MAX_TOTAL_MESSAGES');

        $authorPins = 0;
        foreach ($pins as $pin) {
            if (!is_array($pin) || ($pin['deleted_at'] ?? null) !== null) {
                continue;
            }
            if (($pin['project_key'] ?? null) === $projectKey && ($pin['author_id'] ?? null) === $authorId) {
                $authorPins++;
            }
        }
        $this->assertBelowLimit($authorPins, 'MAX_PINS_PER_AUTHOR');
    }

    public function assertCanAddMessage(string $pinId): void
    {
        $data = $this->storage->exportAll();
        $messages = isset($data['messages']) && is_array($data['messages']) ? $data['messages'] : [];

        $this->assertBelowLimit(count($messages), 'MAX_TOTAL_MESSAGES');

        $pinMessages = 0;
        foreach ($messages as $message) {
            if (!is_array($message) || ($message['deleted_at'] ?? null) !== null) {
                continue;
            }
            if (($message['pin_id'] ?? null) === $pinId) {
                $pinMessages++;
            }
        }
        $this->assertBelowLimit($pinMessages, 'MAX_MESSAGES_PER_PIN');
    }

    private function assertBelowLimit(int $current, string $configKey): void
    {
        $limit = max(0, (int) ($this->config[$configKey] ?? 0));
        if ($limit > 0 && $current >= $limit) {
            throw new UsageLimitException('The configured demo usage limit has been reached.');
        }
    }
}

final class UsageLimitException extends RuntimeException
{
}
