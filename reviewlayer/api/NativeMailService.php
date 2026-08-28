<?php

declare(strict_types=1);

namespace ReviewLayer;

final class NativeMailService
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    public function isAvailable(): bool
    {
        return (bool) ($this->config['NOTIFICATIONS_ENABLED'] ?? true)
            && function_exists('mail')
            && $this->fromEmail() !== ''
            && $this->publicBaseUrl() !== '';
    }

    public function verificationUrl(string $token): string
    {
        $base = $this->publicBaseUrl();
        if ($base === '') {
            throw new NotificationException('NOTIFICATIONS_UNAVAILABLE', 'A trusted notification public URL is required.');
        }
        return $base . 'api/index.php?action=verify-email&token=' . rawurlencode($token);
    }

    public function sendVerification(string $recipient, string $language, string $recipientName, string $verificationUrl, int $expiresMinutes): bool
    {
        $polish = $language === 'pl';
        $subject = $polish ? 'ReviewLayer: potwierdź adres e-mail' : 'ReviewLayer: confirm your email address';
        $body = $polish
            ? "Cześć {$this->line($recipientName)},\n\nPotwierdź adres e-mail używany do ręcznych powiadomień ReviewLayer:\n{$verificationUrl}\n\nJeśli ten sam adres jest już potwierdzony w projekcie na innym urządzeniu, bieżąca przeglądarka zostanie bezpiecznie połączona z istniejącym komentującym.\n\nLink wygaśnie za {$expiresMinutes} min. Jeśli nie prosisz o tę zmianę, zignoruj tę wiadomość.\n\nReviewLayer"
            : "Hello {$this->line($recipientName)},\n\nConfirm the email address used for manual ReviewLayer notifications:\n{$verificationUrl}\n\nIf the same address is already verified for this project on another device, this browser will be securely linked to the existing commenter.\n\nThis link expires in {$expiresMinutes} minutes. If you did not request this change, ignore this message.\n\nReviewLayer";
        return $this->send($recipient, $subject, $body);
    }

    public function sendNotification(
        string $recipient,
        string $language,
        string $recipientName,
        string $senderName,
        string $projectKey,
        string $pageUrl,
        array $items
    ): bool {
        $recipientName = $this->line($recipientName);
        $senderName = $this->line($senderName);
        $projectKey = $this->line($projectKey);
        $pageUrl = $this->line($pageUrl);
        $polish = $language === 'pl';
        $digest = $this->notificationDigest($items, $polish);
        $digestBlock = $digest === ''
            ? ''
            : ($polish ? "\n\nZmiany do sprawdzenia:\n{$digest}" : "\n\nChanges to review:\n{$digest}");
        $subject = $polish ? 'ReviewLayer: nowe komentarze w projekcie' : 'ReviewLayer: new project comments';
        $body = $polish
            ? "Cześć {$recipientName},\n\n{$senderName} prosi o sprawdzenie komentarzy w projekcie „{$projectKey}”.{$digestBlock}\n\nOtwórz stronę projektu:\n{$pageUrl}\n\nTa wiadomość została wysłana ręcznie z ReviewLayer.\n\nReviewLayer"
            : "Hello {$recipientName},\n\n{$senderName} asked you to review the comments in project “{$projectKey}”.{$digestBlock}\n\nOpen the project page:\n{$pageUrl}\n\nThis message was sent manually from ReviewLayer.\n\nReviewLayer";
        return $this->send($recipient, $subject, $body);
    }

    /** @param list<array{pin_number:int,events:list<array{id:string,type:string,created_at:string}>}> $items */
    private function notificationDigest(array $items, bool $polish): string
    {
        $lines = [];
        foreach ($items as $item) {
            $pinNumber = (int) ($item['pin_number'] ?? 0);
            if ($pinNumber < 1) continue;
            $label = ($polish ? 'Pinezka #' : 'Pin #') . $pinNumber;
            $tokens = $this->groupNotificationEvents(
                isset($item['events']) && is_array($item['events']) ? $item['events'] : [],
                $polish
            );
            if ($tokens !== []) $label .= ' — ' . implode(' — ', $tokens);
            $lines[] = $label;
        }
        return implode("\n", $lines);
    }

    /** @param list<array{id:string,type:string,created_at:string}> $events @return list<string> */
    private function groupNotificationEvents(array $events, bool $polish): array
    {
        $groups = [];
        foreach ($events as $event) {
            $type = (string) ($event['type'] ?? '');
            $label = match ($type) {
                'comment' => $polish ? 'komentarz' : 'comment',
                'solved' => $polish ? 'rozwiązana' : 'solved',
                'reopened' => $polish ? 'otwarta ponownie' : 'reopened',
                default => '',
            };
            if ($label === '') continue;
            $lastIndex = array_key_last($groups);
            if ($lastIndex !== null && $groups[$lastIndex]['type'] === $type) {
                $groups[$lastIndex]['count']++;
            } else {
                $groups[] = ['type' => $type, 'label' => $label, 'count' => 1];
            }
        }
        return array_map(
            static fn (array $group): string => $group['label'] . ($group['count'] > 1 ? ' ×' . $group['count'] : ''),
            $groups
        );
    }

    private function send(string $recipient, string $subject, string $body): bool
    {
        if (!$this->isAvailable() || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }
        $fromEmail = $this->fromEmail();
        $fromName = $this->line((string) ($this->config['NOTIFICATION_FROM_NAME'] ?? 'ReviewLayer')) ?: 'ReviewLayer';
        $encodedFromName = $this->encodeHeader($fromName);
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'From: ' . $encodedFromName . ' <' . $fromEmail . '>',
            'Auto-Submitted: auto-generated',
            'X-Auto-Response-Suppress: All',
            'X-Mailer: ReviewLayer/' . REVIEWLAYER_VERSION,
        ];
        return @mail($recipient, $this->encodeHeader($subject), str_replace("\n", "\r\n", $body), implode("\r\n", $headers));
    }

    private function fromEmail(): string
    {
        $configured = trim((string) ($this->config['NOTIFICATION_FROM_EMAIL'] ?? ''));
        if ($configured !== '') {
            return filter_var($configured, FILTER_VALIDATE_EMAIL) !== false ? $configured : '';
        }
        $parts = parse_url($this->publicBaseUrl());
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        $host = preg_replace('/^www\./', '', $host) ?? '';
        $derived = 'reviewlayer@' . $host;
        return filter_var($derived, FILTER_VALIDATE_EMAIL) !== false ? $derived : '';
    }

    private function publicBaseUrl(): string
    {
        $configured = trim((string) ($this->config['NOTIFICATION_PUBLIC_BASE_URL'] ?? ''));
        if ($configured === '') {
            return '';
        }
        $parts = parse_url($configured);
        if (!is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            return '';
        }
        return rtrim($configured, '/') . '/';
    }

    private function encodeHeader(string $value): string
    {
        return function_exists('mb_encode_mimeheader')
            ? mb_encode_mimeheader($value, 'UTF-8', 'B', "\r\n")
            : '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function line(string $value): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));
    }
}
