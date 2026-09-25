<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\TelegramService;
use Illuminate\Console\Command;

class TelegramWebhookCommand extends Command
{
    protected $signature = 'telegram:webhook 
                            {action=info : Action to perform (info, set, delete)}
                            {--url= : Webhook URL for set action}';

    protected $description = 'Manage Telegram Bot webhook';

    public function handle(TelegramService $telegram): int
    {
        if (! $telegram->isConfigured()) {
            $this->error('TELEGRAM_BOT_TOKEN is not configured in .env.');

            return self::FAILURE;
        }

        $action = $this->argument('action');

        return match ($action) {
            'set' => $this->setWebhook($telegram),
            'delete' => $this->deleteWebhook($telegram),
            default => $this->showInfo($telegram),
        };
    }

    private function setWebhook(TelegramService $telegram): int
    {
        $url = (string) $this->option('url');

        if (empty($url)) {
            $this->error('Please provide a URL using --url=https://your-domain.com/api/telegram/webhook');

            return self::FAILURE;
        }

        $result = $telegram->setWebhook($url);

        if ($result && ($result['ok'] ?? false)) {
            $this->info("Webhook set successfully to: {$url}");

            return self::SUCCESS;
        }

        $this->error('Failed to set webhook: '.($result['description'] ?? 'Unknown error'));

        return self::FAILURE;
    }

    private function deleteWebhook(TelegramService $telegram): int
    {
        $result = $telegram->deleteWebhook();

        if ($result && ($result['ok'] ?? false)) {
            $this->info('Webhook deleted successfully. You can now use polling.');

            return self::SUCCESS;
        }

        $this->error('Failed to delete webhook.');

        return self::FAILURE;
    }

    private function showInfo(TelegramService $telegram): int
    {
        $info = $telegram->getWebhookInfo();

        if (! $info || ! ($info['ok'] ?? false)) {
            $this->error('Failed to get webhook info.');

            return self::FAILURE;
        }

        $data = $info['result'];
        $this->info('Telegram Webhook Info:');
        $this->table(
            ['Property', 'Value'],
            [
                ['URL', $data['url'] ?: '(None - Polling Mode)'],
                ['Custom Certificate', $data['has_custom_certificate'] ? 'Yes' : 'No'],
                ['Pending Updates', $data['pending_update_count'] ?? 0],
                ['Last Error Date', isset($data['last_error_date']) ? date('Y-m-d H:i:s', $data['last_error_date']) : 'None'],
                ['Last Error Message', $data['last_error_message'] ?? 'None'],
            ]
        );

        return self::SUCCESS;
    }
}
