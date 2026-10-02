<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Models\LibraryBooking;
use App\Models\ProgrammeBooking;
use App\Models\VisitBooking;
use App\Services\VisitorReschedule;
use App\Telegram\Support\BookingCard;
use App\Telegram\Support\FindsVisitorBooking;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;

final class RescheduleBooking
{
    use FindsVisitorBooking;

    private const REFUSALS = [
        VisitorReschedule::CLOSED => 'app.bot.move_closed',
        VisitorReschedule::TAKEN => 'app.bot.move_taken',
        VisitorReschedule::DAILY_LIMIT => 'app.booking.error.daily_limit',
        VisitorReschedule::SAME_TIME => 'app.booking.error.same_time',
    ];

    private const MOVES_PER_HOUR = 10;

    public function __construct(private readonly VisitorReschedule $reschedule) {}

    public function days(Nutgram $bot, string $type, string $id): void
    {
        $booking = $this->movable($bot, $type, $id);

        if ($booking === null) {
            return;
        }

        $days = $this->reschedule->days($booking);

        if ($days === []) {
            $bot->answerCallbackQuery(text: __('app.bot.move_no_days'), show_alert: true);

            return;
        }

        $bot->answerCallbackQuery();
        $bot->editMessageText(
            BookingCard::text($booking)."\n\n".__('app.bot.move_choose_day'),
            parse_mode: ParseMode::HTML,
            reply_markup: BookingCard::days($booking, $days),
        );
    }

    public function times(Nutgram $bot, string $type, string $id, string $date): void
    {
        $booking = $this->movable($bot, $type, $id);

        if ($booking === null) {
            return;
        }

        $day = Carbon::canBeCreatedFromFormat($date, 'Y-m-d') ? Carbon::createFromFormat('!Y-m-d', $date) : null;
        $times = $day === null ? collect() : $this->reschedule->times($booking, $day);

        if ($day === null || $times->isEmpty()) {
            $bot->answerCallbackQuery(text: __('app.bot.move_day_full'), show_alert: true);

            return;
        }

        $bot->answerCallbackQuery();
        $bot->editMessageText(
            BookingCard::text($booking)."\n\n".__('app.bot.move_choose_time', ['date' => BookingCard::day($day, 'dddd, D MMMM')]),
            parse_mode: ParseMode::HTML,
            reply_markup: BookingCard::times($booking, $times),
        );
    }

    public function move(Nutgram $bot, string $type, string $id, string $slot): void
    {
        $booking = $this->movable($bot, $type, $id);

        if ($booking === null) {
            return;
        }

        if (! RateLimiter::attempt('telegram-move:'.$bot->userId(), self::MOVES_PER_HOUR, static fn (): bool => true, 3600)) {
            $bot->answerCallbackQuery(text: __('app.booking.error.too_many'), show_alert: true);

            return;
        }

        $outcome = ctype_digit($slot) ? $this->reschedule->move($booking, (int) $slot) : VisitorReschedule::TAKEN;

        if ($outcome !== VisitorReschedule::DONE) {
            $bot->answerCallbackQuery(
                text: __(self::REFUSALS[$outcome], ['limit' => VisitorReschedule::availability($booking)::bookingsPerDay()]),
                show_alert: true,
            );

            return;
        }

        $bot->answerCallbackQuery(text: __('app.bot.moved'));
        $bot->editMessageText(
            BookingCard::text($booking)."\n\n".__('app.bot.moved'),
            parse_mode: ParseMode::HTML,
            reply_markup: BookingCard::buttons($booking),
        );
    }

    public function back(Nutgram $bot, string $type, string $id): void
    {
        $booking = $this->booking($bot, $type, $id);

        if ($booking === null) {
            return;
        }

        $bot->answerCallbackQuery();
        $bot->editMessageText(
            BookingCard::text($booking),
            parse_mode: ParseMode::HTML,
            reply_markup: BookingCard::buttons($booking),
        );
    }

    private function movable(Nutgram $bot, string $type, string $id): VisitBooking|LibraryBooking|null
    {
        $booking = $this->booking($bot, $type, $id);

        if ($booking === null) {
            return null;
        }

        if ($booking instanceof ProgrammeBooking || ! VisitorReschedule::allows($booking)) {
            $bot->answerCallbackQuery(text: __('app.bot.move_closed'), show_alert: true);

            return null;
        }

        return $booking;
    }
}
