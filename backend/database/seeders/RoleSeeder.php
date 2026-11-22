<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleSeeder extends Seeder
{
    public const ROLES = ['super_admin', 'catalog_manager', 'translator', 'reviewer', 'support', 'finance'];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (self::ROLES as $role) {
            Role::findOrCreate($role, 'web');
        }
    }
}
