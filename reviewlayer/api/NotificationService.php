<?php

declare(strict_types=1);

namespace ReviewLayer;

use RuntimeException;
use Throwable;

final class NotificationService
{
    private string $path;
    private string $lockPath;
    private string $keyPath;
    private NativeMailService $mailer;

    /** @param array<string, mixed> $config */
    public function __construct(private readonly string $dataDirectory, private readonly array $config)
    {
        $this->path = $dataDirectory . '/notifications.json';
        $this->lockPath = $dataDirectory . '/.notifications.lock';
        $this->keyPath = $dataDirectory . '/.notification-key';
        $this->mailer = new NativeMailService($config);
    }

    public function isAvailable(): bool
    {
        $authenticatedEncryptionAvailable = (
            function_exists('sodium_crypto_secretbox') && function_exists('sodium_crypto_secretbox_open')
        ) || (
            function_exists('openssl_encrypt') && function_exists('openssl_decrypt')
        );
        if (!(bool) ($this->config['NOTIFICATIONS_ENABLED'] ?? true)
            || !$this->mailer->isAvailable()
            || !$authenticatedEncryptionAvailable) {
            return false;
        }
        try {
            $this->encryptionKey();
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function resolveAuthorId(string $projectKey, string $authorId): string
    {
        if (!is_file($this->path)) {
            return $authorId;
        }
        try {
            return $this->resolveAuthorIdInData($this->read(), $projectKey, $authorId);
        } catch (Throwable $error) {
            error_log('[ReviewLayer] Notification identity lookup error: ' . $error->getMessage());
            return $authorId;
        }
    }

    public function synchronizeLinkedAuthors(StorageInterface $storage): int
    {
        if (!is_file($this->path)) {
            return 0;
        }
        $data = $this->read();
        $completed = [];
        foreach ($data['aliases'] as $alias) {
            if ((string) ($alias['storage_merged_at'] ?? '') !== '') continue;
            $projectKey = (string) ($alias['project_key'] ?? '');
            $sourceAuthorId = (string) ($alias['source_author_id'] ?? '');
            $canonicalAuthorId = (string) ($alias['canonical_author_id'] ?? '');
            if ($projectKey === '' || $sourceAuthorId === '' || $canonicalAuthorId === '') continue;
            try {
                $canonicalExists = false;
                $sourceExists = false;
                foreach ($storage->listProjectUserRecords($projectKey) as $user) {
                    $candidateId = (string) ($user['author_id'] ?? '');
                    if (hash_equals($candidateId, $canonicalAuthorId)) $canonicalExists = true;
                    if (hash_equals($candidateId, $sourceAuthorId)) $sourceExists = true;
                }
                if (!$canonicalExists) continue;
                if ($sourceExists && !$storage->mergeProjectAuthors($projectKey, $canonicalAuthorId, $sourceAuthorId)) continue;
                $completed[] = $projectKey . "\0" . $sourceAuthorId . "\0" . $canonicalAuthorId;
            } catch (Throwable $error) {
                error_log('[ReviewLayer] Deferred author merge: ' . $error->getMessage());
            }
        }
        if ($completed === []) return 0;
        return $this->mutate(function (array &$latest) use ($completed): int {
            $completedMap = array_fill_keys($completed, true);
            $marked = 0;
            foreach ($latest['aliases'] as &$alias) {
                $key = (string) ($alias['project_key'] ?? '') . "\0"
                    . (string) ($alias['source_author_id'] ?? '') . "\0"
                    . (string) ($alias['canonical_author_id'] ?? '');
                if (!isset($completedMap[$key]) || (string) ($alias['storage_merged_at'] ?? '') !== '') continue;
                $alias['storage_merged_at'] = gmdate('c');
                $alias['updated_at'] = gmdate('c');
                $marked++;
            }
            unset($alias);
            return $marked;
        });
    }

    public function pruneOrphanedProfiles(StorageInterface $storage): int
    {
        if (!is_file($this->path)) {
            return 0;
        }
        return $this->mutate(function (array &$data) use ($storage): int {
            $activeUsers = [];
            $projects = [];
            foreach ($data['profiles'] as $profile) {
                $projectKey = (string) ($profile['project_key'] ?? '');
                if ($projectKey !== '') {
                    $projects[$projectKey] = true;
                }
            }
            foreach ($data['aliases'] as $alias) {
                $projectKey = (string) ($alias['project_key'] ?? '');
                if ($projectKey !== '') {
                    $projects[$projectKey] = true;
                }
            }
            foreach (array_keys($projects) as $projectKey) {
                foreach ($storage->listProjectUserRecords($projectKey) as $user) {
                    $activeUsers[$projectKey . "\0" . (string) ($user['author_id'] ?? '')] = true;
                }
            }

            $before = count($data['profiles']);
            $data['profiles'] = array_values(array_filter(
                $data['profiles'],
                static fn (array $profile): bool => isset($activeUsers[
                    (string) ($profile['project_key'] ?? '') . "\0" . (string) ($profile['author_id'] ?? '')
                ])
            ));
            $activeProfiles = [];
            $activeRecipients = [];
            foreach ($data['profiles'] as $profile) {
                $activeProfiles[(string) ($profile['project_key'] ?? '') . "\0" . (string) ($profile['author_id'] ?? '')] = true;
                $activeRecipients[(string) ($profile['recipient_id'] ?? '')] = true;
            }
            $data['aliases'] = array_values(array_filter(
                $data['aliases'],
                static fn (array $alias): bool => isset($activeProfiles[
                    (string) ($alias['project_key'] ?? '') . "\0" . (string) ($alias['canonical_author_id'] ?? '')
                ])
            ));
            $data['deliveries'] = array_values(array_filter(
                $data['deliveries'],
                static fn (array $delivery): bool => isset($activeProfiles[
                    (string) ($delivery['project_key'] ?? '') . "\0" . (string) ($delivery['author_id'] ?? '')
                ]) && isset($activeRecipients[(string) ($delivery['recipient_id'] ?? '')])
            ));
            $data['verification_attempts'] = array_values(array_filter(
                $data['verification_attempts'],
                static fn (array $attempt): bool => isset($activeProfiles[
                    (string) ($attempt['project_key'] ?? '') . "\0" . (string) ($attempt['author_id'] ?? '')
                ])
            ));
            return $before - count($data['profiles']);
        });
    }

    /** @return array<string, mixed> */
    public function settings(StorageInterface $storage, string $projectKey, string $authorId, string $authorSecret, string $language): array
    {
        $this->assertAvailable();
        $profile = $this->claimProfile($storage, $projectKey, $authorId, $authorSecret, $language);
        return $this->publicSettings($profile);
    }

    /** @return array<string, mixed> */
    public function requestVerification(
        StorageInterface $storage,
        string $projectKey,
        string $authorId,
        string $authorSecret,
        string $language,
        string $email
    ): array {
        $this->assertAvailable();
        $email = strtolower(trim($email));
        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n]/', $email) === 1) {
            throw new NotificationException('VALIDATION_ERROR', 'The email address is invalid.');
        }
        $profile = $this->claimProfile($storage, $projectKey, $authorId, $authorSecret, $language);
        if ((string) ($profile['email_ciphertext'] ?? '') !== '' && (string) ($profile['email_verified_at'] ?? '') !== '') {
            try {
                if (hash_equals($this->decrypt((string) $profile['email_ciphertext']), $email)) {
                    return $this->publicSettings($profile) + ['verification_sent' => false];
                }
            } catch (Throwable) {
                throw new NotificationException('NOTIFICATIONS_UNAVAILABLE', 'The saved email address cannot be decrypted.');
            }
        }

        $secret = $this->randomToken();
        $tokenHash = hash('sha256', $secret);
        $expiresAt = gmdate('c', time() + max(300, (int) $this->config['EMAIL_VERIFICATION_TTL_SECONDS']));
        $attemptId = uuidV4();
        $pendingCiphertext = $this->encrypt($email);
        $updated = $this->mutate(function (array &$data) use ($profile, $tokenHash, $expiresAt, $attemptId, $pendingCiphertext): array {
            $index = $this->profileIndex($data, (string) $profile['project_key'], (string) $profile['author_id']);
            if ($index === null) {
                throw new NotificationException('PROFILE_UNAVAILABLE', 'The notification profile is unavailable.');
            }
            $this->assertVerificationLimit($data, (string) $profile['project_key'], (string) $profile['author_id']);
            $data['profiles'][$index]['pending_email_ciphertext'] = $pendingCiphertext;
            $data['profiles'][$index]['verification_token_hash'] = $tokenHash;
            $data['profiles'][$index]['verification_expires_at'] = $expiresAt;
            $data['profiles'][$index]['updated_at'] = gmdate('c');
            $data['verification_attempts'][] = [
                'id' => $attemptId,
                'project_key' => $profile['project_key'],
                'author_id' => $profile['author_id'],
                'ip_hash' => $this->ipHash(),
                'created_at' => gmdate('c'),
                'status' => 'reserved',
            ];
            return $data['profiles'][$index];
        });

        $token = (string) $profile['recipient_id'] . '.' . $secret;
        $sent = $this->mailer->sendVerification(
            $email,
            (string) $updated['language'],
            (string) $updated['author_name'],
            $this->mailer->verificationUrl($token),
            (int) ceil(max(300, (int) $this->config['EMAIL_VERIFICATION_TTL_SECONDS']) / 60)
        );
        $this->finishVerificationAttempt($attemptId, $sent, (string) $profile['project_key'], (string) $profile['author_id'], $tokenHash);
        if (!$sent) {
            throw new NotificationException('MAIL_FAILED', 'The verification email could not be accepted by the server mail transport.');
        }
        return $this->publicSettings($updated) + ['verification_sent' => true];
    }

