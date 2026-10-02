<?php

declare(strict_types=1);

namespace App\Telegram\Middleware;

use App\Models\TelegramUser;
use Illuminate\Support\Carbon;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;

final class IdentifyVisitor
{
    public function __invoke(Nutgram $bot, callable $next): void
    {
        $from = $bot->user();
        $type = $bot->chat()?->type;

        if ($from === null || ($type !== ChatType::PRIVATE && $type !== ChatType::PRIVATE->value)) {
            return;
        }

        $visitor = TelegramUser::query()->createOrFirst(['chat_id' => $from->id], ['language_code' => self::language($from->language_code)]);

        if (! in_array($visitor->language_code, available_locales(), true)) {
            $visitor->language_code = self::language($visitor->language_code);
        }

        $visitor->fill([
            'username' => $from->username,
            'first_name' => $from->first_name,
            'last_name' => $from->last_name,
            'last_seen_at' => Carbon::now(),
        ])->save();

        $bot->set(TelegramUser::class, $visitor);
        app()->setLocale($visitor->language_code);

        $next($bot);
    }

    private static function language(?string $code): string
    {
        $language = strtolower((string) strtok((string) $code, '-_'));

        return in_array($language, available_locales(), true) ? $language : (string) config('app.locale');
    }

    public static function visitor(Nutgram $bot): TelegramUser
    {
        return $bot->get(TelegramUser::class);
    }
}
