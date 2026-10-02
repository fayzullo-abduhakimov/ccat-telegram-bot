<?php

declare(strict_types=1);

namespace App\Filament\Resources\TelegramUsers\Pages;

use App\Filament\Resources\TelegramUsers\TelegramUserResource;
use Filament\Resources\Pages\ListRecords;

class ListTelegramUsers extends ListRecords
{
    protected static string $resource = TelegramUserResource::class;
}
