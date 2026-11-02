<?php

namespace App\Core\Access\Infrastructure;

use App\Models\Permission;
use App\Models\RolePermission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AccessControlSeeder extends Seeder
{
    private const PERMISSIONS = [
        'profile.read' => ['Lihat profil', 'identity'],
        'profile.update' => ['Ubah profil', 'identity'],
        'membership.read' => ['Lihat keanggotaan', 'identity'],
        'business.create' => ['Buat usaha', 'business'],
        'business.read' => ['Lihat usaha', 'business'],
        'business.update' => ['Ubah usaha', 'business'],
        'business.verify' => ['Verifikasi mitra', 'business'],
        'ops.view' => ['Lihat konsol operasional', 'ops'],
        'member.read' => ['Lihat anggota', 'access'],
        'member.invite' => ['Undang anggota', 'access'],
        'member.update' => ['Ubah keanggotaan', 'access'],
        'member.remove' => ['Nonaktifkan anggota', 'access'],
        'permissions.read' => ['Lihat izin', 'access'],
        'settlement.execute' => ['Jalankan settlement', 'ledger'],
        'collateral.topup' => ['Top up jaminan agency', 'payment'],
        'withdrawal.request' => ['Ajukan pencairan', 'ledger'],
        'withdrawal.review' => ['Tinjau pencairan', 'ledger'],
        'catalog.read' => ['Lihat katalog grosir', 'mad-warung'],
        'catalog.manage' => ['Kelola harga dan stok grosir', 'mad-warung'],
        'catalog.publish' => ['Kelola katalog produk platform', 'mad-warung'],
        'order.create' => ['Buat pesanan warung', 'mad-warung'],
        'order.approve' => ['Setujui pesanan warung', 'mad-warung'],
        'order.pay' => ['Bayar pesanan warung', 'mad-warung'],
        'order.fulfill' => ['Siapkan pesanan grosir', 'mad-warung'],
        'order.receive' => ['Konfirmasi penerimaan pesanan', 'mad-warung'],
        'order.report' => ['Lihat laporan pesanan warung', 'mad-warung'],
        'member_limit.manage' => ['Kelola plafon penjaga', 'mad-warung'],
        'shift.manage' => ['Kelola jadwal shift', 'mad-warung'],
        'shift.self' => ['Mulai dan akhiri shift sendiri', 'mad-warung'],
        'pos.sell' => ['Jalankan kasir warung', 'mad-warung'],
        'pos.report' => ['Lihat penjualan kasir warung', 'mad-warung'],
        'booking.create' => ['Pesan perjalanan Mad Trans', 'mad-trans'],
        'booking.read' => ['Lihat pesanan perjalanan sendiri', 'mad-trans'],
        'booking.cancel' => ['Batalkan pesanan perjalanan sendiri', 'mad-trans'],
        'schedule.manage' => ['Kelola jadwal dan armada travel', 'mad-trans'],
        'schedule.approve' => ['Setujui jadwal travel', 'mad-trans'],
        'trip.manage' => ['Mulai dan selesaikan perjalanan', 'mad-trans'],
    ];

    private const ROLE_PERMISSIONS = [
        'pihak_luar' => [
            'profile.read', 'profile.update', 'membership.read', 'business.create',
            'booking.create', 'booking.read', 'booking.cancel',
        ],
        'pemilik' => [
            'profile.read', 'profile.update', 'membership.read',
            'business.create', 'business.read', 'business.update',
            'member.read', 'member.invite', 'member.update', 'member.remove',
            'collateral.topup',
            'withdrawal.request',
            'catalog.read',
            'order.create',
            'order.approve',
            'order.pay',
            'catalog.manage',
            'order.receive',
            'order.fulfill',
            'order.report',
            'member_limit.manage',
            'shift.manage',
            'pos.report',
            'schedule.manage',
            'schedule.approve',
            'trip.manage',
        ],
        'penjaga' => [
            'profile.read', 'profile.update', 'membership.read', 'business.read',
            'catalog.read', 'order.create', 'order.receive', 'shift.self', 'pos.sell',
        ],
        'driver' => [
            'profile.read', 'profile.update', 'membership.read', 'business.read',
            'catalog.read', 'catalog.manage', 'order.fulfill', 'order.report',
            'trip.manage',
        ],
        'staf' => [
            'profile.read', 'profile.update', 'membership.read', 'business.read',
            'catalog.read', 'catalog.manage', 'order.fulfill', 'order.report',
            'trip.manage',
        ],
        // Ops menjalankan pekerjaan harian platform: tinjau pencairan, verifikasi
        // mitra, dan rekonsiliasi. Administrasi (pengguna, parameter, settlement,
        // promo) tetap milik admin — lihat peran di bawah.
        'ops' => [
            'profile.read', 'profile.update',
            'business.read', 'business.verify',
            'ops.view',
            'withdrawal.review',
            'order.report',
        ],
        'admin' => [
            'profile.read', 'profile.update', 'membership.read',
            'business.create', 'business.read', 'business.update', 'business.verify',
            'member.read', 'member.invite', 'member.update', 'member.remove',
            'permissions.read',
            'ops.view',
            'settlement.execute',
            'withdrawal.review',
            'catalog.publish',
            'order.report',
        ],
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            $permissionIds = [];

            foreach (self::PERMISSIONS as $code => [$label, $group]) {
                $permission = Permission::query()->updateOrCreate(
                    ['code' => $code],
                    ['label' => $label, 'group' => $group],
                );
                $permissionIds[$code] = $permission->getKey();
            }

            RolePermission::query()->whereIn('role', array_keys(self::ROLE_PERMISSIONS))->delete();

            foreach (self::ROLE_PERMISSIONS as $role => $codes) {
                foreach ($codes as $code) {
                    RolePermission::query()->create([
                        'role' => $role,
                        'permission_id' => $permissionIds[$code],
                    ]);
                }
            }
        });
    }
}
