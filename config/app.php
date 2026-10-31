<?php
declare(strict_types=1);

return [
    'environment' => getenv('APP_ENV') ?: 'development',
    'app_secret' => getenv('APP_SECRET') ?: '',
    'session_secure' => filter_var(getenv('SESSION_SECURE') ?: '0', FILTER_VALIDATE_BOOL),
    'product_image_max_count' => filter_var(getenv('PRODUCT_IMAGE_MAX_COUNT') ?: '5', FILTER_VALIDATE_INT),
    'product_image_max_bytes' => filter_var(getenv('PRODUCT_IMAGE_MAX_BYTES') ?: '5242880', FILTER_VALIDATE_INT),
    'database' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => getenv('DB_PORT') ?: '3306',
        'name' => getenv('DB_NAME') ?: 'second_story',
        'user' => getenv('DB_USER') ?: 'second_story_app',
        'password' => getenv('DB_PASSWORD') ?: '',
    ],
];
