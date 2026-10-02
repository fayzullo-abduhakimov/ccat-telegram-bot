<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Services\VisitorCancellation;
use App\Telegram\Support\BookingCard;
use App\Telegram\Support\FindsVisitorBooking;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;

final class CancelBooking
{
    use FindsVisitorBooking;

    private const OUTCOMES = [
        VisitorCancellation::DONE => 'app.bot.cancelled',
        VisitorCancellation::ALREADY => 'app.bot.cancel_already',
        VisitorCancellation::TOO_LATE => 'app.bot.cancel_too_late',
    ];

    public function __construct(private readonly VisitorCancellation $cancellation) {}

    public function ask(Nutgram $bot, string $type, string $id): void
    {
        $booking = $this->booking($bot, $type, $id);

        if ($booking === null) {
            return;
        }

        $obstacle = VisitorCancellation::obstacle($booking);

        if ($obstacle !== null) {
            $bot->answerCallbackQuery(text: __(self::OUTCOMES[$obstacle]), show_alert: true);

            return;
        }

        $bot->answerCallbackQuery();
        $bot->editMessageText(
            BookingCard::text($booking)."\n\n".__('app.bot.cancel_question'),
            parse_mode: ParseMode::HTML,
            reply_markup: BookingCard::cancelQuestion($booking),
        );
    }

    public function confirm(Nutgram $bot, string $type, string $id): void
    {
        $booking = $this->booking($bot, $type, $id);

        if ($booking === null) {
            return;
        }

        $outcome = __(self::OUTCOMES[$this->cancellation->cancel($booking)]);

        $bot->answerCallbackQuery(text: $outcome);
        $bot->editMessageText(
            BookingCard::text($booking)."\n\n".$outcome,
            parse_mode: ParseMode::HTML,
            reply_markup: BookingCard::buttons($booking),
        );
    }

    public function keep(Nutgram $bot, string $type, string $id): void
    {
        $booking = $this->booking($bot, $type, $id);

        if ($booking === null) {
            return;
        }

        $bot->answerCallbackQuery(text: __('app.bot.cancel_kept'));
        $bot->editMessageText(
            BookingCard::text($booking),
            parse_mode: ParseMode::HTML,
            reply_markup: BookingCard::buttons($booking),
        );
    }
}
