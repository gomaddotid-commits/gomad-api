<?php

namespace Tests\Feature;

use App\Core\Access\Infrastructure\AccessControlSeeder;
use App\Core\Identity\Domain\AccountType;
use App\Core\Ledger\Infrastructure\LedgerAccountSeeder;
use App\Models\Business;
use App\Models\PlatformUserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Satu akun satu fungsi.
 *
 * Tes ini mengunci janji produknya: akun pelanggan tidak bisa masuk ruang kerja
 * mitra, akun warung tidak bisa masuk ruang kerja agency, dan akun ops tidak bisa
 * membuka halaman administrasi platform. Penjagaan terjadi di rute (`area:`), jadi
 * menebak URL tidak menolong siapa pun.
 */
class AccountAreaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        app(LedgerAccountSeeder::class)->run();
    }

    public function test_customer_cannot_enter_any_work_area(): void
    {
        $customer = User::factory()->accountType(AccountType::Customer)->create(['status' => 'active']);
        [$warung, $agency] = $this->businesses($customer);

        foreach ([
            '/mad-warung',
            '/mad-warung/'.$warung->getKey(),
            '/mad-warung/'.$warung->getKey().'/katalog',
            '/mad-trans/agency',
            '/mad-trans/agency/'.$agency->getKey(),
            '/mad-trans/driver',
            '/admin',
        ] as $path) {
            $this->actingAs($customer)->get($path)->assertForbidden();
        }
    }

    public function test_warung_owner_cannot_enter_agency_or_platform_area(): void
    {
        [$warung, $agency] = $this->businesses();
        $owner = User::factory()->accountType(AccountType::WarungOwner)->create(['status' => 'active']);
        $this->join($warung, $owner, 'pemilik');

        $this->actingAs($owner)->get('/mad-warung/'.$warung->getKey())->assertOk();
        $this->actingAs($owner)->get('/mad-trans/agency/'.$agency->getKey())->assertForbidden();
        $this->actingAs($owner)->get('/mad-trans')->assertForbidden();
        $this->actingAs($owner)->get('/admin')->assertForbidden();
    }

    public function test_agency_owner_cannot_enter_warung_area(): void
    {
        [$warung, $agency] = $this->businesses();
        $owner = User::factory()->accountType(AccountType::AgencyOwner)->create(['status' => 'active']);
        $this->join($agency, $owner, 'pemilik');

        $this->actingAs($owner)->get('/mad-trans/agency/'.$agency->getKey())->assertOk();
        $this->actingAs($owner)->get('/mad-warung/'.$warung->getKey())->assertForbidden();
        $this->actingAs($owner)->get('/admin')->assertForbidden();
    }

    public function test_ops_runs_the_queue_but_not_the_administration(): void
    {
        $ops = $this->platformUser(AccountType::Ops, 'ops');

        foreach (['/admin', '/admin/pencairan', '/admin/mitra', '/admin/rekonsiliasi', '/admin/dukungan'] as $path) {
            $this->actingAs($ops)->get($path)->assertOk();
        }

        foreach (['/admin/pengguna', '/admin/parameter', '/admin/settlement', '/admin/promos'] as $path) {
            $this->actingAs($ops)->get($path)->assertForbidden();
        }
    }

    public function test_admin_can_open_administration_pages(): void
    {
        $admin = $this->platformUser(AccountType::Admin, 'admin');

        foreach (['/admin', '/admin/pencairan', '/admin/mitra', '/admin/rekonsiliasi', '/admin/pengguna', '/admin/parameter'] as $path) {
            $this->actingAs($admin)->get($path)->assertOk();
        }
    }

    public function test_customer_cannot_create_a_business(): void
    {
        $customer = User::factory()->accountType(AccountType::Customer)->create(['status' => 'active']);

        $this->actingAs($customer)
            ->post('/businesses', ['name' => 'Warung Baru', 'type' => 'warung'])
            ->assertSessionHasErrors('type');

        $this->assertSame(0, Business::query()->where('name', 'Warung Baru')->count());
    }

    public function test_warung_owner_can_only_create_a_warung(): void
    {
        $owner = User::factory()->accountType(AccountType::WarungOwner)->create(['status' => 'active']);

        $this->actingAs($owner)
            ->post('/businesses', ['name' => 'Grosir Salah Tipe', 'type' => 'grosir'])
            ->assertSessionHasErrors('type');

        $this->actingAs($owner)
            ->post('/businesses', ['name' => 'Warung Benar', 'type' => 'warung'])
            ->assertRedirect();

        $this->assertSame(1, Business::query()->where('name', 'Warung Benar')->count());
    }

    public function test_registration_records_the_chosen_account_type(): void
    {
        $this->post('/register', [
            'name' => 'Pemilik Grosir',
            'phone' => '0812-3456-7700',
            'password' => 'a-secure-test-password',
            'password_confirmation' => 'a-secure-test-password',
            'account_type' => 'grosir_owner',
        ])->assertRedirect(route('dashboard'));

        $user = User::query()->where('phone', '+6281234567700')->firstOrFail();
        $this->assertSame('grosir_owner', $user->account_type);
    }

    public function test_registration_rejects_staff_account_types(): void
    {
        $this->post('/register', [
            'name' => 'Calon Supir',
            'phone' => '0812-3456-7701',
            'password' => 'a-secure-test-password',
            'password_confirmation' => 'a-secure-test-password',
            'account_type' => 'driver',
        ])->assertSessionHasErrors('account_type');
    }

    public function test_navigation_only_shows_own_function(): void
    {
        $customer = User::factory()->accountType(AccountType::Customer)->create(['status' => 'active']);

        $this->actingAs($customer)->get('/dashboard')
            ->assertOk()
            ->assertSee('Pesanan saya')
            ->assertDontSee('Ruang kerja warung')
            ->assertDontSee('Konsol');

        [$warung] = $this->businesses();
        $owner = User::factory()->accountType(AccountType::WarungOwner)->create(['status' => 'active']);
        $this->join($warung, $owner, 'pemilik');

        $this->actingAs($owner)->get('/usaha')
            ->assertOk()
            ->assertSee('Ruang kerja warung')
            ->assertDontSee('Pesanan saya');
    }

    private function platformUser(AccountType $type, string $role): User
    {
        $user = User::factory()->accountType($type)->create(['status' => 'active']);
        PlatformUserRole::query()->create(['user_id' => $user->getKey(), 'role' => $role]);

        return $user;
    }

    /**
     * @return array{0: Business, 1: Business}
     */
    private function businesses(?User $owner = null): array
    {
        $owner ??= User::factory()->create();

        $warung = $this->business($owner, 'warung', 'GM-WARUNG-AREA1', 'Warung Area');
        $agency = $this->business($owner, 'agency', 'GM-AGENCY-AREA1', 'Agency Area');

        return [$warung, $agency];
    }

    private function business(User $owner, string $type, string $code, string $name): Business
    {
        return Business::query()->create([
            'type' => $type,
            'name' => $name,
            'code' => $code,
            'owner_user_id' => $owner->getKey(),
            'status' => 'active',
        ]);
    }

    private function join(Business $business, User $user, string $role): void
    {
        $business->members()->attach($user->getKey(), [
            'role' => $role,
            'status' => 'active',
            'joined_at' => now(),
        ]);
    }
}
