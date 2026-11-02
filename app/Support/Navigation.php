<?php

namespace App\Support;

use App\Core\Access\Application\ActionAuthorizer;
use App\Core\Identity\Domain\AccountType;
use App\Models\Business;
use App\Models\User;

/**
 * Navigasi web yang sadar fungsi akun.
 *
 * Satu aplikasi, banyak fungsi, satu akun satu fungsi (lihat AccountType). Menu
 * diturunkan dari tipe akun — sumber kebenaran yang sama dengan `area:` pada rute —
 * sehingga menu dan penjagaan tidak bisa berbeda pendapat: yang tidak boleh dibuka
 * juga tidak muncul, dan yang muncul pasti bisa dibuka.
 *
 * Header hanya memuat pintu masuk, bukan seluruh isi ruang kerja. Daftar halaman
 * per pekerjaan sudah ada di navigasi dalam ruang kerja (`_nav.blade.php`).
 */
final class Navigation
{
    public function __construct(private readonly ActionAuthorizer $authorizer) {}

    /**
     * @return list<array{label: string, href: string, active: bool}>
     */
    public function primary(?User $user): array
    {
        return $user === null ? $this->guestItems() : $this->memberItems($user);
    }

    /**
     * Tamu hanya melihat toko depan. Tidak ada satu pun pintu ruang kerja di sini.
     *
     * @return list<array{label: string, href: string, active: bool}>
     */
    private function guestItems(): array
    {
        return [
            $this->item('Cari jadwal', route('public.search'), ['public.search', 'public.schedule']),
            $this->item('Mad Warung', route('warung.public.index'), ['warung.public.*']),
            $this->item('Jadi mitra', route('public.partners'), ['public.partners']),
            $this->item('Unduh aplikasi', route('public.download'), ['public.download']),
        ];
    }

    /**
     * @return list<array{label: string, href: string, active: bool}>
     */
    private function memberItems(User $user): array
    {
        $type = $user->accountType();

        return match ($type) {
            AccountType::Customer => $this->customerItems(),
            AccountType::WarungOwner, AccountType::WarungGuard,
            AccountType::GrosirOwner, AccountType::GrosirStaff => $this->outletItems($user, $type),
            AccountType::AgencyOwner, AccountType::AgencyStaff => $this->agencyItems($user),
            AccountType::Driver => $this->driverItems(),
            AccountType::Ops, AccountType::Admin => $this->platformItems($user),
        };
    }

    /**
     * Pelanggan: berbelanja, bukan mengelola.
     *
     * @return list<array{label: string, href: string, active: bool}>
     */
    private function customerItems(): array
    {
        return [
            $this->item('Beranda', route('dashboard'), ['dashboard']),
            $this->item('Cari jadwal', route('mad-trans.index'), ['mad-trans.index', 'mad-trans.schedules.*']),
            $this->item('Pesanan saya', route('mad-trans.bookings.index'), ['mad-trans.bookings.*']),
            $this->item('Sewa saya', route('account.rentals'), ['account.rentals', 'rental.*', 'rental-documents.*']),
            $this->item('Mad Warung', route('warung.public.index'), ['warung.public.*']),
            $this->item('Level & misi', route('loyalty.index'), ['loyalty.*']),
        ];
    }

    /**
     * Warung dan grosir berbagi satu ruang kerja, tetapi penjaga tidak melihat
     * halaman pemilik (penjaga, laporan) dan grosir tidak punya penjaga.
     *
     * @return list<array{label: string, href: string, active: bool}>
     */
    private function outletItems(User $user, AccountType $type): array
    {
        $businessType = in_array($type, [AccountType::GrosirOwner, AccountType::GrosirStaff], true)
            ? 'grosir'
            : 'warung';

        $business = $this->firstBusiness($user, $businessType);
        $label = $businessType === 'grosir' ? 'Ruang kerja grosir' : 'Ruang kerja warung';

        if ($business === null) {
            return [$this->item($label, route('businesses.index'), ['dashboard', 'businesses.*', 'warung.*'])];
        }

        return [
            $this->item($label, route('warung.show', $business), ['warung.*']),
            $this->item('Usaha saya', route('businesses.index'), ['businesses.*']),
        ];
    }

