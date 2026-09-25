<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    private string $botToken;

    private string $apiUrl;

    public function __construct()
    {
        $this->botToken = (string) config('telegram.bot_token', '');
        $apiBase = rtrim((string) config('telegram.api_base', 'https://api.telegram.org'), '/');
        $this->apiUrl = "{$apiBase}/bot{$this->botToken}";
    }

    public function isConfigured(): bool
    {
        return ! empty($this->botToken);
    }

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     */
    public function sendMessage(
        int|string $chatId,
        string $text,
        ?array $replyMarkup = null,
        string $parseMode = 'HTML'
    ): ?array {
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => $parseMode,
            'disable_web_page_preview' => true,
        ];

        if ($replyMarkup !== null) {
            $payload['reply_markup'] = json_encode($replyMarkup);
        }

        $result = $this->post('sendMessage', $payload);

        if ($result !== null) {
            return $result;
        }

        if (! empty($parseMode)) {
            Log::warning('Telegram sendMessage failed with parse_mode, falling back to plain text');
            $plainText = trim(strip_tags(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            $fallbackPayload = [
                'chat_id' => $chatId,
                'text' => $plainText,
                'disable_web_page_preview' => true,
            ];

            if ($replyMarkup !== null) {
                $fallbackPayload['reply_markup'] = json_encode($replyMarkup);
            }

            return $this->post('sendMessage', $fallbackPayload);
        }

        return null;
    }

    /**
     * @param  string  $photoData  Binary PNG content or photo URL
     * @param  array<string, mixed>|null  $replyMarkup
     */
    public function sendPhoto(
        int|string $chatId,
        string $photoData,
        string $filename = 'ccat-qr.png',
        string $caption = '',
        ?array $replyMarkup = null,
        string $parseMode = 'HTML'
    ): ?array {
        if (filter_var($photoData, FILTER_VALIDATE_URL)) {
            $payload = [
                'chat_id' => $chatId,
                'photo' => $photoData,
                'caption' => $caption,
                'parse_mode' => $parseMode,
            ];

            if ($replyMarkup !== null) {
                $payload['reply_markup'] = json_encode($replyMarkup);
            }

            $result = $this->post('sendPhoto', $payload);
            if ($result !== null) {
                return $result;
            }

            if (! empty($parseMode)) {
                $payload['caption'] = trim(strip_tags(html_entity_decode($caption, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                unset($payload['parse_mode']);

                return $this->post('sendPhoto', $payload);
            }

            return null;
        }

        $request = Http::timeout(30)->asMultipart();

        $data = [
            ['name' => 'chat_id', 'contents' => (string) $chatId],
            ['name' => 'caption', 'contents' => $caption],
            ['name' => 'parse_mode', 'contents' => $parseMode],
            [
                'name' => 'photo',
                'contents' => $photoData,
                'filename' => $filename,
                'headers' => ['Content-Type' => 'image/png'],
            ],
        ];

        if ($replyMarkup !== null) {
            $data[] = ['name' => 'reply_markup', 'contents' => json_encode($replyMarkup)];
        }

        try {
            $response = $request->post("{$this->apiUrl}/sendPhoto", $data);

            if ($response->successful()) {
                return $response->json();
            }

            $responseBody = $response->body();
            Log::error('Telegram sendPhoto failed', [
                'status' => $response->status(),
                'body' => $responseBody,
            ]);

            if (! empty($parseMode)) {
                Log::warning('Telegram sendPhoto failed with parse_mode, falling back to plain text');
                $plainCaption = trim(strip_tags(html_entity_decode($caption, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

                $fallbackData = [
                    ['name' => 'chat_id', 'contents' => (string) $chatId],
                    ['name' => 'caption', 'contents' => $plainCaption],
                    [
                        'name' => 'photo',
                        'contents' => $photoData,
                        'filename' => $filename,
                        'headers' => ['Content-Type' => 'image/png'],
                    ],
                ];

                if ($replyMarkup !== null) {
                    $fallbackData[] = ['name' => 'reply_markup', 'contents' => json_encode($replyMarkup)];
                }

                $fallbackResponse = Http::timeout(30)->asMultipart()->post("{$this->apiUrl}/sendPhoto", $fallbackData);

                if ($fallbackResponse->successful()) {
                    return $fallbackResponse->json();
                }

                Log::error('Telegram sendPhoto plain fallback failed', [
                    'status' => $fallbackResponse->status(),
                    'body' => $fallbackResponse->body(),
                ]);
            }

            return null;
        } catch (\Throwable $e) {
            Log::error('Telegram sendPhoto exception: '.$e->getMessage());

            return null;
        }
    }

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     */
    public function editMessageText(
        int|string $chatId,
        int $messageId,
        string $text,
        ?array $replyMarkup = null,
        string $parseMode = 'HTML'
    ): ?array {
        $payload = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => $parseMode,
            'disable_web_page_preview' => true,
        ];

        if ($replyMarkup !== null) {
            $payload['reply_markup'] = json_encode($replyMarkup);
        }

        return $this->post('editMessageText', $payload);
    }

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     */
    public function editMessageCaption(
        int|string $chatId,
        int $messageId,
        string $caption,
        ?array $replyMarkup = null,
        string $parseMode = 'HTML'
    ): ?array {
        $payload = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'caption' => $caption,
            'parse_mode' => $parseMode,
        ];

        if ($replyMarkup !== null) {
            $payload['reply_markup'] = json_encode($replyMarkup);
        }

        return $this->post('editMessageCaption', $payload);
    }

    public function answerCallbackQuery(
        string $callbackQueryId,
        ?string $text = null,
        bool $showAlert = false
    ): ?array {
        $payload = [
            'callback_query_id' => $callbackQueryId,
            'show_alert' => $showAlert,
        ];

        if ($text !== null) {
            $payload['text'] = $text;
        }

        return $this->post('answerCallbackQuery', $payload);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getUpdates(int $offset = 0, int $limit = 100, int $timeout = 30): array
    {
        $response = $this->post('getUpdates', [
            'offset' => $offset,
            'limit' => $limit,
            'timeout' => $timeout,
        ], timeout: $timeout + 10);

        if ($response && ($response['ok'] ?? false)) {
            return $response['result'] ?? [];
        }

        return [];
    }

    public function getMe(): ?array
    {
        return $this->post('getMe', []);
    }

    public function setWebhook(string $url): ?array
    {
        return $this->post('setWebhook', [
            'url' => $url,
            'drop_pending_updates' => false,
        ]);
    }

    public function deleteWebhook(): ?array
    {
        return $this->post('deleteWebhook', [
            'drop_pending_updates' => false,
        ]);
    }

    public function getWebhookInfo(): ?array
    {
        return $this->post('getWebhookInfo', []);
    }

    /**
     * @param  array<int, array{command: string, description: string}>  $commands
     */
    public function setMyCommands(array $commands, ?string $languageCode = null): ?array
    {
        $payload = [
            'commands' => $commands,
        ];

        if (! empty($languageCode)) {
            $payload['language_code'] = $languageCode;
        }

        return $this->post('setMyCommands', $payload);
    }

    public function registerBotCommands(): void
    {
        $commandsEn = [
            ['command' => 'mybookings', 'description' => '📋 View my active bookings'],
            ['command' => 'language', 'description' => '🌐 Change language / Сменить язык'],
            ['command' => 'help', 'description' => 'ℹ️ How to use this bot'],
            ['command' => 'start', 'description' => '🚀 Start / View bookings'],
        ];

        $commandsRu = [
            ['command' => 'mybookings', 'description' => '📋 Мои активные бронирования'],
            ['command' => 'language', 'description' => '🌐 Выбрать язык / Change language'],
            ['command' => 'help', 'description' => 'ℹ️ О боте и контакты'],
            ['command' => 'start', 'description' => '🚀 Главное меню / Мои бронирования'],
        ];

        $commandsUz = [
            ['command' => 'mybookings', 'description' => '📋 Mening faol bandliklarim'],
            ['command' => 'language', 'description' => '🌐 Tilni o‘zgartirish / Change language'],
            ['command' => 'help', 'description' => 'ℹ️ Bot haqida ma’lumot'],
            ['command' => 'start', 'description' => '🚀 Asosiy menyu / Bandliklarim'],
        ];

        $this->setMyCommands($commandsEn);
        $this->setMyCommands($commandsRu, 'ru');
        $this->setMyCommands($commandsUz, 'uz');
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function post(string $method, array $params, int $timeout = 15): ?array
    {
        if (! $this->isConfigured()) {
            Log::warning("TelegramService: bot token not configured when calling {$method}");

            return null;
        }

        try {
            $response = Http::timeout($timeout)->post("{$this->apiUrl}/{$method}", $params);

            if ($response->successful()) {
                return $response->json();
            }

            Log::error("Telegram API error [{$method}]", [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        } catch (\Throwable $e) {
            Log::error("Telegram request exception [{$method}]: ".$e->getMessage());

            return null;
        }
    }
}
