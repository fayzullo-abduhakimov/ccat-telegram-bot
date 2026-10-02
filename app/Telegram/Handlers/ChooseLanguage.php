<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Telegram\Middleware\IdentifyVisitor;
use App\Telegram\Support\Keyboards;
use SergiX44\Nutgram\Nutgram;

final class ChooseLanguage
{
    public function __construct(private readonly Start $start) {}

    public function show(Nutgram $bot): void
    {
        if ($bot->isCallbackQuery()) {
            $bot->answerCallbackQuery();
        }

        $bot->sendMessage(__('app.bot.choose_language'), reply_markup: Keyboards::languages());
    }

    public function set(Nutgram $bot, string $code): void
    {
        if (! array_key_exists($code, Keyboards::LANGUAGES)) {
            $bot->answerCallbackQuery();

            return;
        }

        IdentifyVisitor::visitor($bot)->update(['language_code' => $code]);
        app()->setLocale($code);

        $bot->editMessageText(__('app.bot.language_saved'));

        $this->start->menu($bot);
    }
}
