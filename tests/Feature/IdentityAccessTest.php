<?php

namespace Tests\Feature;

use App\Core\Access\Infrastructure\AccessControlSeeder;
use App\Core\Identity\Domain\AccountType;
use App\Models\Business;
use App\Models\BusinessInvitation;
use App\Models\PlatformUserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class IdentityAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessControlSeeder::class);
    }

    public function test_a_user_can_hold_multiple_business_roles_under_one_identity(): void
    {
        $user = User::factory()->create();
        $ownedBusiness = $this->businessFor($user);
        $staffBusiness = $this->businessFor(User::factory()->create());
        $staffBusiness->members()->attach($user, [
            'role' => 'penjaga',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $token = $user->createToken('test-device')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/me/memberships')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['id' => $ownedBusiness->id, 'role' => 'pemilik'])
            ->assertJsonFragment(['id' => $staffBusiness->id, 'role' => 'penjaga']);
    }

    public function test_staff_cannot_read_another_business_or_its_members(): void
    {
        $actor = User::factory()->create();
        $ownBusiness = $this->businessFor(User::factory()->create());
        $ownBusiness->members()->attach($actor, [
            'role' => 'penjaga',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $otherBusiness = $this->businessFor(User::factory()->create());
        $token = $actor->createToken('test-device')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/businesses/'.$ownBusiness->id)
            ->assertOk();

        $this->withToken($token)
            ->getJson('/api/v1/businesses/'.$otherBusiness->id)
            ->assertForbidden()
            ->assertJsonPath('code', 'FORBIDDEN');

        $this->withToken($token)
            ->getJson('/api/v1/businesses/'.$otherBusiness->id.'/members')
            ->assertForbidden()
            ->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_api_token_authentication_does_not_require_a_web_session(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Test Mobile User',
            'phone' => '0812-3456-7890',
            'email' => null,
            'password' => 'a-secure-test-password',
            'password_confirmation' => 'a-secure-test-password',
        ])->assertCreated()
            ->assertJsonPath('data.user.phone', '+6281234567890');

        $login = $this->postJson('/api/v1/auth/login', [
            'identity' => '+6281234567890',
            'password' => 'a-secure-test-password',
            'device_name' => 'phase-one-test',
        ])->assertOk()
            ->assertJsonPath('data.user.phone', '+6281234567890')
            ->assertJsonStructure(['data' => ['token', 'expires_at', 'user' => ['id', 'name', 'phone', 'email']]]);

        $token = $login->json('data.token');
        $this->getJson('/api/v1/me')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'UNAUTHENTICATED');

        $this->withToken($token)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.user.phone', '+6281234567890');
    }

    public function test_login_does_not_disclose_whether_identity_exists(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'identity' => '+6281234567899',
            'password' => 'wrong-password',
        ])->assertUnauthorized()
            ->assertJsonPath('code', 'INVALID_CREDENTIALS')
            ->assertJsonStructure(['message', 'code', 'errors']);
    }

    public function test_auth_rate_limit_uses_the_api_error_envelope(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'identity' => '+6281234567899',
                'password' => 'wrong-password',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/v1/auth/login', [
            'identity' => '+6281234567899',
            'password' => 'wrong-password',
        ])->assertTooManyRequests()
            ->assertJsonPath('code', 'RATE_LIMITED')
            ->assertJsonStructure(['message', 'code', 'errors']);
    }

    public function test_staff_invitation_is_hashed_single_use_and_creates_membership(): void
    {
        $owner = User::factory()->create();
        $business = $this->businessFor($owner);
        $token = $owner->createToken('test-device')->plainTextToken;

        $issued = $this->withToken($token)
            ->postJson('/api/v1/businesses/'.$business->id.'/invitations', [
                'phone' => '0812-3456-7899',
                'role' => 'penjaga',
            ])->assertCreated();

        $invitationToken = parse_url($issued->json('data.invitation.url'), PHP_URL_FRAGMENT);
        $invitation = BusinessInvitation::query()->firstOrFail();
        $this->assertNotSame($invitationToken, $invitation->token_hash);
        $this->assertSame(hash('sha256', $invitationToken), $invitation->token_hash);

        Auth::forgetGuards();
        $this->flushHeaders()->postJson('/api/v1/invitations/accept', [
            'token' => $invitationToken,
            'name' => 'Invited Staff',
            'password' => 'a-secure-test-password',
            'password_confirmation' => 'a-secure-test-password',
        ])->assertOk()
            ->assertJsonPath('data.user.phone', '+6281234567899')
            ->assertJsonPath('data.role', 'penjaga');

        $this->assertDatabaseHas('business_members', [
            'business_id' => $business->id,
            'role' => 'penjaga',
            'status' => 'active',
        ]);
        $this->postJson('/api/v1/invitations/accept', ['token' => $invitationToken])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED');
    }

    public function test_invitation_cannot_be_claimed_by_another_existing_identity(): void
    {
        $owner = User::factory()->create();
        $business = $this->businessFor($owner);
        $ownerToken = $owner->createToken('test-device')->plainTextToken;
        $issued = $this->withToken($ownerToken)
            ->postJson('/api/v1/businesses/'.$business->id.'/invitations', [
                'phone' => '0812-3456-7899',
                'role' => 'penjaga',
            ])->assertCreated();
        $invitationToken = parse_url($issued->json('data.invitation.url'), PHP_URL_FRAGMENT);
        $existingUser = User::factory()->accountType(AccountType::WarungGuard)->create(['phone' => '+6281234567899']);
        $otherUser = User::factory()->create();

        $this->postJson('/api/v1/invitations/accept', [
            'token' => $invitationToken,
            'name' => 'Claim Attempt',
            'password' => 'a-secure-test-password',
            'password_confirmation' => 'a-secure-test-password',
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED');

        $this->actingAs($otherUser, 'sanctum')
            ->postJson('/api/v1/invitations/accept', ['token' => $invitationToken])
            ->assertUnprocessable();

        $existingUserToken = $existingUser->createToken('test-device')->plainTextToken;
        Auth::forgetGuards();
        $this->withToken($existingUserToken)
            ->postJson('/api/v1/invitations/accept', ['token' => $invitationToken])
            ->assertOk()
            ->assertJsonPath('data.user.id', $existingUser->id);
    }

    public function test_customer_account_cannot_take_a_staff_invitation(): void
    {
        // Satu akun satu fungsi: menerima undangan penjaga tidak mengubah akun
        // pelanggan menjadi penjaga. Pemilik harus mengundang nomor lain.
        $owner = User::factory()->accountType(AccountType::WarungOwner)->create();
        $business = $this->businessFor($owner);
        $ownerToken = $owner->createToken('test-device')->plainTextToken;
        $issued = $this->withToken($ownerToken)
            ->postJson('/api/v1/businesses/'.$business->id.'/invitations', [
                'phone' => '0812-3456-7800',
                'role' => 'penjaga',
            ])->assertCreated();
        $invitationToken = parse_url($issued->json('data.invitation.url'), PHP_URL_FRAGMENT);

        $customer = User::factory()->accountType(AccountType::Customer)->create(['phone' => '+6281234567800']);
        $customerToken = $customer->createToken('test-device')->plainTextToken;
        Auth::forgetGuards();

        $this->withToken($customerToken)
            ->postJson('/api/v1/invitations/accept', ['token' => $invitationToken])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED');

        $this->assertSame('customer', $customer->fresh()->account_type);
    }

    public function test_web_session_login_can_open_dashboard(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'identity' => $user->phone,
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $this->get('/dashboard')->assertOk();
    }

    public function test_owner_can_update_and_remove_staff_memberships(): void
    {
        $owner = User::factory()->create();
        $business = $this->businessFor($owner);
        $staff = User::factory()->create();
        $business->members()->attach($staff, [
            'role' => 'penjaga',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $membershipId = $business->members()->whereKey($staff->id)->firstOrFail()->pivot->id;
        $token = $owner->createToken('test-device')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/businesses/'.$business->id.'/members')
            ->assertOk()
            ->assertJsonFragment([
                'membership' => ['id' => $membershipId, 'role' => 'penjaga', 'status' => 'active'],
            ]);
        $this->withToken($token)
            ->patchJson('/api/v1/businesses/'.$business->id.'/members/'.$membershipId, ['role' => 'staf'])
            ->assertOk();
        $this->assertDatabaseHas('business_members', [
            'id' => $membershipId,
            'business_id' => $business->id,
            'user_id' => $staff->id,
            'role' => 'staf',
            'status' => 'active',
        ]);

        $this->withToken($token)
            ->deleteJson('/api/v1/businesses/'.$business->id.'/members/'.$staff->id)
            ->assertOk();
        $this->assertDatabaseHas('business_members', [
            'id' => $membershipId,
            'status' => 'inactive',
        ]);

        $this->withToken($token)
            ->deleteJson('/api/v1/businesses/'.$business->id.'/members/'.$owner->id)
            ->assertUnprocessable();
    }

    public function test_permission_catalog_is_limited_to_platform_admins(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-device')->plainTextToken;
        $this->withToken($token)
            ->getJson('/api/v1/permissions')
            ->assertForbidden()
            ->assertJsonPath('code', 'FORBIDDEN');

        PlatformUserRole::query()->create([
            'user_id' => $user->id,
            'role' => 'admin',
        ]);
        $this->withToken($token)
            ->getJson('/api/v1/permissions')
            ->assertOk()
            ->assertJsonFragment(['code' => 'member.invite']);
    }

    public function test_platform_admin_can_be_assigned_to_an_existing_active_user_from_console(): void
    {
        $user = User::factory()->create();

        $this->artisan('app:assign-platform-admin', ['phone' => $user->phone])
            ->expectsOutput('Peran admin platform ditetapkan.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('platform_user_roles', [
            'user_id' => $user->id,
            'role' => 'admin',
        ]);
    }

    public function test_web_invitation_link_onboards_staff_and_starts_a_session(): void
    {
        $owner = User::factory()->create();
        $business = $this->businessFor($owner);
        $this->post('/login', [
            'identity' => $owner->phone,
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $issued = $this->post(route('businesses.invitations.store', $business), [
            'phone' => '0812-3456-7898',
            'role' => 'penjaga',
        ])->assertRedirect(route('businesses.show', $business));
        $invitationUrl = $issued->getSession()->get('invitation_url');
        $this->assertIsString($invitationUrl);
        $this->assertStringContainsString('#', $invitationUrl);
        $token = parse_url($invitationUrl, PHP_URL_FRAGMENT);

        $this->get('/invitations/accept')
            ->assertOk()
            ->assertSee('location.hash.slice(1)');
        $this->post('/invitations/accept/logout', ['token' => $token])
            ->assertRedirect(route('invitations.accept-page').'#'.$token);
        $this->post('/invitations/accept', [
            'token' => $token,
            'name' => 'Web Invited Staff',
            'password' => 'a-secure-test-password',
            'password_confirmation' => 'a-secure-test-password',
        ])->assertRedirect(route('businesses.show', $business));

        $this->assertDatabaseHas('business_members', [
            'business_id' => $business->id,
            'role' => 'penjaga',
            'status' => 'active',
        ]);

        // Beranda meneruskan akun ke ruang kerjanya sendiri, bukan ke halaman umum.
        $this->get('/dashboard')->assertRedirect(route('warung.index'));
        $this->get(route('warung.index'))->assertOk();
        $this->assertDatabaseHas('users', [
            'phone' => '+6281234567898',
            'account_type' => 'warung_guard',
        ]);
    }

    private function businessFor(User $owner): Business
    {
        $business = Business::query()->create([
            'type' => 'warung',
            'name' => 'Warung test '.$owner->id,
            'code' => 'GM-WARUNG-'.str_pad((string) $owner->id, 8, '0', STR_PAD_LEFT),
            'owner_user_id' => $owner->id,
            'status' => 'draft',
        ]);
        $business->members()->attach($owner, [
            'role' => 'pemilik',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return $business;
    }
}
