<?php

declare(strict_types=1);

namespace App\Telegram\Support;

use App\Models\LibraryBooking;
use App\Models\ProgrammeBooking;
use App\Models\VisitBooking;
use App\Telegram\Middleware\IdentifyVisitor;
use SergiX44\Nutgram\Nutgram;

trait FindsVisitorBooking
{
    protected function booking(Nutgram $bot, string $type, string $id): VisitBooking|LibraryBooking|ProgrammeBooking|null
    {
        $phone = IdentifyVisitor::visitor($bot)->phone;
        $booking = $phone === null || ! ctype_digit($id) ? null : app(VisitorBookings::class)->find($phone, $type, (int) $id);

        if ($booking === null) {
            $bot->answerCallbackQuery(text: __('app.bot.not_found'), show_alert: true);
        }

        return $booking;
    }
}
