<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Telegram\TelegramWebhook;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Nutgram\Laravel\RunningMode\LaravelWebhook;
use SergiX44\Nutgram\Nutgram;

class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, Nutgram $bot): Response
    {
        abort_unless(hash_equals(TelegramWebhook::secret(), (string) $request->header('X-Telegram-Bot-Api-Secret-Token')), 403);

        $bot->setRunningMode(LaravelWebhook::class);
        $bot->run();

        return response()->noContent();
    }
}
