<?php

namespace Tests\Feature;

use App\Core\Access\Infrastructure\AccessControlSeeder;
use App\Core\Identity\Application\Mfa\StartTwoFactorChallenge;
use App\Core\Identity\Application\Mfa\VerifyTwoFactorChallenge;
use App\Core\Notification\Application\TwoFactorCodeNotification;
use App\Models\TwoFactorChallenge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Verifikasi dua langkah: kode sekali pakai lewat email.
 *
 * Yang dikunci: kata sandi benar saja tidak cukup, kode hanya berlaku sekali,
 * percobaan dibatasi, dan mematikan MFA wajib menyertakan kata sandi.
 */
class TwoFactorAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        Notification::fake();
    }

    public function test_enabling_two_factor_requires_a_confirmed_code(): void
    {
        $user = $this->user(['email' => 'mfa@example.test']);

        $this->actingAs($user)
            ->get('/profil/keamanan')
            ->assertOk()
            ->assertSee('Keamanan akun')
            ->assertSee('Belum aktif');

        $this->actingAs($user)
            ->post('/profil/keamanan/dua-langkah')
            ->assertRedirect()
            ->assertSessionHas('mfa.enable_pending', true);

        Notification::assertSentTo($user, TwoFactorCodeNotification::class);

        // Kode salah tidak mengaktifkan apa pun.
        $this->actingAs($user)
            ->post('/profil/keamanan/dua-langkah/konfirmasi', ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertNull($user->fresh()->two_factor_enabled_at);

        $this->actingAs($user)
            ->post('/profil/keamanan/dua-langkah/konfirmasi', ['code' => $this->latestCode($user, 'enable')])
            ->assertRedirect();

        $this->assertNotNull($user->fresh()->two_factor_enabled_at);
    }

    public function test_login_with_two_factor_needs_the_emailed_code(): void
    {
        $user = $this->user(['email' => 'dua@example.test']);
        $this->enableTwoFactor($user);

        $this->post('/login', [
            'identity' => $user->email,
            'password' => 'KataSandiRahasia123!',
        ])->assertRedirect(route('mfa.challenge'));

        $this->assertGuest();
        Notification::assertSentTo($user, TwoFactorCodeNotification::class);

        // Kode salah ditolak dan belum ada sesi.
        $this->post('/masuk/verifikasi', ['code' => '111111'])->assertSessionHasErrors('code');
        $this->assertGuest();

        $this->post('/masuk/verifikasi', ['code' => $this->latestCode($user, 'login')])
            ->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user->fresh());

        // Kode yang sama tidak bisa dipakai dua kali.
        $this->post('/logout');
        $this->post('/login', ['identity' => $user->email, 'password' => 'KataSandiRahasia123!']);
        $this->post('/masuk/verifikasi', ['code' => $this->latestCode($user, 'login')])->assertRedirect('/dashboard');
    }

    public function test_too_many_wrong_codes_invalidate_the_challenge(): void
    {
        $user = $this->user(['email' => 'limit@example.test']);
        $this->enableTwoFactor($user);

        $started = app(StartTwoFactorChallenge::class)->execute($user, TwoFactorChallenge::PURPOSE_LOGIN);
        $verify = app(VerifyTwoFactorChallenge::class);

        $lastMessage = null;

        for ($attempt = 1; $attempt <= TwoFactorChallenge::MAX_ATTEMPTS; $attempt++) {
            try {
                $verify->execute($user, '000000', TwoFactorChallenge::PURPOSE_LOGIN);
            } catch (InvalidArgumentException $exception) {
                $lastMessage = $exception->getMessage();
            }
        }

        $this->assertSame('Terlalu banyak percobaan. Minta kode baru.', $lastMessage);
        $this->assertNotNull($started['challenge']->fresh()->consumed_at);

        // Kode yang benar sekalipun tidak diterima lagi setelah tantangan dimatikan.
        $rejected = false;

        try {
            $verify->execute($user, $started['code'], TwoFactorChallenge::PURPOSE_LOGIN);
        } catch (InvalidArgumentException) {
            $rejected = true;
        }

        $this->assertTrue($rejected);
    }

    public function test_disabling_two_factor_requires_the_password(): void
    {
        $user = $this->user(['email' => 'disable@example.test']);
        $this->enableTwoFactor($user);

        $this->actingAs($user)
            ->delete('/profil/keamanan/dua-langkah', ['password' => 'salah'])
            ->assertSessionHasErrors('password');

        $this->assertNotNull($user->fresh()->two_factor_enabled_at);

        $this->actingAs($user)
            ->delete('/profil/keamanan/dua-langkah', ['password' => 'KataSandiRahasia123!'])
            ->assertRedirect();

        $this->assertNull($user->fresh()->two_factor_enabled_at);
    }

    public function test_mobile_login_returns_mfa_token_then_exchanges_it(): void
    {
        $user = $this->user(['email' => 'mobile-mfa@example.test']);
        $this->enableTwoFactor($user);

        $login = $this->postJson('/api/v1/auth/login', [
            'identity' => $user->email,
            'password' => 'KataSandiRahasia123!',
            'device_name' => 'uji',
        ])->assertOk()
            ->assertJsonPath('data.mfa_required', true);

        $token = $login->json('data.mfa_token');
        $this->assertIsString($token);

        $this->postJson('/api/v1/auth/mfa/verify', [
            'mfa_token' => $token,
            'code' => '000000',
        ])->assertStatus(422);

        $this->postJson('/api/v1/auth/mfa/verify', [
            'mfa_token' => $token,
            'code' => $this->latestCode($user, 'login'),
            'device_name' => 'uji',
        ])->assertOk()
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonStructure(['data' => ['token', 'expires_at']]);
    }

    public function test_security_api_reports_status_and_toggles(): void
    {
        $user = $this->user(['email' => 'api-security@example.test']);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me/security')
            ->assertOk()
            ->assertJsonPath('data.two_factor_enabled', false);

        $this->postJson('/api/v1/me/security/two-factor')
            ->assertStatus(202)
            ->assertJsonStructure(['data' => ['mfa_token', 'expires_at']]);

        $this->postJson('/api/v1/me/security/two-factor/confirm', [
            'code' => $this->latestCode($user, 'enable'),
        ])->assertOk()->assertJsonPath('data.two_factor_enabled', true);

        $this->deleteJson('/api/v1/me/security/two-factor', ['password' => 'salah'])->assertStatus(422);
        $this->deleteJson('/api/v1/me/security/two-factor', ['password' => 'KataSandiRahasia123!'])
            ->assertOk()
            ->assertJsonPath('data.two_factor_enabled', false);
    }

    private function user(array $overrides = []): User
    {
        return User::query()->create(array_merge([
            'name' => 'Pengguna MFA',
            'phone' => '08'.random_int(1000000000, 9999999999),
            'email' => null,
            'password' => Hash::make('KataSandiRahasia123!'),
            'status' => 'active',
        ], $overrides));
    }

    private function enableTwoFactor(User $user): void
    {
        $user->forceFill(['two_factor_enabled_at' => now()])->save();
    }

    /**
     * Kode asli hanya ada di email; Notifikasi palsu menyimpannya untuk tes.
     */
    private function latestCode(User $user, string $purpose): string
    {
        $captured = null;

        Notification::assertSentTo(
            $user,
            TwoFactorCodeNotification::class,
            function (TwoFactorCodeNotification $notification) use (&$captured, $purpose): bool {
                if ($notification->purpose === $purpose) {
                    $captured = $notification->code;
                }

                return true;
            },
        );

        if ($captured === null) {
            $this->fail('Notifikasi kode verifikasi tidak pernah dikirim untuk tujuan '.$purpose.'.');
        }

        return $captured;
    }
}
