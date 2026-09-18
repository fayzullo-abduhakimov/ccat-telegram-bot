<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\TelegramBotUpdateHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    public function __construct(
        private readonly TelegramBotUpdateHandler $handler,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $update = $request->all();

        if (empty($update)) {
            return response()->json(['ok' => false, 'error' => 'Empty update payload'], 400);
        }

        try {
            $this->handler->handle($update);

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            Log::error('Telegram webhook handling exception: '.$e->getMessage(), [
                'update' => $update,
                'trace' => $e->getTraceAsString(),
            ]);

            // Still return 200 to Telegram so it doesn't repeatedly retry failing updates
            return response()->json(['ok' => true, 'warning' => 'Processed with errors']);
        }
    }
}