    /** @return array<string, mixed> */
    public function verify(StorageInterface $storage, string $token): array
    {
        $this->assertAvailable();
        if (preg_match('/^([0-9a-f-]{36})\.([A-Za-z0-9_-]{43})$/D', $token, $matches) !== 1) {
            throw new NotificationException('VALIDATION_ERROR', 'The verification link is invalid.');
        }
        $recipientId = Validation::uuid($matches[1], 'recipient_id');
        $tokenHash = hash('sha256', $matches[2]);
        $result = $this->mutate(function (array &$data) use ($recipientId, $tokenHash): array {
            $sourceIndex = null;
            foreach ($data['profiles'] as $index => $profile) {
                if (hash_equals((string) ($profile['recipient_id'] ?? ''), $recipientId)) {
                    $sourceIndex = $index;
                    break;
                }
            }
            if ($sourceIndex === null) {
                throw new NotificationException('VERIFICATION_EXPIRED', 'The verification link is invalid or expired.');
            }
            $source = $data['profiles'][$sourceIndex];
            $storedHash = (string) ($source['verification_token_hash'] ?? '');
            $expires = strtotime((string) ($source['verification_expires_at'] ?? '')) ?: 0;
            if ($storedHash === '' || !hash_equals($storedHash, $tokenHash) || $expires < time()) {
                throw new NotificationException('VERIFICATION_EXPIRED', 'The verification link is invalid or expired.');
            }
            $pending = (string) ($source['pending_email_ciphertext'] ?? '');
            if ($pending === '') {
                throw new NotificationException('VERIFICATION_EXPIRED', 'The verification link is no longer active.');
            }
            $email = strtolower($this->decrypt($pending));
            $projectKey = (string) $source['project_key'];
            $matchingIndexes = [];
            foreach ($data['profiles'] as $index => $profile) {
                if ($index === $sourceIndex || (string) ($profile['project_key'] ?? '') !== $projectKey) continue;
                if ((string) ($profile['email_ciphertext'] ?? '') === '' || (string) ($profile['email_verified_at'] ?? '') === '') continue;
                try {
                    if (hash_equals(strtolower($this->decrypt((string) $profile['email_ciphertext'])), $email)) {
                        $matchingIndexes[] = $index;
                    }
                } catch (Throwable) {
                    continue;
                }
            }

            if ($matchingIndexes === []) {
                $source['email_ciphertext'] = $pending;
                $source['email_verified_at'] = gmdate('c');
                $source['pending_email_ciphertext'] = '';
                $source['verification_token_hash'] = '';
                $source['verification_expires_at'] = '';
                $source['updated_at'] = gmdate('c');
                $data['profiles'][$sourceIndex] = $source;
                return [
                    'project_key' => $projectKey,
                    'author_name' => (string) $source['author_name'],
                    'language' => (string) $source['language'],
                    'canonical_author_id' => (string) $source['author_id'],
                    'linked_author_ids' => [],
                    'linked' => false,
                ];
            }

            usort($matchingIndexes, static fn (int $a, int $b): int =>
                strcmp((string) ($data['profiles'][$a]['created_at'] ?? ''), (string) ($data['profiles'][$b]['created_at'] ?? ''))
            );
            $canonicalIndex = $matchingIndexes[0];
            $canonical = $data['profiles'][$canonicalIndex];
            $mergeIndexes = array_values(array_unique([$sourceIndex, ...array_slice($matchingIndexes, 1)]));
            $linkedAuthorIds = [];
            $linkedRecipientIds = [];
            $identityHashes = $this->profileIdentityHashes($canonical);
            foreach ($mergeIndexes as $mergeIndex) {
                $mergedProfile = $data['profiles'][$mergeIndex];
                $mergedAuthorId = (string) ($mergedProfile['author_id'] ?? '');
                if ($mergedAuthorId !== '' && !hash_equals($mergedAuthorId, (string) $canonical['author_id'])) {
                    $linkedAuthorIds[] = $mergedAuthorId;
                }
                $mergedRecipientId = (string) ($mergedProfile['recipient_id'] ?? '');
                if ($mergedRecipientId !== '') $linkedRecipientIds[$mergedRecipientId] = true;
                $identityHashes = array_values(array_unique([...$identityHashes, ...$this->profileIdentityHashes($mergedProfile)]));
            }
            $canonical['identity_hashes'] = $identityHashes;
            unset($canonical['identity_hash']);
            $canonical['updated_at'] = gmdate('c');

            $profiles = [];
            foreach ($data['profiles'] as $index => $profile) {
                if ($index === $canonicalIndex) {
                    $profiles[] = $canonical;
                } elseif (!in_array($index, $mergeIndexes, true)) {
                    $profiles[] = $profile;
                }
            }
            $data['profiles'] = $profiles;
            foreach ($data['aliases'] as &$alias) {
                if ((string) ($alias['project_key'] ?? '') === $projectKey
                    && in_array((string) ($alias['canonical_author_id'] ?? ''), $linkedAuthorIds, true)) {
                    $alias['canonical_author_id'] = (string) $canonical['author_id'];
                }
            }
            unset($alias);
            foreach ($linkedAuthorIds as $linkedAuthorId) {
                $this->upsertAlias($data, $projectKey, $linkedAuthorId, (string) $canonical['author_id']);
            }
            foreach ($data['deliveries'] as &$delivery) {
                if ((string) ($delivery['project_key'] ?? '') !== $projectKey) continue;
                if (in_array((string) ($delivery['author_id'] ?? ''), $linkedAuthorIds, true)) {
                    $delivery['author_id'] = (string) $canonical['author_id'];
                }
                if (isset($linkedRecipientIds[(string) ($delivery['recipient_id'] ?? '')])) {
                    $delivery['recipient_id'] = (string) $canonical['recipient_id'];
                }
            }
            unset($delivery);
            foreach ($data['verification_attempts'] as &$attempt) {
                if ((string) ($attempt['project_key'] ?? '') === $projectKey
                    && in_array((string) ($attempt['author_id'] ?? ''), $linkedAuthorIds, true)) {
                    $attempt['author_id'] = (string) $canonical['author_id'];
                }
            }
            unset($attempt);
            return [
                'project_key' => $projectKey,
                'author_name' => (string) $canonical['author_name'],
                'language' => (string) $source['language'],
                'canonical_author_id' => (string) $canonical['author_id'],
                'linked_author_ids' => array_values(array_unique($linkedAuthorIds)),
                'linked' => true,
            ];
        });
        try {
            $this->synchronizeLinkedAuthors($storage);
        } catch (Throwable $error) {
            error_log('[ReviewLayer] Deferred author synchronization: ' . $error->getMessage());
        }
        unset($result['project_key']);
        unset($result['linked_author_ids']);
        return $result;
    }

