<?php

declare(strict_types=1);

return [
    'PROJECT_ACCESS_CODE' => '',
    'ADMIN_ACCESS_CODE' => '',
    'ALLOW_GUESTS' => true,
    'ALLOW_AUTHOR_DELETE_OWN_MESSAGES' => true,
    'ALLOW_AUTHOR_DELETE_OWN_PINS' => true,
    'ALLOW_ADMIN_WITHOUT_CODE' => false,
    'CREATE_BACKUP_BEFORE_PURGE' => true,
    'STORAGE_MODE' => 'auto',
    'MOBILE_BREAKPOINT' => 600,
    'DESKTOP_BREAKPOINT' => 1024,
    'MAX_NAME_LENGTH' => 80,
    'MAX_MESSAGE_LENGTH' => 5000,
    'RATE_LIMIT_REQUESTS' => 30,
    'RATE_LIMIT_WINDOW_SECONDS' => 60,
    'PERSISTENT_RATE_LIMIT' => false,
    'MAX_PINS_PER_AUTHOR' => 0,
    'MAX_MESSAGES_PER_PIN' => 0,
    'MAX_TOTAL_PINS' => 0,
    'MAX_TOTAL_MESSAGES' => 0,
    'MAX_BACKUPS' => 0,
];
