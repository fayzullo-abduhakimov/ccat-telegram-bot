<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Support\MailLanguages;
use App\Telegram\Middleware\IdentifyVisitor;
use App\Telegram\Support\Keyboards;
use SergiX44\Nutgram\Nutgram;

final class Start
{
    public function __invoke(Nutgram $bot): void
    {
        $bot->sendMessage(MailLanguages::join(app()->getLocale(), fn (string $language): string => __('app.bot.welcome', [], $language), "\n\n— — —\n\n"));

        $this->menu($bot);
    }

    public function menu(Nutgram $bot): void
    {
        if ($bot->isCallbackQuery()) {
            $bot->answerCallbackQuery();
        }

        if (IdentifyVisitor::visitor($bot)->phone === null) {
            $bot->sendMessage(__('app.bot.ask_phone'), reply_markup: Keyboards::sharePhone());

            return;
        }

        $bot->sendMessage(__('app.bot.menu'), reply_markup: Keyboards::menu());
    }
}