    /** @return array<string, mixed> */
    public function removeEmail(StorageInterface $storage, string $projectKey, string $authorId, string $authorSecret, string $language): array
    {
        $this->assertAvailable();
        $profile = $this->claimProfile($storage, $projectKey, $authorId, $authorSecret, $language);
        $updated = $this->mutate(function (array &$data) use ($profile): array {
            $index = $this->profileIndex($data, (string) $profile['project_key'], (string) $profile['author_id']);
            if ($index === null) {
                throw new NotificationException('PROFILE_UNAVAILABLE', 'The notification profile is unavailable.');
            }
            foreach (['email_ciphertext', 'email_verified_at', 'pending_email_ciphertext', 'verification_token_hash', 'verification_expires_at'] as $field) {
                $data['profiles'][$index][$field] = '';
            }
            $data['profiles'][$index]['updated_at'] = gmdate('c');
            return $data['profiles'][$index];
        });
        return $this->publicSettings($updated);
    }

    /** @return array<int, array<string, mixed>> */
    public function recipients(StorageInterface $storage, string $projectKey, string $authorId, string $authorSecret, string $language): array
    {
        $this->assertAvailable();
        $sender = $this->claimProfile($storage, $projectKey, $authorId, $authorSecret, $language);
        $users = $storage->listProjectUserRecords($projectKey);
        $data = $this->read();
        $profiles = [];
        foreach ($data['profiles'] as $profile) {
            if ((string) ($profile['project_key'] ?? '') === $projectKey) {
                $profiles[(string) ($profile['author_id'] ?? '')] = $profile;
            }
        }
        return array_map(static function (array $user) use ($profiles, $sender): array {
            $profile = $profiles[(string) $user['author_id']] ?? [];
            $verified = (string) ($profile['email_ciphertext'] ?? '') !== '' && (string) ($profile['email_verified_at'] ?? '') !== '';
            return [
                'author_name' => (string) $user['author_name'],
                'color_index' => (int) $user['color_index'],
                'role_key' => (string) ($user['role_key'] ?? 'unassigned'),
                'recipient_id' => $verified ? (string) ($profile['recipient_id'] ?? '') : '',
                'email_verified' => $verified,
                'is_current' => hash_equals((string) $user['author_id'], (string) $sender['author_id']),
            ];
        }, $users);
    }

