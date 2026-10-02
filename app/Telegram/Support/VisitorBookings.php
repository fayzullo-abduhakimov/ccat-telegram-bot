<?php

declare(strict_types=1);

namespace App\Telegram\Support;

use App\Enums\BookingStatus;
use App\Enums\BookingType;
use App\Models\LibraryBooking;
use App\Models\ProgrammeBooking;
use App\Models\VisitBooking;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class VisitorBookings
{
    private const SHOWN = 10;

    /**
     * @return Collection<int, VisitBooking|LibraryBooking|ProgrammeBooking>
     */
    public function upcoming(string $phone): Collection
    {
        return $this->all($phone)
            ->filter(fn (VisitBooking|LibraryBooking|ProgrammeBooking $booking): bool => self::isUpcoming($booking))
            ->sortBy(fn (VisitBooking|LibraryBooking|ProgrammeBooking $booking): int => self::startsAt($booking)->getTimestamp())
            ->take(self::SHOWN)
            ->values();
    }

    /**
     * @return Collection<int, VisitBooking|LibraryBooking|ProgrammeBooking>
     */
    public function past(string $phone): Collection
    {
        return $this->all($phone)
            ->reject(fn (VisitBooking|LibraryBooking|ProgrammeBooking $booking): bool => self::isUpcoming($booking))
            ->sortByDesc(fn (VisitBooking|LibraryBooking|ProgrammeBooking $booking): int => self::startsAt($booking)->getTimestamp())
            ->take(self::SHOWN)
            ->values();
    }

    public function find(string $phone, string $type, int $id): VisitBooking|LibraryBooking|ProgrammeBooking|null
    {
        return BookingType::tryFrom($type)?->model()::query()->withPhone($phone)->whereKey($id)->first();
    }

    public static function reference(VisitBooking|LibraryBooking|ProgrammeBooking $booking): string
    {
        return BookingType::of($booking)->value.':'.$booking->getKey();
    }

    public static function isUpcoming(VisitBooking|LibraryBooking|ProgrammeBooking $booking): bool
    {
        return in_array($booking->status, [BookingStatus::Pending, BookingStatus::Confirmed], true) && $booking->isUpcoming();
    }

    /**
     * @return Collection<int, VisitBooking|LibraryBooking|ProgrammeBooking>
     */
    private function all(string $phone): Collection
    {
        /** @var Collection<int, VisitBooking|LibraryBooking|ProgrammeBooking> $bookings */
        $bookings = collect(BookingType::cases())->flatMap(fn (BookingType $type): Collection => $type->model()::query()
            ->withPhone($phone)
            ->when($type === BookingType::Programme, fn ($query) => $query->with('programme.location'))
            ->get());

        return $bookings;
    }

    private static function startsAt(VisitBooking|LibraryBooking|ProgrammeBooking $booking): CarbonInterface
    {
        return match (true) {
            $booking instanceof VisitBooking => $booking->date->copy()->setTimeFromTimeString((string) $booking->time),
            $booking instanceof LibraryBooking => $booking->date->copy()->setTimeFromTimeString((string) $booking->starts_at),
            default => $booking->programme->date_start ?? $booking->created_at ?? now(),
        };
    }
}
