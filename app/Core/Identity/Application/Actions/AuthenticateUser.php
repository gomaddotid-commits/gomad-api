<?php

namespace App\Core\Identity\Application\Actions;

use App\Core\Identity\Domain\PhoneNumber;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;

final class AuthenticateUser
{
    public function execute(string $identity, string $password): ?User
    {
        $identity = trim($identity);
        $user = null;

        if (str_contains($identity, '@')) {
            $user = User::query()->whereRaw('lower(email) = ?', [mb_strtolower($identity)])->first();
        } else {
            try {
                $phone = PhoneNumber::fromInput($identity)->value;
                $user = User::query()->where('phone', $phone)->first();
            } catch (InvalidArgumentException) {
                return null;
            }
        }

        if ($user === null || $user->status !== 'active' || ! Hash::check($password, $user->password)) {
            return null;
        }

        $user->forceFill(['last_login_at' => now()])->save();

        return $user;
    }
}