    public function send(
        StorageInterface $storage,
        string $projectKey,
        string $authorId,
        string $authorSecret,
        string $language,
        string $recipientId,
        string $pageUrl
    ): void {
        $this->assertAvailable();
        $sender = $this->claimProfile($storage, $projectKey, $authorId, $authorSecret, $language);
        $recipient = $this->readProfileByRecipientId($projectKey, $recipientId);
        if ($recipient === null || (string) ($recipient['email_verified_at'] ?? '') === '' || (string) ($recipient['email_ciphertext'] ?? '') === '') {
            throw new NotificationException('EMAIL_NOT_VERIFIED', 'The recipient has no verified email address.');
        }
        if (hash_equals((string) $recipient['author_id'], (string) $sender['author_id'])) {
            throw new NotificationException('VALIDATION_ERROR', 'You cannot notify yourself.');
        }
        $pageUrl = Validation::canonicalPageKey(Validation::pageUrl($pageUrl));
        $notificationItems = $this->notificationItems($storage, $projectKey, $pageUrl, (string) $sender['author_id']);
        $this->deliverNotification($projectKey, $sender, $recipient, $recipientId, $pageUrl, $notificationItems);
    }

    public function sendRole(
        StorageInterface $storage,
        string $projectKey,
        string $authorId,
        string $authorSecret,
        string $language,
        string $audienceRole,
        string $pageUrl
    ): int {
        $this->assertAvailable();
        $sender = $this->claimProfile($storage, $projectKey, $authorId, $authorSecret, $language);
        $audienceRole = Validation::audienceRole($audienceRole);
        $eligibleAuthorIds = [];
        foreach ($storage->listProjectUserRecords($projectKey) as $user) {
            $roleKey = (string) ($user['role_key'] ?? 'unassigned');
            if ($audienceRole !== 'all' && !in_array($roleKey, [$audienceRole, 'generalist'], true)) continue;
            $eligibleAuthorIds[(string) ($user['author_id'] ?? '')] = true;
        }

        $recipients = [];
        foreach ($this->read()['profiles'] as $profile) {
            $profileAuthorId = (string) ($profile['author_id'] ?? '');
            $recipientId = (string) ($profile['recipient_id'] ?? '');
            if ((string) ($profile['project_key'] ?? '') !== $projectKey
                || !isset($eligibleAuthorIds[$profileAuthorId])
                || hash_equals($profileAuthorId, (string) $sender['author_id'])
                || (string) ($profile['email_verified_at'] ?? '') === ''
                || (string) ($profile['email_ciphertext'] ?? '') === ''
                || $recipientId === '') {
                continue;
            }
            $recipients[$recipientId] = $profile;
        }
        if ($recipients === []) {
            throw new NotificationException('EMAIL_NOT_VERIFIED', 'No matching commenter has a verified email address.');
        }
        if (count($recipients) > 25) {
            throw new NotificationException('VALIDATION_ERROR', 'A role notification can include at most 25 recipients.');
        }

        $pageUrl = Validation::canonicalPageKey(Validation::pageUrl($pageUrl));
        $notificationItems = $this->notificationItems($storage, $projectKey, $pageUrl, (string) $sender['author_id']);
        $sent = 0;
        $firstError = null;
        foreach ($recipients as $recipientId => $recipient) {
            try {
                $this->deliverNotification($projectKey, $sender, $recipient, (string) $recipientId, $pageUrl, $notificationItems);
                $sent++;
            } catch (Throwable $error) {
                $firstError ??= $error;
            }
        }
        if ($sent === 0 && $firstError instanceof Throwable) {
            throw $firstError;
        }
        return $sent;
    }

    /** @param array<string, mixed> $sender @param array<string, mixed> $recipient @param array<int, array<string, mixed>> $notificationItems */
    private function deliverNotification(
        string $projectKey,
        array $sender,
        array $recipient,
        string $recipientId,
        string $pageUrl,
        array $notificationItems
    ): void {
        $deliveryId = $this->reserveDelivery($projectKey, (string) $sender['author_id'], $recipientId);
        try {
            $email = $this->decrypt((string) $recipient['email_ciphertext']);
            $sent = $this->mailer->sendNotification(
                $email,
                (string) $recipient['language'],
                (string) $recipient['author_name'],
                (string) $sender['author_name'],
                $projectKey,
                $pageUrl,
                $notificationItems
            );
        } catch (Throwable $error) {
            $this->finishDelivery($deliveryId, false);
            if ($error instanceof NotificationException) {
                throw $error;
            }
            throw new NotificationException('MAIL_FAILED', 'The notification email could not be prepared.');
        }
        $this->finishDelivery($deliveryId, $sent);
        if (!$sent) {
            throw new NotificationException('MAIL_FAILED', 'The notification email could not be accepted by the server mail transport.');
        }
    }

