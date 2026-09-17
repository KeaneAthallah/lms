<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Http\Resources\UserResource;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    public function update(ProfileUpdateRequest $request)
    {
        $user = $request->user();

        $data = $request->safe()->only(['name', 'email', 'headline', 'bio']);

        if ($request->hasFile('avatar')) {
            if ($user->avatar_path) {
                Storage::disk('public')->delete($user->avatar_path);
            }

            $data['avatar_path'] = $request->file('avatar')->store('avatars', 'public');
        }

        $user->update($data);

        return (new UserResource($user->load('roles')))
            ->additional(['message' => 'Profile updated successfully.']);
    }
}
