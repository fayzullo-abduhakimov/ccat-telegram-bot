<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BookingStatus;
use App\Models\LibraryBooking;
use App\Models\ProgrammeBooking;
use App\Models\VisitBooking;
use Illuminate\Support\Facades\DB;

class VisitorCancellation
{
    public const DONE = 'done';

    public const ALREADY = 'already';

    public const TOO_LATE = 'too_late';

    public static function obstacle(VisitBooking|LibraryBooking|ProgrammeBooking $booking): ?string
    {
        if (in_array($booking->status, [BookingStatus::Cancelled, BookingStatus::Rejected], true)) {
            return self::ALREADY;
        }

        if ($booking->isVerified() || ! $booking->isUpcoming()) {
            return self::TOO_LATE;
        }

        return null;
    }

    /**
     * @return self::DONE|self::ALREADY|self::TOO_LATE
     */
    public function cancel(VisitBooking|LibraryBooking|ProgrammeBooking $booking): string
    {
        return DB::transaction(function () use ($booking): string {
            $locked = $booking->newQuery()->whereKey($booking->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                return self::ALREADY;
            }

            $obstacle = self::obstacle($locked);

            if ($obstacle !== null) {
                return $obstacle;
            }

            $locked->update([
                'status' => BookingStatus::Cancelled,
                'cancelled_at' => now(),
            ]);
            $booking->setRawAttributes($locked->getAttributes(), sync: true);

            return self::DONE;
        });
    }
}