    /** @return list<array{pin_number:int,events:list<array{id:string,type:string,created_at:string}>}> */
    private function notificationItems(StorageInterface $storage, string $projectKey, string $pageUrl, string $senderAuthorId): array
    {
        $items = [];
        foreach (array_reverse($storage->listPins($projectKey, $pageUrl)) as $pin) {
            $pinNumber = (int) ($pin['pin_number'] ?? 0);
            $pinId = (string) ($pin['id'] ?? '');
            if ($pinNumber < 1 || $pinId === '') continue;
            $fullPin = $storage->getPin($pinId, $projectKey);
            if ($fullPin === null) continue;
            $messages = isset($fullPin['messages']) && is_array($fullPin['messages']) ? $fullPin['messages'] : [];
            $pinCreatedBySender = hash_equals((string) ($fullPin['author_id'] ?? ''), $senderAuthorId);
            $events = [];
            foreach ($messages as $messageIndex => $message) {
                if (!hash_equals((string) ($message['author_id'] ?? ''), $senderAuthorId)) continue;
                $isInitialPinComment = $pinCreatedBySender
                    && $messageIndex === 0
                    && (string) ($message['created_at'] ?? '') === (string) ($fullPin['created_at'] ?? '');
                if ($isInitialPinComment) continue;
                $events[] = [
                    'id' => (string) ($message['id'] ?? 'message-' . $messageIndex),
                    'type' => 'comment',
                    'created_at' => (string) ($message['created_at'] ?? ''),
                ];
            }
            foreach ($storage->listPinStatusEvents($pinId, $projectKey) as $eventIndex => $event) {
                if (!hash_equals((string) ($event['author_id'] ?? ''), $senderAuthorId)) continue;
                $events[] = [
                    'id' => (string) ($event['id'] ?? 'status-' . $eventIndex),
                    'type' => (string) ($event['status'] ?? '') === 'resolved' ? 'solved' : 'reopened',
                    'created_at' => (string) ($event['created_at'] ?? ''),
                ];
            }
            usort($events, static fn (array $a, array $b): int =>
                [$a['created_at'], $a['id']] <=> [$b['created_at'], $b['id']]
            );
            if (!$pinCreatedBySender && $events === []) continue;
            $items[] = ['pin_number' => $pinNumber, 'events' => $events];
        }
        return $items;
    }

