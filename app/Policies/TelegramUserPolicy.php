<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\TelegramUser;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class TelegramUserPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:TelegramUser');
    }

    public function view(AuthUser $authUser, TelegramUser $telegramUser): bool
    {
        return $authUser->can('View:TelegramUser');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:TelegramUser');
    }

    public function update(AuthUser $authUser, TelegramUser $telegramUser): bool
    {
        return $authUser->can('Update:TelegramUser');
    }

    public function delete(AuthUser $authUser, TelegramUser $telegramUser): bool
    {
        return $authUser->can('Delete:TelegramUser');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:TelegramUser');
    }

    public function restore(AuthUser $authUser, TelegramUser $telegramUser): bool
    {
        return $authUser->can('Restore:TelegramUser');
    }

    public function forceDelete(AuthUser $authUser, TelegramUser $telegramUser): bool
    {
        return $authUser->can('ForceDelete:TelegramUser');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:TelegramUser');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:TelegramUser');
    }

    public function replicate(AuthUser $authUser, TelegramUser $telegramUser): bool
    {
        return $authUser->can('Replicate:TelegramUser');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:TelegramUser');
    }
}
