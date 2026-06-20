<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Register a new user and send verification email.
     */
    public function register(Request $request): JsonResponse
    {
        $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'phone' => 'nullable|regex:/^[0-9+\-\s()]+$/|min:10|max:20',
            'company_name' => 'nullable|string|max:255',
        ]);

        $user = User::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'phone'        => $request->phone,
            'company_name' => $request->company_name,
            'password' => Hash::make($request->password),
            'role'     => 'user',
        ]);

        $user->sendEmailVerificationNotification();

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'message'              => 'Registration successful. Please verify your email.',
            'requires_verification' => true,
            'user'                 => $user,
            'token'                => $token,
        ], 201);
    }

    /**
     * Login for regular users (role = user).
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($user->isAdmin()) {
            throw ValidationException::withMessages([
                'email' => ['Admin accounts must use the Admin Login portal.'],
            ]);
        }

        if (! $user->isVerified()) {
            return response()->json([
                'message'              => 'Please verify your email before logging in.',
                'requires_verification' => true,
            ], 403);
        }

        // Status 1 = Approved → allow login
        // (optional: block any unknown status values too)
        if ((int) $user->status !== 1) {
            return response()->json([
                'message'        => 'Your account is pending approval. Please wait for an administrator to approve your account.',
                'account_pending' => true,
            ], 403);
        }

        $user->tokens()->delete();
        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json(['user' => $user, 'token' => $token]);
    }

    /**
     * Login for admin users only.
     */
    public function adminLogin(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->isAdmin()) {
            throw ValidationException::withMessages([
                'email' => ['Access denied. This portal is for administrators only.'],
            ]);
        }

        if (! $user->isVerified()) {
            return response()->json([
                'message'              => 'Please verify your email before logging in.',
                'requires_verification' => true,
            ], 403);
        }

        $user->tokens()->delete();
        $token = $user->createToken('admin-token')->plainTextToken;

        return response()->json(['user' => $user, 'token' => $token]);
    }

    /**
     * Send a password reset link for regular front users only.
     *
     * Admin accounts intentionally do not use this front-user flow.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || $user->isAdmin()) {
            return response()->json([
                'message' => 'If a matching user account exists, a password reset link has been sent.',
            ]);
        }

        Password::sendResetLink($request->only('email'));

        return response()->json([
            'message' => 'If a matching user account exists, a password reset link has been sent.',
        ]);
    }

    /**
     * Reset password for regular front users only.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token'    => ['required', 'string'],
            'email'    => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || $user->isAdmin()) {
            throw ValidationException::withMessages([
                'email' => ['This password reset link is invalid.'],
            ]);
        }

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                $user->tokens()->delete();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return response()->json([
            'message' => 'Password reset successfully. You can now sign in.',
        ]);
    }

    /**
     * Verify email — called by the SPA (not opened directly in browser).
     *
     * The SPA reads the signed URL params from the email link and calls this
     * endpoint via axios. Returns JSON so the SPA can handle success/error.
     */
    public function verifyEmail(Request $request, int $id, string $hash): JsonResponse
    {
        $user = User::findOrFail($id);

        if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return response()->json(['message' => 'Invalid verification link.'], 403);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified.', 'already_verified' => true]);
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return response()->json(['message' => 'Email verified successfully.']);
    }

    /**
     * Resend the verification email.
     */
    public function resendVerification(Request $request): JsonResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified.']);
        }

        $request->user()->sendEmailVerificationNotification();

        return response()->json(['message' => 'Verification email sent.']);
    }

    /**
     * Get authenticated user (includes computed fields like avatar_url).
     */
    public function user(Request $request): JsonResponse
    {
        return response()->json($request->user()->profileResource());
    }

    /**
     * Logout.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }
}
