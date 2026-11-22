<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCatalog;
use Tests\TestCase;

/** Filament wires the 2FA requirement into the admin routes at boot, so the flag is set before the app starts. */
class AdminMfaTest extends TestCase
{
    use BuildsCatalog, RefreshDatabase;

    protected function setUp(): void
    {
        putenv('ADMIN_MFA_REQUIRED=true');
        $_ENV['ADMIN_MFA_REQUIRED'] = $_SERVER['ADMIN_MFA_REQUIRED'] = 'true';
        parent::setUp();
        $this->seedReference();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        putenv('ADMIN_MFA_REQUIRED');
        unset($_ENV['ADMIN_MFA_REQUIRED'], $_SERVER['ADMIN_MFA_REQUIRED']);
    }

    public function test_staff_must_enrol_an_authenticator_before_using_the_admin(): void
    {
        $this->assertTrue(config('lugha.admin_mfa_required'));
        $admin = $this->staff();

        $this->actingAs($admin)->get('/admin/games')->assertRedirectContains('/admin/multi-factor-authentication');

        $admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->actingAs($admin->fresh())->get('/admin/games')->assertOk();
    }
}
