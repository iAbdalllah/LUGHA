<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MakeUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_a_verified_admin_from_the_prompt(): void
    {
        $this->seed(RoleSeeder::class);

        $this->artisan('lugha:make-user', ['email' => 'Owner@Example.com', '--name' => 'Owner', '--role' => ['super_admin']])
            ->expectsQuestion('Password (at least 12 characters)', 'a-long-password-1')
            ->expectsQuestion('Repeat the password', 'a-long-password-1')
            ->assertSuccessful();

        $u = User::where('email', 'owner@example.com')->firstOrFail();
        $this->assertTrue($u->hasRole('super_admin'));
        $this->assertTrue((bool) $u->is_staff);
        $this->assertTrue($u->canAccessPanel(filament()->getPanel('admin')));
        $this->assertNotNull($u->email_verified_at);
        $this->assertTrue(Hash::check('a-long-password-1', $u->password));
    }

    public function test_asks_for_the_email_when_left_out(): void
    {
        $this->seed(RoleSeeder::class);

        $this->artisan('lugha:make-user', ['--role' => ['super_admin']])
            ->expectsQuestion('Email', 'boss@example.com')
            ->expectsQuestion('Password (at least 12 characters)', 'a-long-password-1')
            ->expectsQuestion('Repeat the password', 'a-long-password-1')
            ->assertSuccessful();

        $this->assertTrue(User::where('email', 'boss@example.com')->firstOrFail()->hasRole('super_admin'));
    }

    public function test_refuses_short_passwords_and_unknown_roles(): void
    {
        $this->seed(RoleSeeder::class);

        $this->artisan('lugha:make-user', ['email' => 'a@example.com', '--role' => ['god']])->assertFailed();
        $this->artisan('lugha:make-user', ['email' => 'a@example.com'])
            ->expectsQuestion('Password (at least 12 characters)', 'short')
            ->assertFailed();
        $this->assertDatabaseMissing('users', ['email' => 'a@example.com']);
    }
}
