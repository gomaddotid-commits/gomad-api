<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'account_type' => $this->accountType()->value,
            'phone_verified_at' => $this->phone_verified_at?->toIso8601String(),
            'membership' => $this->whenPivotLoaded('business_members', fn (): array => [
                'id' => $this->pivot->id,
                'role' => $this->pivot->role,
                'status' => $this->pivot->status,
            ]),
        ];
    }
}
