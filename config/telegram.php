<?php

declare(strict_types=1);

return [
    'bot_token' => env('TELEGRAM_BOT_TOKEN', ''),
    'bot_username' => env('TELEGRAM_BOT_USERNAME', 'ccat_booking_bot'),
    'api_base' => env('TELEGRAM_API_BASE', 'https://api.telegram.org'),

    'ccat_api_url' => rtrim(env('CCAT_API_URL', 'http://localhost:8000/api/bot'), '/'),
    'ccat_api_token' => env('CCAT_API_TOKEN', ''),
];
