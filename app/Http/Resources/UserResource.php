<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'headline' => $this->headline,
            'bio' => $this->bio,
            'avatar_url' => $this->avatar_path ? asset('storage/'.$this->avatar_path) : null,
            'roles' => $this->roles->pluck('name'),
            'role_labels' => $this->roles->pluck('label'),
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
