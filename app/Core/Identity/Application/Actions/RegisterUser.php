<?php

namespace App\Core\Identity\Application\Actions;

use App\Core\Identity\Domain\AccountType;
use App\Core\Identity\Domain\PhoneNumber;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class RegisterUser
{
    public function execute(string $name, string $phone, ?string $email, string $password, AccountType $accountType = AccountType::Customer): User
    {
        try {
            $normalizedPhone = PhoneNumber::fromInput($phone)->value;
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['phone' => [$exception->getMessage()]]);
        }

        try {
            return DB::transaction(fn (): User => User::query()->create([
                'name' => trim($name),
                'phone' => $normalizedPhone,
                'email' => $email === null ? null : mb_strtolower(trim($email)),
                'password' => $password,
                'status' => 'active',
                'account_type' => $accountType->value,
            ]));
        } catch (QueryException $exception) {
            if (User::query()->where('phone', $normalizedPhone)->exists()) {
                throw ValidationException::withMessages([
                    'phone' => ['Nomor telepon sudah terdaftar.'],
                ]);
            }

            if ($email !== null && User::query()->whereRaw('lower(email) = ?', [mb_strtolower(trim($email))])->exists()) {
                throw ValidationException::withMessages([
                    'email' => ['Email sudah terdaftar.'],
                ]);
            }

            throw $exception;
        }
    }
}
