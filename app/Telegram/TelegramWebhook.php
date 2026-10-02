<?php

declare(strict_types=1);

namespace App\Telegram;

final class TelegramWebhook
{
    public static function url(): string
    {
        return route('telegram.webhook');
    }

    public static function secret(): string
    {
        return hash_hmac('sha256', 'telegram-webhook', (string) config('app.key'));
    }
}
