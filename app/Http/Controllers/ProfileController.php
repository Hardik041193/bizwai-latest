<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdatePasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    /**
     * Return the authenticated user's full profile.
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json($request->user()->profileResource());
    }

    /**
     * Update name, email, phone, job_title, address, bio.
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        $emailChanged = $data['email'] !== $user->email;

        $user->fill($data);

        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        $user->save();

        if ($emailChanged) {
            $user->sendEmailVerificationNotification();
        }

        return response()->json([
            'message'        => 'Profile updated successfully.',
            'user'           => $user->profileResource(),
            'email_changed'  => $emailChanged,
        ]);
    }

    /**
     * Change password — requires current password verification.
     */
    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! Hash::check($request->current_password, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $user->password = Hash::make($request->password);
        $user->save();

        // Revoke all tokens except the current one so other sessions are invalidated
        $user->tokens()->where('id', '!=', $user->currentAccessToken()->id)->delete();

        return response()->json(['message' => 'Password updated successfully.']);
    }

    /**
     * Upload / replace profile avatar.
     * Stores in storage/app/public/avatars and returns the public URL.
     */
    public function updateAvatar(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048'],
        ]);

        $user = $request->user();

        // Remove old avatar file if it was a local upload
        if ($user->avatar && str_starts_with($user->avatar, 'avatars/')) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($user->avatar);
        }

        $path = $request->file('avatar')->store('avatars', 'public');

        $user->avatar = $path;
        $user->save();

        return response()->json([
            'message'    => 'Avatar updated successfully.',
            'avatar_url' => $user->avatarUrl(),
        ]);
    }
}
