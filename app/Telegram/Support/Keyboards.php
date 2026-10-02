<?php

declare(strict_types=1);

namespace App\Telegram\Support;

use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Keyboard\KeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardMarkup;

final class Keyboards
{
    /** @var array<string, string> */
    public const LANGUAGES = [
        'uz' => 'O‘zbekcha',
        'ru' => 'Русский',
        'en' => 'English',
    ];

    public const MENU = [
        'bookings' => 'app.bot.menu_bookings',
        'history' => 'app.bot.menu_history',
        'language' => 'app.bot.menu_language',
    ];

    public static function sharePhone(): ReplyKeyboardMarkup
    {
        return ReplyKeyboardMarkup::make(resize_keyboard: true, one_time_keyboard: true)
            ->addRow(KeyboardButton::make(__('app.bot.share_phone'), request_contact: true));
    }

    public static function menu(): ReplyKeyboardMarkup
    {
        return ReplyKeyboardMarkup::make(resize_keyboard: true, is_persistent: true)
            ->addRow(KeyboardButton::make(__(self::MENU['bookings'])), KeyboardButton::make(__(self::MENU['history'])))
            ->addRow(KeyboardButton::make(__(self::MENU['language'])));
    }

    public static function site(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make(__('app.bot.menu_site'), url: (string) config('app.url')));
    }

    public static function languages(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()->addRow(...array_map(
            fn (string $code, string $name): InlineKeyboardButton => InlineKeyboardButton::make($name, callback_data: "lang:{$code}"),
            array_keys(self::LANGUAGES),
            self::LANGUAGES,
        ));
    }
}