    /**
     * @return list<array{label: string, href: string, active: bool}>
     */
    private function agencyItems(User $user): array
    {
        $agency = $this->firstBusiness($user, 'agency');

        // Ruang kerja agency dijaga izin `schedule.manage`. Staf agency belum punya
        // izin itu, jadi menunya diarahkan ke daftar usaha — bukan ke halaman yang
        // akan menolaknya. Ruang kerja staf agency menyusul di fase berikutnya.
        if ($agency === null || ! $this->authorizer->allows($user, 'schedule.manage', $agency)) {
            return [$this->item('Usaha saya', route('businesses.index'), ['dashboard', 'businesses.*', 'mad-trans.agency.*'])];
        }

        return [
            $this->item('Ruang kerja agency', route('mad-trans.agency.show', $agency), [
                'mad-trans.agency.*',
            ]),
            $this->item('Usaha saya', route('businesses.index'), ['businesses.*']),
        ];
    }

    /**
     * Supir hanya punya satu pekerjaan: jadwal yang ditugaskan kepadanya.
     *
     * @return list<array{label: string, href: string, active: bool}>
     */
    private function driverItems(): array
    {
        return [
            $this->item('Jadwal saya', route('mad-trans.driver.index'), ['mad-trans.driver.*']),
        ];
    }

    /**
     * Platform: ops dan admin melihat konsol, tetapi hanya admin melihat halaman
     * administrasi (pengguna, parameter, settlement). Izin, bukan tebakan menu.
     *
     * @return list<array{label: string, href: string, active: bool}>
     */
    private function platformItems(User $user): array
    {
        $items = [
            $this->item('Konsol', route('admin.dashboard'), ['admin.dashboard']),
        ];

        if ($this->authorizer->allows($user, 'withdrawal.review')) {
            $items[] = $this->item('Pencairan', route('admin.withdrawals'), ['admin.withdrawals*']);
        }

        if ($this->authorizer->allows($user, 'business.verify')) {
            $items[] = $this->item('Mitra', route('admin.partners'), ['admin.partners*']);
        }

        if ($this->authorizer->allows($user, 'ops.view')) {
            $items[] = $this->item('Rekonsiliasi', route('admin.reconciliation'), ['admin.reconciliation']);
        }

        if ($this->authorizer->allows($user, 'settlement.execute')) {
            $items[] = $this->item('Settlement', route('admin.settlement'), ['admin.settlement*']);
        }

        if ($this->authorizer->allows($user, 'permissions.read')) {
            $items[] = $this->item('Pengguna & peran', route('admin.users'), ['admin.users']);
            $items[] = $this->item('Parameter', route('admin.parameters'), ['admin.parameters']);
        }

        return $items;
    }

    /**
     * Menu akun, ditampilkan di sisi kanan header — bukan bagian navigasi kerja.
     *
     * Notifikasi membawa angka karena kotak masuk hanya berguna sebesar isi yang
     * menunggu; tidak ada item lain yang boleh memasang angka serupa.
     *
     * @return list<array{label: string, href: string, active: bool, count?: int}>
     */
    public function account(User $user): array
    {
        $unread = $user->unreadNotifications()->count();

        return [
            [
                'label' => 'Notifikasi',
                'href' => route('notifications.index'),
                'active' => request()->routeIs('notifications.*'),
                'count' => $unread,
            ],
            [
                'label' => 'Profil',
                'href' => route('account.show'),
                'active' => request()->routeIs('account.*'),
                'count' => 0,
            ],
            [
                'label' => 'Bantuan',
                'href' => route('support.index'),
                'active' => request()->routeIs('support.*'),
                'count' => 0,
            ],
        ];
    }

    /**
     * Usaha pertama milik pengguna dengan tipe tertentu — cukup untuk menaruh satu
     * pintu masuk di navigasi; pemilihan usaha tetap dilakukan di halaman usaha.
     */
    private function firstBusiness(User $user, string $type): ?Business
    {
        return Business::query()
            ->where('type', $type)
            ->where(fn ($query) => $query
                ->where('owner_user_id', $user->getKey())
                ->orWhereHas('members', fn ($members) => $members
                    ->whereKey($user->getKey())
                    ->where('business_members.status', 'active')))
            ->orderBy('name')
            ->first();
    }

    /**
     * @param  list<string>  $patterns
     * @return array{label: string, href: string, active: bool}
     */
    private function item(string $label, string $href, array $patterns): array
    {
        return [
            'label' => $label,
            'href' => $href,
            'active' => request()->routeIs(...$patterns),
        ];
    }
}
