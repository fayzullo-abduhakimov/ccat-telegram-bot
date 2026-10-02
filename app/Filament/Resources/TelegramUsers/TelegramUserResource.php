<?php

declare(strict_types=1);

namespace App\Filament\Resources\TelegramUsers;

use App\Filament\Resources\TelegramUsers\Pages\ListTelegramUsers;
use App\Filament\Resources\TelegramUsers\Pages\ViewTelegramUser;
use App\Filament\Resources\TelegramUsers\Schemas\TelegramUserInfolist;
use App\Filament\Resources\TelegramUsers\Tables\TelegramUsersTable;
use App\Models\TelegramUser;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends \Filament\Resources\Resource<TelegramUser>
 */
class TelegramUserResource extends Resource
{
    protected static ?string $model = TelegramUser::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    protected static ?string $recordTitleAttribute = 'username';

    protected static ?int $navigationSort = 5;

    public static function getModelLabel(): string
    {
        return __('app.label.telegram_users_single');
    }

    public static function getPluralModelLabel(): string
    {
        return __('app.label.telegram_users_plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.label.administration');
    }

    public static function getNavigationLabel(): string
    {
        return static::getPluralModelLabel();
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getModel()::count();

        return $count > 0 ? (string) $count : null;
    }

    /**
     * @return Builder<TelegramUser>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withBookingsCount();
    }

    public static function infolist(Schema $schema): Schema
    {
        return TelegramUserInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TelegramUsersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTelegramUsers::route('/'),
            'view' => ViewTelegramUser::route('/{record}'),
        ];
    }
}
