<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\TelegramBotUpdateHandler;
use App\Services\TelegramService;
use Illuminate\Console\Command;

class TelegramPollCommand extends Command
{
    protected $signature = 'telegram:poll {--timeout=30 : Long-polling timeout in seconds}';

    protected $description = 'Start long polling for incoming Telegram updates';

    public function handle(TelegramService $telegram, TelegramBotUpdateHandler $handler): int
    {
        if (! $telegram->isConfigured()) {
            $this->error('TELEGRAM_BOT_TOKEN is not configured in your .env file.');
            $this->line('Please add TELEGRAM_BOT_TOKEN=your_token to your .env file.');

            return self::FAILURE;
        }

        $bot = $telegram->getMe();
        if (! $bot || ! ($bot['ok'] ?? false)) {
            $this->error('Failed to connect to Telegram Bot API. Please verify your TELEGRAM_BOT_TOKEN.');

            return self::FAILURE;
        }

        $botInfo = $bot['result'];
        $this->info("🤖 Bot connected: @{$botInfo['username']} ({$botInfo['first_name']})");
        $this->info("👂 Listening for updates... Press Ctrl+C to stop.\n");

        $offset = 0;
        $timeout = (int) $this->option('timeout');

        while (true) {
            try {
                $updates = $telegram->getUpdates($offset, limit: 100, timeout: $timeout);

                foreach ($updates as $update) {
                    $updateId = (int) $update['update_id'];
                    $offset = $updateId + 1;

                    if (isset($update['message'])) {
                        $from = $update['message']['from']['first_name'] ?? 'User';
                        $text = $update['message']['text'] ?? '[media]';
                        $this->line(sprintf(
                            '[%s] 💬 Message from %s: %s',
                            now()->format('H:i:s'),
                            $from,
                            $text
                        ));
                    } elseif (isset($update['callback_query'])) {
                        $from = $update['callback_query']['from']['first_name'] ?? 'User';
                        $data = $update['callback_query']['data'] ?? '';
                        $this->line(sprintf(
                            '[%s] 🔘 Button click by %s: %s',
                            now()->format('H:i:s'),
                            $from,
                            $data
                        ));
                    }

                    try {
                        $handler->handle($update);
                    } catch (\Throwable $ex) {
                        $this->error('Error processing update: '.$ex->getMessage());
                    }
                }
            } catch (\Throwable $e) {
                $this->warn('Error during polling: '.$e->getMessage());
                sleep(2);
            }
        }

        return self::SUCCESS;
    }
}
