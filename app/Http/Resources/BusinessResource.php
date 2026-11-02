<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BusinessResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'name' => $this->name,
            'code' => $this->code,
            'owner_user_id' => $this->owner_user_id,
            'status' => $this->status,
            'role' => $this->whenPivotLoaded('business_members', fn () => $this->pivot->role),
            'active_members_count' => $this->when(
                isset($this->active_members_count),
                fn () => $this->active_members_count,
            ),
        ];
    }
}
