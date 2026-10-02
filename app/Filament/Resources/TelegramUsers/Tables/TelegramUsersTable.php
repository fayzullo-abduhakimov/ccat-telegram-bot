<?php

declare(strict_types=1);

namespace App\Filament\Resources\TelegramUsers\Tables;

use App\Filament\Support\Tables;
use App\Models\TelegramUser;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TelegramUsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('last_seen_at', 'desc')
            ->columns([
                TextColumn::make('chat_id')
                    ->label(__('app.label.telegram_chat_id'))
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                TextColumn::make('username')
                    ->label(__('app.label.telegram_username'))
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn (?string $state): string => $state ? "@{$state}" : '—')
                    ->url(fn (TelegramUser $record): ?string => $record->username ? "https://t.me/{$record->username}" : null, shouldOpenInNewTab: true)
                    ->color(fn (TelegramUser $record): ?string => $record->username ? 'primary' : null),

                TextColumn::make('name')
                    ->label(__('app.booking.name'))
                    ->searchable(['first_name', 'last_name'])
                    ->placeholder('—'),

                TextColumn::make('phone')
                    ->label(__('app.booking.phone'))
                    ->formatStateUsing(fn (string $state): string => "+{$state}")
                    ->searchable()
                    ->copyable()
                    ->placeholder('—'),

                TextColumn::make('language_code')
                    ->label(__('app.label.telegram_language'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => strtoupper($state ?? '—'))
                    ->color(fn (?string $state): string => match ($state) {
                        'uz' => 'info',
                        'ru' => 'warning',
                        'en' => 'success',
                        default => 'gray',
                    }),

                TextColumn::make('bookings')
                    ->label(__('app.label.telegram_linked_bookings'))
                    ->state(fn (TelegramUser $record): int => $record->bookingsCount())
                    ->badge()
                    ->color('gray'),

                TextColumn::make('last_seen_at')
                    ->label(__('app.label.telegram_last_seen'))
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->placeholder('—'),

                Tables::createdAt()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('language_code')
                    ->label(__('app.label.telegram_language'))
                    ->options([
                        'uz' => "O'zbek",
                        'ru' => 'Русский',
                        'en' => 'English',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
