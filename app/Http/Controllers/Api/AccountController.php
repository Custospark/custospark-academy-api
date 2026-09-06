<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    /** Update the authenticated user's own profile (name + phone; email is identity). */
    public function updateProfile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        $user = $request->user();
        $user->update($validated);

        return response()->json(['data' => $this->serialize($user)]);
    }

    /** Change password after proving the current one. */
    public function updatePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $request->user();
        if (! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'Your current password is incorrect.',
            ]);
        }

        $user->update(['password' => Hash::make($validated['password'])]);

        return response()->json(['data' => null, 'message' => 'Password updated.']);
    }

    /** Upload/replace the user's own profile picture. */
    public function uploadAvatar(Request $request): JsonResponse
    {
        $request->validate(['avatar' => ['required', 'image', 'max:2048']]);

        $user = $request->user();
        $old = $user->getRawOriginal('avatar_url');
        $path = $request->file('avatar')->store('avatars', 'public');
        $user->update(['avatar_url' => $path]);

        if ($old) {
            Storage::disk('public')->delete($old);
        }

        return response()->json(['data' => $this->serialize($user->fresh())]);
    }

    /** @param  \App\Models\User  $user */
    private function serialize($user): array
    {
        // avatar_url resolves to an absolute URL via the User accessor.
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
            'status' => $user->status,
            'avatar_url' => $user->avatar_url,
        ];
    }
}
