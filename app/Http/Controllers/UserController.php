<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    /**
     * Guard: only admins may access any action in this controller.
     */
    private function authorizeAdmin(Request $request): void
    {
        if (! $request->user()?->isAdmin()) {
            abort(403, 'Admin access required.');
        }
    }

    /**
     * Paginated list of users with optional search and role filter.
     *
     * GET /api/admin/users?search=&role=&per_page=15&page=1
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $request->validate([
            'search'   => ['nullable', 'string', 'max:100'],
            'role'     => ['nullable', 'in:admin,user'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $users = User::query()
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%' . $request->search . '%';
                $q->where(function ($q2) use ($term) {
                    $q2->where('name', 'like', $term)
                       ->orWhere('email', 'like', $term);
                });
            })
            ->when($request->filled('role'), fn ($q) => $q->where('role', $request->role))
            ->orderBy('id', 'desc')
            ->paginate($request->integer('per_page', 15));

        $users->getCollection()->transform(fn (User $u) => $this->resource($u));

        return response()->json($users);
    }

    /**
     * GET /api/admin/users/{user}
     */
    public function show(Request $request, User $user): JsonResponse
    {
        $this->authorizeAdmin($request);

        return response()->json($this->resource($user));
    }

    /**
     * POST /api/admin/users
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = User::create([
            'name'      => $data['name'],
            'email'     => $data['email'],
            'password'  => Hash::make($data['password']),
            'role'      => $data['role'],
            'phone'     => $data['phone']     ?? null,
            'job_title' => $data['job_title'] ?? null,
            'address'   => $data['address']   ?? null,
        ]);

        // Auto-verify so admin-created accounts can log in immediately
        $user->markEmailAsVerified();

        return response()->json([
            'message' => 'User created successfully.',
            'user'    => $this->resource($user),
        ], 201);
    }

    /**
     * PUT /api/admin/users/{user}
     */
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $data = $request->validated();

        $user->fill([
            'name'      => $data['name'],
            'email'     => $data['email'],
            'role'      => $data['role'],
            'phone'     => $data['phone']     ?? null,
            'job_title' => $data['job_title'] ?? null,
            'address'   => $data['address']   ?? null,
        ]);

        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }

        $user->save();

        return response()->json([
            'message' => 'User updated successfully.',
            'user'    => $this->resource($user),
        ]);
    }

    /**
     * DELETE /api/admin/users/{user}
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->authorizeAdmin($request);

        // Prevent self-deletion
        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'You cannot delete your own account.'], 422);
        }

        // Revoke all tokens before deleting
        $user->tokens()->delete();
        $user->delete();

        return response()->json(['message' => 'User deleted successfully.']);
    }

    /**
     * Consistent user resource shape returned to the frontend.
     */
    private function resource(User $user): array
    {
        return [
            'id'                => $user->id,
            'name'              => $user->name,
            'email'             => $user->email,
            'role'              => $user->role,
            'phone'             => $user->phone,
            'job_title'         => $user->job_title,
            'address'           => $user->address,
            'avatar_url'        => $user->avatarUrl(),
            'email_verified_at' => $user->email_verified_at,
            'created_at'        => $user->created_at,
        ];
    }
}