    /** @return array<string, mixed> */
    private function claimProfile(StorageInterface $storage, string $projectKey, string $authorId, string $authorSecret, string $language): array
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $authorSecret) !== 1) {
            throw new NotificationException('NOTIFICATION_IDENTITY_DENIED', 'The browser identity secret is invalid.');
        }
        $language = in_array($language, ['pl', 'en'], true) ? $language : 'en';
        $authorId = $this->resolveAuthorId($projectKey, $authorId);
        $user = null;
        foreach ($storage->listProjectUserRecords($projectKey) as $candidate) {
            if (hash_equals((string) $candidate['author_id'], $authorId)) {
                $user = $candidate;
                break;
            }
        }
        if ($user === null) {
            throw new NotificationException('PROFILE_UNAVAILABLE', 'Add a pin or comment before enabling email notifications.');
        }
        $identityHash = hash('sha256', $authorSecret);
        return $this->mutate(function (array &$data) use ($projectKey, $authorId, $identityHash, $language, $user): array {
            $index = $this->profileIndex($data, $projectKey, $authorId);
            if ($index !== null) {
                $identityHashes = $this->profileIdentityHashes($data['profiles'][$index]);
                if ($identityHashes !== [] && !in_array($identityHash, $identityHashes, true)) {
                    throw new NotificationException('NOTIFICATION_IDENTITY_DENIED', 'This notification profile belongs to another browser identity.');
                }
                $data['profiles'][$index]['identity_hashes'] = array_values(array_unique([...$identityHashes, $identityHash]));
                unset($data['profiles'][$index]['identity_hash']);
                $data['profiles'][$index]['author_name'] = (string) $user['author_name'];
                $data['profiles'][$index]['language'] = $language;
                $data['profiles'][$index]['updated_at'] = gmdate('c');
                return $data['profiles'][$index];
            }
            $now = gmdate('c');
            $profile = [
                'project_key' => $projectKey,
                'author_id' => $authorId,
                'author_name' => (string) $user['author_name'],
                'recipient_id' => uuidV4(),
                'identity_hashes' => [$identityHash],
                'language' => $language,
                'email_ciphertext' => '',
                'email_verified_at' => '',
                'pending_email_ciphertext' => '',
                'verification_token_hash' => '',
                'verification_expires_at' => '',
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $data['profiles'][] = $profile;
            return $profile;
        });
    }

    /** @param array<string, mixed> $profile @return array<string, mixed> */
    private function publicSettings(array $profile): array
    {
        $verified = (string) ($profile['email_ciphertext'] ?? '') !== '' && (string) ($profile['email_verified_at'] ?? '') !== '';
        $pending = (string) ($profile['pending_email_ciphertext'] ?? '') !== '' && (strtotime((string) ($profile['verification_expires_at'] ?? '')) ?: 0) >= time();
        return [
            'email_verified' => $verified,
            'email_masked' => $verified ? $this->maskEmail($this->decrypt((string) $profile['email_ciphertext'])) : '',
            'verification_pending' => $pending,
            'pending_email_masked' => $pending ? $this->maskEmail($this->decrypt((string) $profile['pending_email_ciphertext'])) : '',
            'language' => (string) ($profile['language'] ?? 'en'),
            'canonical_author_id' => (string) ($profile['author_id'] ?? ''),
            'author_name' => (string) ($profile['author_name'] ?? ''),
            'linked_devices' => count($this->profileIdentityHashes($profile)),
        ];
    }

    /** @return array<string, mixed>|null */
    private function readProfileByRecipientId(string $projectKey, string $recipientId): ?array
    {
        $data = $this->read();
        foreach ($data['profiles'] as $profile) {
            if ((string) ($profile['project_key'] ?? '') === $projectKey
                && hash_equals((string) ($profile['recipient_id'] ?? ''), $recipientId)) {
                return $profile;
            }
        }
        return null;
    }

    private function reserveDelivery(string $projectKey, string $authorId, string $recipientId): string
    {
        return $this->mutate(function (array &$data) use ($projectKey, $authorId, $recipientId): string {
            $now = time();
            $hourCutoff = $now - 3600;
            $dayCutoff = $now - 86400;
            $cooldown = max(0, (int) $this->config['NOTIFICATION_COOLDOWN_SECONDS']);
            $hourly = 0;
            $daily = 0;
            $recipientDaily = 0;
            $ipHourly = 0;
            $ipDaily = 0;
            $ipHash = $this->ipHash();
            foreach ($data['deliveries'] as $delivery) {
                $timestamp = strtotime((string) ($delivery['created_at'] ?? '')) ?: 0;
                if ($timestamp <= $dayCutoff) continue;
                if (hash_equals((string) ($delivery['ip_hash'] ?? ''), $ipHash)) {
                    $ipDaily++;
                    if ($timestamp > $hourCutoff) $ipHourly++;
                }
                if ((string) ($delivery['project_key'] ?? '') !== $projectKey) continue;
                if ((string) ($delivery['author_id'] ?? '') === $authorId) {
                    $daily++;
                    if ($timestamp > $hourCutoff) $hourly++;
                    if ((string) ($delivery['recipient_id'] ?? '') === $recipientId && $timestamp > $now - $cooldown) {
                        throw new NotificationException('NOTIFICATION_RATE_LIMITED', 'This recipient was notified recently.');
                    }
                }
                if ((string) ($delivery['recipient_id'] ?? '') === $recipientId) {
                    $recipientDaily++;
                }
            }
            if ((int) $this->config['NOTIFICATION_HOURLY_LIMIT'] > 0 && $hourly >= (int) $this->config['NOTIFICATION_HOURLY_LIMIT']) {
                throw new NotificationException('NOTIFICATION_RATE_LIMITED', 'The hourly notification limit has been reached.');
            }
            if ((int) $this->config['NOTIFICATION_DAILY_LIMIT'] > 0 && $daily >= (int) $this->config['NOTIFICATION_DAILY_LIMIT']) {
                throw new NotificationException('NOTIFICATION_RATE_LIMITED', 'The daily notification limit has been reached.');
            }
            if ((int) $this->config['NOTIFICATION_RECIPIENT_DAILY_LIMIT'] > 0 && $recipientDaily >= (int) $this->config['NOTIFICATION_RECIPIENT_DAILY_LIMIT']) {
                throw new NotificationException('NOTIFICATION_RATE_LIMITED', 'The recipient notification limit has been reached.');
            }
            if ((int) $this->config['NOTIFICATION_IP_HOURLY_LIMIT'] > 0 && $ipHourly >= (int) $this->config['NOTIFICATION_IP_HOURLY_LIMIT']) {
                throw new NotificationException('NOTIFICATION_RATE_LIMITED', 'The hourly server email limit has been reached.');
            }
            if ((int) $this->config['NOTIFICATION_IP_DAILY_LIMIT'] > 0 && $ipDaily >= (int) $this->config['NOTIFICATION_IP_DAILY_LIMIT']) {
                throw new NotificationException('NOTIFICATION_RATE_LIMITED', 'The daily server email limit has been reached.');
            }
            $id = uuidV4();
            $data['deliveries'][] = [
                'id' => $id,
                'project_key' => $projectKey,
                'author_id' => $authorId,
                'recipient_id' => $recipientId,
                'ip_hash' => $ipHash,
                'created_at' => gmdate('c'),
                'status' => 'reserved',
            ];
            return $id;
        });
    }

    private function finishDelivery(string $id, bool $sent): void
    {
        $this->mutate(static function (array &$data) use ($id, $sent): void {
            foreach ($data['deliveries'] as $index => &$delivery) {
                if (!hash_equals((string) ($delivery['id'] ?? ''), $id)) continue;
                if ($sent) {
                    $delivery['status'] = 'sent';
                    $delivery['sent_at'] = gmdate('c');
                } else {
                    unset($data['deliveries'][$index]);
                    $data['deliveries'] = array_values($data['deliveries']);
                }
                unset($delivery);
                return;
            }
            unset($delivery);
        });
    }

    /** @param array<string, mixed> $data */
    private function assertVerificationLimit(array $data, string $projectKey, string $authorId): void
    {
        $now = time();
        $cooldown = max(0, (int) $this->config['EMAIL_VERIFICATION_COOLDOWN_SECONDS']);
        $daily = 0;
        $ipDaily = 0;
        $ipHash = $this->ipHash();
        foreach ($data['verification_attempts'] as $attempt) {
            $timestamp = strtotime((string) ($attempt['created_at'] ?? '')) ?: 0;
            if ($timestamp <= $now - 86400) continue;
            if (hash_equals((string) ($attempt['ip_hash'] ?? ''), $ipHash)) $ipDaily++;
            if ((string) ($attempt['project_key'] ?? '') !== $projectKey || (string) ($attempt['author_id'] ?? '') !== $authorId) continue;
            $daily++;
            if ($timestamp > $now - $cooldown) {
                throw new NotificationException('NOTIFICATION_RATE_LIMITED', 'A verification email was sent recently.');
            }
        }
        if ((int) $this->config['EMAIL_VERIFICATION_DAILY_LIMIT'] > 0 && $daily >= (int) $this->config['EMAIL_VERIFICATION_DAILY_LIMIT']) {
            throw new NotificationException('NOTIFICATION_RATE_LIMITED', 'The daily email verification limit has been reached.');
        }
        if ((int) $this->config['EMAIL_VERIFICATION_IP_DAILY_LIMIT'] > 0 && $ipDaily >= (int) $this->config['EMAIL_VERIFICATION_IP_DAILY_LIMIT']) {
            throw new NotificationException('NOTIFICATION_RATE_LIMITED', 'The daily server verification limit has been reached.');
        }
    }

    private function finishVerificationAttempt(string $id, bool $sent, string $projectKey, string $authorId, string $tokenHash): void
    {
        $this->mutate(function (array &$data) use ($id, $sent, $projectKey, $authorId, $tokenHash): void {
            foreach ($data['verification_attempts'] as $index => &$attempt) {
                if (!hash_equals((string) ($attempt['id'] ?? ''), $id)) continue;
                if ($sent) {
                    $attempt['status'] = 'sent';
                } else {
                    unset($data['verification_attempts'][$index]);
                    $data['verification_attempts'] = array_values($data['verification_attempts']);
                    $profileIndex = $this->profileIndex($data, $projectKey, $authorId);
                    if ($profileIndex !== null && hash_equals((string) ($data['profiles'][$profileIndex]['verification_token_hash'] ?? ''), $tokenHash)) {
                        $data['profiles'][$profileIndex]['pending_email_ciphertext'] = '';
                        $data['profiles'][$profileIndex]['verification_token_hash'] = '';
                        $data['profiles'][$profileIndex]['verification_expires_at'] = '';
                    }
                }
                unset($attempt);
                return;
            }
            unset($attempt);
        });
    }

    /** @param array<string, mixed> $profile @return array<int, string> */
    private function profileIdentityHashes(array $profile): array
    {
        $hashes = isset($profile['identity_hashes']) && is_array($profile['identity_hashes'])
            ? $profile['identity_hashes']
            : [];
        if (isset($profile['identity_hash']) && is_string($profile['identity_hash'])) {
            $hashes[] = $profile['identity_hash'];
        }
        return array_values(array_unique(array_filter(
            array_map('strval', $hashes),
            static fn (string $hash): bool => preg_match('/^[0-9a-f]{64}$/D', $hash) === 1
        )));
    }

    /** @param array<string, mixed> $data */
    private function resolveAuthorIdInData(array $data, string $projectKey, string $authorId): string
    {
        $current = $authorId;
        $visited = [$current => true];
        for ($depth = 0; $depth < 20; $depth++) {
            $next = '';
            foreach ($data['aliases'] as $alias) {
                if ((string) ($alias['project_key'] ?? '') === $projectKey
                    && hash_equals((string) ($alias['source_author_id'] ?? ''), $current)) {
                    $next = (string) ($alias['canonical_author_id'] ?? '');
                    break;
                }
            }
            if ($next === '' || isset($visited[$next]) || preg_match('/^[0-9a-f-]{36}$/D', $next) !== 1) break;
            $visited[$next] = true;
            $current = $next;
        }
        return $current;
    }

    /** @param array<string, mixed> $data */
    private function upsertAlias(array &$data, string $projectKey, string $sourceAuthorId, string $canonicalAuthorId): void
    {
        if (hash_equals($sourceAuthorId, $canonicalAuthorId)) return;
        foreach ($data['aliases'] as &$alias) {
            if ((string) ($alias['project_key'] ?? '') !== $projectKey) continue;
            if (hash_equals((string) ($alias['canonical_author_id'] ?? ''), $sourceAuthorId)) {
                $alias['canonical_author_id'] = $canonicalAuthorId;
            }
            if (hash_equals((string) ($alias['source_author_id'] ?? ''), $sourceAuthorId)) {
                $alias['canonical_author_id'] = $canonicalAuthorId;
                $alias['updated_at'] = gmdate('c');
                $alias['storage_merged_at'] = '';
                unset($alias);
                return;
            }
        }
        unset($alias);
        $data['aliases'][] = [
            'project_key' => $projectKey,
            'source_author_id' => $sourceAuthorId,
            'canonical_author_id' => $canonicalAuthorId,
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
            'storage_merged_at' => '',
        ];
    }

    /** @param array<string, mixed> $data */
    private function profileIndex(array $data, string $projectKey, string $authorId): ?int
    {
        foreach ($data['profiles'] as $index => $profile) {
            if ((string) ($profile['project_key'] ?? '') === $projectKey && (string) ($profile['author_id'] ?? '') === $authorId) {
                return $index;
            }
        }
        return null;
    }

    private function encrypt(string $plaintext): string
    {
        $key = $this->encryptionKey();
        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            return 's1.' . $this->base64UrlEncode($nonce . sodium_crypto_secretbox($plaintext, $nonce, $key));
        }
        if (function_exists('openssl_encrypt')) {
            $nonce = random_bytes(12);
            $tag = '';
            $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
            if (!is_string($ciphertext)) throw new RuntimeException('Email encryption failed.');
            return 'o1.' . $this->base64UrlEncode($nonce . $tag . $ciphertext);
        }
        throw new RuntimeException('Authenticated encryption is unavailable.');
    }

    private function decrypt(string $payload): string
    {
        $key = $this->encryptionKey();
        if (str_starts_with($payload, 's1.') && function_exists('sodium_crypto_secretbox_open')) {
            $decoded = $this->base64UrlDecode(substr($payload, 3));
            $nonceLength = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
            $plaintext = sodium_crypto_secretbox_open(substr($decoded, $nonceLength), substr($decoded, 0, $nonceLength), $key);
            if (!is_string($plaintext)) throw new RuntimeException('Email decryption failed.');
            return $plaintext;
        }
        if (str_starts_with($payload, 'o1.') && function_exists('openssl_decrypt')) {
            $decoded = $this->base64UrlDecode(substr($payload, 3));
            $nonce = substr($decoded, 0, 12);
            $tag = substr($decoded, 12, 16);
            $plaintext = openssl_decrypt(substr($decoded, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
            if (!is_string($plaintext)) throw new RuntimeException('Email decryption failed.');
            return $plaintext;
        }
        throw new RuntimeException('The encrypted email format is unsupported.');
    }

    private function encryptionKey(): string
    {
        $configured = trim((string) ($this->config['NOTIFICATION_ENCRYPTION_KEY'] ?? ''));
        if ($configured !== '') {
            $decoded = base64_decode($configured, true);
            if (is_string($decoded) && strlen($decoded) === 32) return $decoded;
            if (preg_match('/^[0-9a-f]{64}$/Di', $configured) === 1) return hex2bin($configured);
            throw new RuntimeException('NOTIFICATION_ENCRYPTION_KEY must contain 32 bytes encoded as base64 or hexadecimal.');
        }
        if (is_file($this->keyPath)) {
            $raw = trim((string) file_get_contents($this->keyPath));
            $decoded = base64_decode($raw, true);
            if (is_string($decoded) && strlen($decoded) === 32) return $decoded;
            throw new RuntimeException('The notification encryption key file is invalid.');
        }
        if (!is_dir($this->dataDirectory) || !is_writable($this->dataDirectory)) {
            throw new RuntimeException('The notification key directory is not writable.');
        }
        $key = random_bytes(32);
        if (file_put_contents($this->keyPath, base64_encode($key), LOCK_EX) === false) {
            throw new RuntimeException('Unable to create the notification encryption key.');
        }
        @chmod($this->keyPath, 0600);
        return $key;
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        if ($local === '' || $domain === '') return '';
        return substr($local, 0, 1) . str_repeat('*', max(3, min(8, strlen($local) - 1))) . '@' . $domain;
    }

    private function assertAvailable(): void
    {
        if (!$this->isAvailable()) {
            throw new NotificationException('NOTIFICATIONS_UNAVAILABLE', 'Server email notifications are unavailable.');
        }
    }

    private function randomToken(): string
    {
        return $this->base64UrlEncode(random_bytes(32));
    }

    private function ipHash(): string
    {
        return hash_hmac('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), $this->encryptionKey());
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if (!is_string($decoded)) throw new RuntimeException('Invalid encrypted data encoding.');
        return $decoded;
    }

    /** @return array<string, mixed> */
    private function read(): array
    {
        return $this->withLock(LOCK_SH, fn (): array => $this->readData());
    }

    /** @template T @param callable(array<string,mixed>&):T $callback @return T */
    private function mutate(callable $callback): mixed
    {
        return $this->withLock(LOCK_EX, function () use ($callback): mixed {
            $data = $this->readData();
            $this->prune($data);
            $result = $callback($data);
            $this->writeData($data);
            return $result;
        });
    }

    /** @template T @param callable():T $callback @return T */
    private function withLock(int $operation, callable $callback): mixed
    {
        $handle = fopen($this->lockPath, 'c+');
        if ($handle === false) throw new RuntimeException('Unable to open the notification store lock.');
        try {
            if (!flock($handle, $operation)) throw new RuntimeException('Unable to lock the notification store.');
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
            return ['format_version' => 2, 'profiles' => [], 'aliases' => [], 'deliveries' => [], 'verification_attempts' => []];
        }
        $raw = file_get_contents($this->path);
        if ($raw === false) throw new RuntimeException('Unable to read the notification store.');
        $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new RuntimeException('The notification store is invalid.');
        foreach (['profiles', 'aliases', 'deliveries', 'verification_attempts'] as $field) {
            $data[$field] = isset($data[$field]) && is_array($data[$field]) ? array_values($data[$field]) : [];
        }
        foreach ($data['profiles'] as &$profile) {
            $profile['identity_hashes'] = $this->profileIdentityHashes($profile);
            unset($profile['identity_hash']);
        }
        unset($profile);
        $data['format_version'] = 2;
        return $data;
    }

    /** @param array<string, mixed> $data */
    private function prune(array &$data): void
    {
        $deliveryCutoff = time() - 172800;
        $verificationCutoff = time() - 86400;
        $data['deliveries'] = array_values(array_filter($data['deliveries'], static fn (array $entry): bool =>
            (strtotime((string) ($entry['created_at'] ?? '')) ?: 0) > $deliveryCutoff
        ));
        $data['verification_attempts'] = array_values(array_filter($data['verification_attempts'], static fn (array $entry): bool =>
            (strtotime((string) ($entry['created_at'] ?? '')) ?: 0) > $verificationCutoff
        ));
    }

    /** @param array<string, mixed> $data */
    private function writeData(array $data): void
    {
        $temporary = $this->dataDirectory . '/.notifications.' . bin2hex(random_bytes(8)) . '.tmp';
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents($temporary, $encoded, LOCK_EX) === false) throw new RuntimeException('Unable to write the notification store.');
        @chmod($temporary, 0600);
        if (!rename($temporary, $this->path)) {
            if (is_file($temporary)) unlink($temporary);
            throw new RuntimeException('Unable to replace the notification store atomically.');
        }
    }
}

final class NotificationException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
