<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Telegram\Middleware\IdentifyVisitor;
use App\Telegram\Support\BookingCard;
use App\Telegram\Support\Keyboards;
use App\Telegram\Support\VisitorBookings;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;

final class ShowBookings
{
    public function __construct(
        private readonly VisitorBookings $bookings,
        private readonly Start $start,
    ) {}

    public function upcoming(Nutgram $bot): void
    {
        $phone = IdentifyVisitor::visitor($bot)->phone;

        if ($phone === null) {
            $this->start->menu($bot);

            return;
        }

        if ($bot->isCallbackQuery()) {
            $bot->answerCallbackQuery();
        }

        $bookings = $this->bookings->upcoming($phone);

        if ($bookings->isEmpty()) {
            $bot->sendMessage(__('app.bot.no_bookings', ['phone' => $phone]), reply_markup: Keyboards::site());

            return;
        }

        $bot->sendMessage(__('app.bot.bookings_heading'));

        foreach ($bookings as $booking) {
            $bot->sendMessage(BookingCard::text($booking), parse_mode: ParseMode::HTML, reply_markup: BookingCard::buttons($booking));
        }
    }

    public function past(Nutgram $bot): void
    {
        $phone = IdentifyVisitor::visitor($bot)->phone;

        if ($phone === null) {
            $this->start->menu($bot);

            return;
        }

        if ($bot->isCallbackQuery()) {
            $bot->answerCallbackQuery();
        }

        $bookings = $this->bookings->past($phone);

        $bot->sendMessage(
            $bookings->isEmpty()
                ? __('app.bot.no_history')
                : __('app.bot.history_heading')."\n\n".$bookings->map(BookingCard::line(...))->implode("\n"),
            parse_mode: ParseMode::HTML,
        );
    }
}
