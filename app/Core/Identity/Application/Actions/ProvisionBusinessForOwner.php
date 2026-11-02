<?php

namespace App\Core\Identity\Application\Actions;

use App\Core\Identity\Domain\AccountType;
use App\Models\Business;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Menyiapkan entitas usaha untuk akun mitra yang baru mendaftar.
 *
 * Satu akun satu fungsi: tipe akun sudah menentukan jenis usaha, jadi pemilik
 * tidak perlu memilih jenis usaha lagi. Usaha dibuat sebagai **draft** dengan
 * nama sementara; pemilik melengkapi namanya, lalu ops memverifikasi.
 *
 * Akun pelanggan, supir, dan staf tidak mendapat usaha — mereka bukan pemilik.
 */
final class ProvisionBusinessForOwner
{
    public function execute(User $user): ?Business
    {
        $type = $user->accountType()->businessType();

        if ($type === null) {
            return null;
        }

        $existing = Business::query()->where('owner_user_id', $user->getKey())->where('type', $type)->first();

        if ($existing !== null) {
            return $existing;
        }

        $label = match ($user->accountType()) {
            AccountType::WarungOwner => 'Warung',
            AccountType::GrosirOwner => 'Grosir',
            AccountType::AgencyOwner => 'Travel & Rental',
            default => 'Usaha',
        };

        $business = Business::query()->create([
            'type' => $type,
            'name' => trim($label.' '.$user->name),
            'code' => 'GM-'.strtoupper($type).'-'.strtoupper(Str::random(8)),
            'owner_user_id' => $user->getKey(),
            'status' => 'draft',
        ]);

        $business->members()->attach($user->getKey(), [
            'role' => 'pemilik',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return $business;
    }
}
