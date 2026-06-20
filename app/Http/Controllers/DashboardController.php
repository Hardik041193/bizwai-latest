<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function stats(): JsonResponse
    {
        // Exclude admin role from all counts
        $baseQuery = User::where('role', '!=', 'admin');

        $totalUsers    = (clone $baseQuery)->count();

        // Active Users = status 1 (Approved)
        $activeUsers   = (clone $baseQuery)->where('status', 1)->count();

        // QBO Active Users = qbo_status 1 (Connected)
        $qboActive     = (clone $baseQuery)->where('qbo_status', 1)->count();

        // Pending Verification = email_verified_at is NULL
        $pendingVerif  = (clone $baseQuery)->whereNull('email_verified_at')->count();

        return response()->json([
            'total_users'   => $totalUsers,
            'active_users'  => $activeUsers,
            'qbo_active'    => $qboActive,
            'pending_verif' => $pendingVerif,
        ]);
    }
}
