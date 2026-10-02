<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Telegram\TelegramWebhook;
use Illuminate\Console\Command;
use SergiX44\Nutgram\Nutgram;
use Throwable;

final class TelegramWebhookCommand extends Command
{
    protected $signature = 'telegram:webhook';

    protected $description = 'Register the Telegram bot webhook and menu commands';

    public function handle(Nutgram $bot): int
    {
        if (blank(config('nutgram.token'))) {
            $this->warn('TELEGRAM_BOT_TOKEN is not set; the bot is left as it is.');

            return self::SUCCESS;
        }

        try {
            $bot->setWebhook(
                url: TelegramWebhook::url(),
                allowed_updates: ['message', 'callback_query'],
                secret_token: TelegramWebhook::secret(),
            );
            $bot->registerMyCommands();
        } catch (Throwable $exception) {
            $this->error('Telegram refused the webhook: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Telegram delivers updates to '.TelegramWebhook::url());

        return self::SUCCESS;
    }
}
