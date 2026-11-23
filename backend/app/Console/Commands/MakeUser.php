<?php

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Create (or update) an account from the server shell — the first admin on a fresh install, or a verified test player.
 * The password is typed at the prompt, never passed as an argument (it would land in shell history).
 */
class MakeUser extends Command
{
    protected $signature = 'lugha:make-user {email? : Asked for when left out} {--name= : Display name} {--role=* : Staff role(s), e.g. super_admin} {--locale=ar}';

    protected $description = 'Create or update a verified account, optionally with staff roles (asks for the password)';

    public function handle(): int
    {
        $email = strtolower(trim((string) ($this->argument('email') ?: $this->ask('Email'))));
        $roles = (array) $this->option('role');
        $unknown = array_diff($roles, RoleSeeder::ROLES);
        if ($unknown) {
            $this->error('Unknown role(s): '.implode(', ', $unknown).'. Known: '.implode(', ', RoleSeeder::ROLES));

            return self::FAILURE;
        }
        if (Validator::make(['email' => $email], ['email' => ['required', 'email']])->fails()) {
            $this->error('That is not a valid email address.');

            return self::FAILURE;
        }

        $password = (string) $this->secret('Password (at least 12 characters)');
        $check = Validator::make(['password' => $password], ['password' => ['required', Password::min(12)]]);
        if ($check->fails()) {
            $this->error($check->errors()->first('password'));

            return self::FAILURE;
        }
        if ($password !== (string) $this->secret('Repeat the password')) {
            $this->error('The passwords do not match.');

            return self::FAILURE;
        }

        $user = User::firstOrNew(['email' => $email]);
        $created = ! $user->exists;
        $user->fill([
            'name' => $this->option('name') ?: ($user->name ?: strstr($email, '@', true)),
            'password' => Hash::make($password),
            'locale' => $this->option('locale') ?: 'ar',
        ]);
        $user->email_verified_at ??= now();
        if ($roles) {
            $user->is_staff = true; // the admin panel only admits staff (User::canAccessPanel)
        }
        $user->save();
        foreach ($roles as $role) {
            $user->assignRole($role);
        }

        $this->info(($created ? 'Created ' : 'Updated ').$email.($roles ? ' with role(s) '.implode(', ', $roles) : ''));

        return self::SUCCESS;
    }
}
