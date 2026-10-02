<?php

return [
    'token' => env('TELEGRAM_BOT_TOKEN') ?: null,

    'safe_mode' => false,

    'config' => [
        'bot_name' => env('TELEGRAM_BOT_USERNAME', 'ccat_booking_bot'),
    ],

    'routes' => true,

    'mixins' => false,

    'namespace' => app_path('Telegram'),

    'log_channel' => env('TELEGRAM_LOG_CHANNEL', 'null'),
];
