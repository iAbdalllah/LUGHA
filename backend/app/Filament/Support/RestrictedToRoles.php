<?php

namespace App\Filament\Support;

use Illuminate\Database\Eloquent\Model;

/** Resource-level access by staff role. super_admin sees everything. */
trait RestrictedToRoles
{
    /** @return string[] */
    abstract protected static function allowedRoles(): array;

    protected static function staffAllowed(): bool
    {
        $user = auth()->user();

        return $user && $user->is_staff && $user->hasAnyRole(['super_admin', ...static::allowedRoles()]);
    }

    public static function canViewAny(): bool
    {
        return static::staffAllowed();
    }

    public static function canCreate(): bool
    {
        return static::staffAllowed();
    }

    public static function canEdit(Model $record): bool
    {
        return static::staffAllowed();
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }
}
