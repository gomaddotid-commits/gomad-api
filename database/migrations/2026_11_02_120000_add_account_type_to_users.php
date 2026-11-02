<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Satu akun = satu fungsi.
 *
 * Sebelumnya satu pengguna bisa menjadi admin platform sekaligus pemilik
 * beberapa usaha. Kolom `account_type` menutup itu: tipe akun menentukan area
 * yang boleh dibuka (lihat App\Core\Identity\Domain\AccountType).
 *
 * Data lama diisi ulang dengan urutan yang masuk akal: staf dulu, lalu pemilik
 * usaha, lalu peran platform (admin menang), sisanya pelanggan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('account_type', 30)->default('customer')->after('status')->index();
        });

        // Staf: penjaga warung, supir, staf grosir/agency.
        DB::table('business_members')
            ->join('businesses', 'businesses.id', '=', 'business_members.business_id')
            ->where('business_members.status', 'active')
            ->whereIn('business_members.role', ['penjaga', 'driver', 'staf'])
            ->select('business_members.user_id', 'business_members.role', 'businesses.type')
            ->orderBy('business_members.id')
            ->get()
            ->each(function (object $row): void {
                $type = match (true) {
                    $row->type === 'warung' => 'warung_guard',
                    $row->type === 'grosir' => 'grosir_staff',
                    $row->role === 'driver' => 'driver',
                    default => 'agency_staff',
                };

                DB::table('users')->where('id', $row->user_id)->update(['account_type' => $type]);
            });

        // Pemilik usaha (menimpa peran staf bila keduanya ada).
        DB::table('businesses')
            ->whereNotNull('owner_user_id')
            ->select('owner_user_id', 'type')
            ->get()
            ->each(function (object $row): void {
                $type = match ($row->type) {
                    'warung' => 'warung_owner',
                    'grosir' => 'grosir_owner',
                    'agency' => 'agency_owner',
                    default => null,
                };

                if ($type !== null) {
                    DB::table('users')->where('id', $row->owner_user_id)->update(['account_type' => $type]);
                }
            });

        // Peran platform adalah fungsi tersendiri dan menimpa keduanya.
        DB::table('platform_user_roles')
            ->where('role', 'admin')
            ->pluck('user_id')
            ->each(fn (int $userId) => DB::table('users')->where('id', $userId)->update(['account_type' => 'admin']));
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['account_type']);
            $table->dropColumn('account_type');
        });
    }
};
