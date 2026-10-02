<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TelegramUser extends Model
{
    /**
     * @var array<string, class-string<VisitBooking|LibraryBooking|ProgrammeBooking>>
     */
    private const BOOKINGS = [
        'visit_bookings_count' => VisitBooking::class,
        'library_bookings_count' => LibraryBooking::class,
        'programme_bookings_count' => ProgrammeBooking::class,
    ];

    protected $fillable = [
        'chat_id',
        'username',
        'first_name',
        'last_name',
        'phone',
        'language_code',
        'last_seen_at',
    ];

    protected $casts = [
        'chat_id' => 'integer',
        'last_seen_at' => 'datetime',
    ];

    public function getNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithBookingsCount(Builder $query): Builder
    {
        return $query->addSelect(array_map(
            fn (string $model): Builder => $model::query()->selectRaw('count(*)')->whereColumn('phone_normalized', $this->qualifyColumn('phone')),
            self::BOOKINGS,
        ));
    }

    public function bookingsCount(): int
    {
        if ($this->phone === null) {
            return 0;
        }

        return collect(self::BOOKINGS)->sum(fn (string $model, string $count): int => (int) ($this->attributes[$count] ?? $model::query()->withPhone($this->phone)->count()));
    }
}
