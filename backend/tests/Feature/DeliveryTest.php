<?php

namespace Tests\Feature;

use App\Domain\Delivery\BuildPackageVersion;
use App\Domain\Entitlements\EntitlementService;
use App\Enums\EntitlementSource;
use App\Models\LocalizationPackage;
use App\Models\PackageVersion;
use App\Models\Translation;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Backend ↔ engine interoperability: PHP builds via lugha-pkg, issues the license, and the Rust engine
 * (`lugha-pkg open`, the same code path the apps use) opens the package with it.
 */
class DeliveryTest extends TestCase
{
    use RefreshDatabase;

    private string $keyPath;

    protected function setUp(): void
    {
        parent::setUp();
        if (! is_executable((string) config('lugha.pkg_binary'))) {
            $this->markTestSkipped('lugha-pkg binary not built (cargo build --release -p lugha-pkg).');
        }
        Storage::fake('local');
        $this->keyPath = sys_get_temp_dir().'/lugha-test-key-'.uniqid().'.json';
        config(['lugha.signing_key_path' => $this->keyPath]);
        $this->artisan('lugha:signing-key', ['--key-id' => 'pk-test'])->assertSuccessful();
        $this->seed(DatabaseSeeder::class);
    }

    protected function tearDown(): void
    {
        @unlink($this->keyPath ?? '');
        parent::tearDown();
    }

    private function pkg(array $args, array $env = []): array
    {
        $p = new Process([config('lugha.pkg_binary'), ...$args], null, $env);
        $p->run();

        return ['code' => $p->getExitCode(), 'json' => json_decode($p->getOutput(), true), 'err' => $p->getErrorOutput()];
    }

    private function release(LocalizationPackage $package, string $version): PackageVersion
    {
        $v = $package->versions()->create(['version' => $version, 'release_notes' => ['ar' => 'تحديث', 'en' => 'Update']]);
        app(BuildPackageVersion::class)->handle($v);
        $this->assertSame('validated', $v->fresh()->build_status, (string) $v->fresh()->build_log);
        $v->update(['build_status' => 'released', 'released_at' => now()]);

        return $v->fresh();
    }

    public function test_build_license_download_and_open_with_the_rust_engine(): void
    {
        $package = LocalizationPackage::where('slug', 'sandline-sa-full')->firstOrFail();
        $v1 = $this->release($package, '1.0.0');
        $this->assertSame(2, $v1->entry_count);
        $this->assertNotNull($v1->fullFile());

        $user = User::where('email', 'player@lugha.test')->first();
        app(EntitlementService::class)->grant($user, $package, EntitlementSource::Purchase);
        $token = $user->createToken('mac', ['catalog:read', 'library:read', 'commerce'])->plainTextToken;

        $dev = $this->pkg(['device-keygen'])['json'];
        $device = $this->withToken($token)->postJson('/api/v1/devices', ['kind' => 'macos', 'name' => 'MacBook', 'public_key' => $dev['public'], 'app_version' => '0.1.0'])
            ->assertCreated()->json('data');
        $this->assertSame($dev['fingerprint'], $device['fingerprint']);

        $auth = $this->withToken($token)->postJson('/api/v1/downloads', ['package' => 'sandline-sa-full', 'device' => $device['id']])->assertCreated()->json();
        $this->assertSame(['full', '1.0.0'], [$auth['kind'], $auth['version']]);

        // The signed URL works without a bearer token; a tampered one doesn't.
        $this->app['auth']->forgetGuards();
        $file = $this->get($auth['url'])->assertOk()->streamedContent();
        $this->get($auth['url'].'x')->assertForbidden();
        $this->assertSame($auth['sha256'], hash('sha256', $file));

        $lpk = tempnam(sys_get_temp_dir(), 'lpk');
        $lic = tempnam(sys_get_temp_dir(), 'lic');
        file_put_contents($lpk, $file);
        file_put_contents($lic, json_encode($auth['license']));
        $opened = $this->pkg(['open', '--lpk', $lpk, '--key', $this->keyPath, '--license', $lic, 'Water costs more than gold out here!'], ['LUGHA_DEVICE_SECRET' => $dev['secret_hex']]);
        $this->assertSame(0, $opened['code'], $opened['err']);
        $this->assertSame('الماء هنا أغلى من الذهب.', $opened['json']['hit']['target']);

        // Another device's secret can't use this license.
        $other = $this->pkg(['device-keygen'])['json'];
        $this->assertSame(1, $this->pkg(['open', '--lpk', $lpk, '--key', $this->keyPath, '--license', $lic], ['LUGHA_DEVICE_SECRET' => $other['secret_hex']])['code']);

        // Next release: a reviewer publishes another line → 1.1.0 ships with a delta from 1.0.0.
        Translation::where('string_key', 'M03_OBJ_002')->first()->update(['status' => 'published']);
        $v2 = $this->release($package, '1.1.0');
        $this->assertSame(1, $v2->files()->where('kind', 'delta')->count());

        $beat = $this->withToken($token)->postJson("/api/v1/devices/{$device['id']}/heartbeat", ['app_version' => '0.1.0',
            'installations' => [['package' => 'sandline-sa-full', 'version' => '1.0.0']]])->assertOk()->json();
        $this->assertSame([['1.0.0', '1.1.0', 'delta']], array_map(fn ($u) => [$u['from'], $u['to'], $u['kind']], $beat['updates']));

        $update = $this->withToken($token)->postJson('/api/v1/downloads', ['package' => 'sandline-sa-full', 'device' => $device['id'], 'from_version' => '1.0.0'])->json();
        $this->assertSame(['delta', '1.1.0', '1.0.0'], [$update['kind'], $update['version'], $update['base_version']]);

        $this->withToken($token)->postJson("/api/v1/devices/{$device['id']}/licenses")->assertOk()->assertJsonStructure(['licenses' => ['sandline-sa-full' => ['payload', 'sig']]]);
        $this->getJson('/api/v1/trust')->assertOk()->assertJsonPath('keys.0.key_id', 'pk-test');
    }

