<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Models\LibraryBooking;
use App\Models\ProgrammeBooking;
use App\Models\VisitBooking;
use App\Services\QrCodeService;
use App\Support\MailLanguages;
use App\Telegram\Support\FindsVisitorBooking;
use App\Telegram\Support\VisitorBookings;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;

final class BookingPass
{
    use FindsVisitorBooking;

    public function __construct(private readonly QrCodeService $qrCodes) {}

    public function qr(Nutgram $bot, string $type, string $id): void
    {
        $booking = $this->usableBooking($bot, $type, $id);

        if ($booking === null) {
            return;
        }

        $bot->answerCallbackQuery();
        $bot->sendPhoto(
            InputFile::make(self::stream($this->qrCodes->generateRawPngForBooking($booking)), 'qr.png'),
            caption: MailLanguages::join(app()->getLocale(), fn (string $language): string => __('app.bot.qr_caption', [], $language), "\n"),
        );
    }

    private function usableBooking(Nutgram $bot, string $type, string $id): VisitBooking|LibraryBooking|ProgrammeBooking|null
    {
        $booking = $this->booking($bot, $type, $id);

        if ($booking !== null && ! ($booking->status->admitsEntry() && VisitorBookings::isUpcoming($booking))) {
            $bot->answerCallbackQuery(text: __('app.bot.pending_note'), show_alert: true);

            return null;
        }

        return $booking;
    }

    /**
     * @return resource
     */
    private static function stream(string $contents)
    {
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }
}
