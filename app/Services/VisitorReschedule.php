<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\BookingRequested;
use App\Models\LibraryBooking;
use App\Models\ProgrammeBooking;
use App\Models\VisitBooking;
use App\Support\LibraryAvailability;
use App\Support\SlotAvailability;
use App\Support\VisitAvailability;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class VisitorReschedule
{
    public const DONE = 'done';

    public const CLOSED = 'closed';

    public const TAKEN = 'taken';

    public const DAILY_LIMIT = 'daily_limit';

    public const SAME_TIME = 'same_time';

    private const DAYS_AHEAD = 30;

    private const DAYS_SHOWN = 12;

    public static function allows(VisitBooking|LibraryBooking|ProgrammeBooking $booking): bool
    {
        return ! $booking instanceof ProgrammeBooking && VisitorCancellation::obstacle($booking) === null;
    }

    /**
     * @return class-string<SlotAvailability>
     */
    public static function availability(VisitBooking|LibraryBooking $booking): string
    {
        return $booking instanceof LibraryBooking ? LibraryAvailability::class : VisitAvailability::class;
    }

    /**
     * @return list<Carbon>
     */
    public function days(VisitBooking|LibraryBooking $booking): array
    {
        $today = Carbon::today();

        return collect(self::availability($booking)::daysWithFreeSeats($today, $today->copy()->addDays(self::DAYS_AHEAD)))
            ->lazy()
            ->map(fn (string $day): Carbon => Carbon::parse($day))
            ->filter(fn (Carbon $day): bool => $this->times($booking, $day)->isNotEmpty())
            ->take(self::DAYS_SHOWN)
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, array{id: int, starts_at: string, ends_at: string}>
     */
    public function times(VisitBooking|LibraryBooking $booking, Carbon $day): Collection
    {
        $availability = self::availability($booking);
        $phone = (string) $booking->getAttribute('phone_normalized');

        if ($day->isBefore(Carbon::today()) || $availability::hasReachedDailyLimit($phone, $day, $booking)) {
            return collect();
        }

        return $availability::slotsOn($day)
            ->filter(fn (array $slot): bool => $slot['free'] > 0 && $slot['id'] !== self::slotOf($booking))
            ->map(fn (array $slot): array => ['id' => $slot['id'], 'starts_at' => $slot['starts_at'], 'ends_at' => self::endsAt($booking, $slot['starts_at'])])
            ->reject(fn (array $slot): bool => $availability::bookingsPerDay() > 1
                && $availability::overlapsOwnBooking($phone, $day, $slot['starts_at'], $slot['ends_at'], $booking))
            ->values();
    }

    /**
     * @return self::DONE|self::CLOSED|self::TAKEN|self::DAILY_LIMIT|self::SAME_TIME
     */
    public function move(VisitBooking|LibraryBooking $booking, int $slotId): string
    {
        $availability = self::availability($booking);

        return DB::transaction(function () use ($booking, $slotId, $availability): string {
            $locked = $booking->newQuery()->whereKey($booking->getKey())->lockForUpdate()->first();

            if ($locked === null || ! self::allows($locked)) {
                return self::CLOSED;
            }

            if (self::slotOf($locked) === $slotId) {
                return self::DONE;
            }

            $slot = $availability::slotModel()::query()->whereKey($slotId)->lockForUpdate()->first();

            if ($slot === null || ! $availability::canBook($slot) || $availability::hasStarted($slot)) {
                return self::TAKEN;
            }

            $phone = (string) $locked->getAttribute('phone_normalized');
            $start = substr($slot->startTime(), 0, 5);
            $end = self::endsAt($locked, $start);

            if ($availability::hasReachedDailyLimit($phone, $slot->date, $locked)) {
                return self::DAILY_LIMIT;
            }

            if ($availability::overlapsOwnBooking($phone, $slot->date, $start, $end, $locked)) {
                return self::SAME_TIME;
            }

            $locked->update([
                $locked instanceof LibraryBooking ? 'library_slot_id' : 'visit_slot_id' => $slot->id,
                'date' => $slot->date->toDateString(),
                ...($locked instanceof LibraryBooking ? ['starts_at' => $start, 'ends_at' => $end] : ['time' => $start]),
            ]);
            $booking->setRawAttributes($locked->getAttributes(), sync: true);

            DB::afterCommit(fn () => event(new BookingRequested($booking)));

            return self::DONE;
        });
    }

    private static function slotOf(VisitBooking|LibraryBooking $booking): ?int
    {
        $slot = $booking instanceof LibraryBooking ? $booking->library_slot_id : $booking->visit_slot_id;

        return $slot === null ? null : (int) $slot;
    }

    private static function endsAt(VisitBooking|LibraryBooking $booking, string $start): string
    {
        return $booking instanceof LibraryBooking ? LibraryAvailability::endsAt($start, $booking->duration_hours) : $start;
    }
}