    public function test_device_code_sign_in(): void
    {
        $start = $this->postJson('/api/v1/auth/device-code', ['client_name' => 'Living-room TV'])->assertOk()->json();
        $this->postJson('/api/v1/auth/device-token', ['device_code' => $start['device_code']])->assertStatus(400)->assertJsonPath('code', 'authorization_pending');
        $this->postJson('/api/v1/auth/device-token', ['device_code' => $start['device_code']])->assertJsonPath('code', 'slow_down');

        $user = User::where('email', 'player@lugha.test')->first();
        $this->actingAs($user)->get('/ar/activate?code='.strtolower(str_replace('-', '', $start['user_code'])))->assertOk()->assertSee('Living-room TV');
        $this->post('/ar/activate', ['code' => $start['user_code'], 'decision' => 'approve'])->assertRedirect();

        $this->app['auth']->forgetGuards();
        $got = $this->postJson('/api/v1/auth/device-token', ['device_code' => $start['device_code']])->assertOk()->json();
        $this->assertSame('player@lugha.test', $got['user']['email']);
        $this->postJson('/api/v1/auth/device-token', ['device_code' => $start['device_code']])->assertJsonPath('code', 'expired_token');
    }

    public function test_device_limit_and_removal_revoke_access(): void
    {
        config(['lugha.device_limits.app' => 2]);
        $user = User::where('email', 'player@lugha.test')->first();
        $tokens = [];
        $ids = [];
        foreach ([1, 2] as $i) {
            $tokens[$i] = $user->createToken("d$i", ['catalog:read', 'library:read', 'commerce'])->plainTextToken;
            $ids[$i] = $this->withToken($tokens[$i])->postJson('/api/v1/devices', ['kind' => 'macos', 'name' => "Mac $i", 'public_key' => $this->pkg(['device-keygen'])['json']['public']])->assertCreated()->json('data.id');
            $this->app['auth']->forgetGuards();
        }
        $third = $user->createToken('d3', ['catalog:read'])->plainTextToken;
        $this->withToken($third)->postJson('/api/v1/devices', ['kind' => 'windows', 'name' => 'PC', 'public_key' => $this->pkg(['device-keygen'])['json']['public']])
            ->assertStatus(409)->assertJsonPath('code', 'device_limit_reached')->assertJsonCount(2, 'devices');
        $this->app['auth']->forgetGuards();
        // TVs have their own allowance.
        $this->withToken($third)->postJson('/api/v1/devices', ['kind' => 'android_tv', 'name' => 'TV', 'public_key' => $this->pkg(['device-keygen'])['json']['public']])->assertCreated();

        $this->actingAs($user)->delete('/ar/devices/'.$ids[1])->assertRedirect();
        $this->app['auth']->forgetGuards();
        $this->withToken($tokens[1])->getJson('/api/v1/me')->assertUnauthorized();
        $this->assertSame(['Mac 2', 'TV'], $user->devices()->where('status', 'active')->orderBy('name')->pluck('name')->all());
        $this->actingAs($user)->get('/ar/devices')->assertOk()->assertSee('Mac 2');
    }
}
