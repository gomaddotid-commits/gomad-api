<?php

namespace App\Core\Identity\Application\Actions;

use App\Core\Access\Application\ActionAuthorizer;
use App\Models\Business;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class CreateBusiness
{
    public function __construct(private readonly ActionAuthorizer $authorizer) {}

    public function execute(User $owner, string $name, string $type): Business
    {
        $this->authorizer->authorize($owner, 'business.create');

        $allowed = $owner->accountType()->businessType();

        if ($allowed === null) {
            throw new InvalidArgumentException('Akun '.$owner->accountType()->label().' tidak dapat membuat usaha. Daftar akun mitra lebih dulu.');
        }

        if ($allowed !== $type) {
            throw new InvalidArgumentException('Akun ini hanya dapat membuat usaha bertipe '.$allowed.'.');
        }

        return DB::transaction(function () use ($owner, $name, $type): Business {
            $business = Business::query()->create([
                'type' => $type,
                'name' => trim($name),
                'code' => 'GM-'.strtoupper($type).'-'.strtoupper(Str::random(8)),
                'owner_user_id' => $owner->getKey(),
                'status' => 'draft',
            ]);

            $business->members()->attach($owner->getKey(), [
                'role' => 'pemilik',
                'status' => 'active',
                'joined_at' => now(),
            ]);

            return $business;
        });
    }
}
