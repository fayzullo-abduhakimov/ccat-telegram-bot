<?php

declare(strict_types=1);

namespace App\Filament\Resources\TelegramUsers\Schemas;

use App\Models\TelegramUser;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TelegramUserInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('app.label.telegram_users_single'))
                ->columns(2)
                ->schema([
                    TextEntry::make('chat_id')
                        ->label(__('app.label.telegram_chat_id'))
                        ->copyable(),

                    TextEntry::make('username')
                        ->label(__('app.label.telegram_username'))
                        ->formatStateUsing(fn (?string $state): string => $state ? "@{$state}" : '—')
                        ->url(fn (TelegramUser $record): ?string => $record->username ? "https://t.me/{$record->username}" : null, shouldOpenInNewTab: true)
                        ->color(fn (TelegramUser $record): ?string => $record->username ? 'primary' : null)
                        ->copyable(),

                    TextEntry::make('first_name')
                        ->label(__('app.label.first_name'))
                        ->placeholder('—'),

                    TextEntry::make('last_name')
                        ->label(__('app.booking.last_name'))
                        ->placeholder('—'),

                    TextEntry::make('phone')
                        ->label(__('app.booking.phone'))
                        ->formatStateUsing(fn (string $state): string => "+{$state}")
                        ->copyable()
                        ->placeholder('—'),

                    TextEntry::make('language_code')
                        ->label(__('app.label.telegram_language'))
                        ->badge()
                        ->formatStateUsing(fn (?string $state): string => strtoupper($state ?? '—'))
                        ->color(fn (?string $state): string => match ($state) {
                            'uz' => 'info',
                            'ru' => 'warning',
                            'en' => 'success',
                            default => 'gray',
                        }),

                    TextEntry::make('booking_links_count')
                        ->label(__('app.label.telegram_linked_bookings'))
                        ->state(fn (TelegramUser $record): int => $record->bookingsCount())
                        ->badge(),

                    TextEntry::make('last_seen_at')
                        ->label(__('app.label.telegram_last_seen'))
                        ->dateTime('d.m.Y H:i:s')
                        ->placeholder('—'),

                    TextEntry::make('created_at')
                        ->label(__('app.label.created_at'))
                        ->dateTime('d.m.Y H:i:s'),
                ]),
        ]);
    }
}
