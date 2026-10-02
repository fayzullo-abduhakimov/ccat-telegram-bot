<?php

declare(strict_types=1);

namespace App\Telegram\Support;

use App\Enums\BookingStatus;
use App\Models\LibraryBooking;
use App\Models\ProgrammeBooking;
use App\Models\VisitBooking;
use App\Services\VisitorCancellation;
use App\Services\VisitorReschedule;
use App\Support\MailLanguages;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class BookingCard
{
    public static function text(VisitBooking|LibraryBooking|ProgrammeBooking $booking): string
    {
        return collect(MailLanguages::startingWith(app()->getLocale()))
            ->map(fn (string $language): string => self::block($booking, $language))
            ->implode("\n\n— — —\n\n");
    }

    public static function line(VisitBooking|LibraryBooking|ProgrammeBooking $booking): string
    {
        $language = app()->getLocale();

        return '• <b>'.e(self::title($booking, $language)).'</b> — '.e(self::when($booking, $language)).' · '.e(self::status($booking, $language));
    }

    public static function buttons(VisitBooking|LibraryBooking|ProgrammeBooking $booking): ?InlineKeyboardMarkup
    {
        $reference = VisitorBookings::reference($booking);
        $keyboard = InlineKeyboardMarkup::make();
        $hasButtons = false;

        if ($booking->status->admitsEntry() && VisitorBookings::isUpcoming($booking)) {
            $keyboard->addRow(InlineKeyboardButton::make(__('app.bot.button_qr'), callback_data: "qr:{$reference}"));
            $hasButtons = true;
        }

        if (VisitorReschedule::allows($booking)) {
            $keyboard->addRow(InlineKeyboardButton::make(__('app.bot.button_move'), callback_data: "move:{$reference}"));
            $hasButtons = true;
        }

        if (VisitorCancellation::obstacle($booking) === null) {
            $keyboard->addRow(InlineKeyboardButton::make(__('app.bot.button_cancel'), callback_data: "cancel:{$reference}"));
            $hasButtons = true;
        }

        return $hasButtons ? $keyboard : null;
    }

    /**
     * @param  list<Carbon>  $days
     */
    public static function days(VisitBooking|LibraryBooking $booking, array $days): InlineKeyboardMarkup
    {
        $reference = VisitorBookings::reference($booking);
        $keyboard = InlineKeyboardMarkup::make();

        foreach (array_chunk($days, 3) as $row) {
            $keyboard->addRow(...array_map(
                fn (Carbon $day): InlineKeyboardButton => InlineKeyboardButton::make(
                    self::day($day, 'dd, D MMM'),
                    callback_data: "move-day:{$reference}:{$day->toDateString()}",
                ),
                $row,
            ));
        }

        return $keyboard->addRow(InlineKeyboardButton::make(__('app.bot.move_back'), callback_data: "move-no:{$reference}"));
    }

    /**
     * @param  Collection<int, array{id: int, starts_at: string, ends_at: string}>  $times
     */
    public static function times(VisitBooking|LibraryBooking $booking, Collection $times): InlineKeyboardMarkup
    {
        $reference = VisitorBookings::reference($booking);
        $keyboard = InlineKeyboardMarkup::make();

        foreach ($times->chunk($booking instanceof LibraryBooking ? 2 : 3) as $row) {
            $keyboard->addRow(...$row->map(fn (array $time): InlineKeyboardButton => InlineKeyboardButton::make(
                $time['starts_at'] === $time['ends_at'] ? $time['starts_at'] : $time['starts_at'].' – '.$time['ends_at'],
                callback_data: "move-to:{$reference}:{$time['id']}",
            ))->values()->all());
        }

        return $keyboard->addRow(
            InlineKeyboardButton::make(__('app.bot.move_other_day'), callback_data: "move:{$reference}"),
            InlineKeyboardButton::make(__('app.bot.move_back'), callback_data: "move-no:{$reference}"),
        );
    }

    public static function day(Carbon $day, string $format, ?string $language = null): string
    {
        $language ??= app()->getLocale();

        return $day->copy()->locale($language === 'uz' ? 'uz_Latn' : $language)->isoFormat($format);
    }

    public static function cancelQuestion(VisitBooking|LibraryBooking|ProgrammeBooking $booking): InlineKeyboardMarkup
    {
        $reference = VisitorBookings::reference($booking);

        return InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make(__('app.bot.cancel_yes'), callback_data: "cancel-yes:{$reference}"),
            InlineKeyboardButton::make(__('app.bot.cancel_no'), callback_data: "cancel-no:{$reference}"),
        );
    }

    private static function block(VisitBooking|LibraryBooking|ProgrammeBooking $booking, string $language): string
    {
        $lines = [
            '<b>'.e(self::title($booking, $language)).'</b>',
            '👤 '.e($booking->name),
            '📅 '.e(self::when($booking, $language)),
            '📍 '.e(self::where($booking, $language)),
            __('app.bot.status', [], $language).': '.e(self::status($booking, $language)),
        ];

        if ($booking->status === BookingStatus::Pending) {
            $lines[] = '⏳ '.__('app.bot.pending_note', [], $language);
        }

        return implode("\n", $lines);
    }

    private static function title(VisitBooking|LibraryBooking|ProgrammeBooking $booking, string $language): string
    {
        return match (true) {
            $booking instanceof VisitBooking => __('app.bot.type_visit', [], $language),
            $booking instanceof LibraryBooking => __('app.bot.type_library', [], $language),
            default => self::plain((string) $booking->programme->getTranslation('title', $language)),
        };
    }

    private static function when(VisitBooking|LibraryBooking|ProgrammeBooking $booking, string $language): string
    {
        if (! $booking instanceof ProgrammeBooking) {
            return self::day($booking->date, 'LL', $language).' · '.$booking->time_range;
        }

        $date = $booking->programme->formatDateLabel($language);
        $time = $booking->programme->timeLabel();

        return $time === '' ? $date : $date.' · '.$time;
    }

    private static function where(VisitBooking|LibraryBooking|ProgrammeBooking $booking, string $language): string
    {
        return match (true) {
            $booking instanceof VisitBooking => __('app.bot.cca_building', [], $language),
            $booking instanceof LibraryBooking => __('app.bot.library_building', [], $language),
            default => self::plain((string) $booking->programme->location?->getTranslation('name', $language)) ?: __('app.bot.cca_building', [], $language),
        };
    }

    private static function status(VisitBooking|LibraryBooking|ProgrammeBooking $booking, string $language): string
    {
        return __('app.booking_status.'.$booking->status->value, [], $language);
    }

    private static function plain(string $html): string
    {
        return trim((string) preg_replace('/\s+/', ' ', strip_tags(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
    }
}
