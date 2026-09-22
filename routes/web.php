<?php

declare(strict_types=1);

use App\Http\Controllers\TelegramWebhookController;
use App\Services\CcatBookingService;
use App\Services\TelegramService;
use Illuminate\Support\Facades\Route;

Route::post('/api/telegram/webhook', [TelegramWebhookController::class, 'handle'])
    ->name('telegram.webhook');

Route::get('/', function (TelegramService $telegram, CcatBookingService $ccat) {
    return response()->json([
        'service' => 'Centre for Contemporary Art Tashkent - Telegram Bot Service',
        'status' => 'active',
        'bot_username' => config('telegram.bot_username'),
        'bot_configured' => $telegram->isConfigured(),
        'ccat_api_url' => config('telegram.ccat_api_url'),
        'commands' => [
            'poll' => 'php artisan telegram:poll',
            'webhook' => 'php artisan telegram:webhook {action=info|set|delete} {--url=}',
            'check_booking' => 'php artisan booking:check {token}',
        ],
        'version' => '1.0.0',
    ]);
});
