<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    /**
     * GET /api/v1/audit-logs
     */
    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();
        $query = AuditLog::with(['user']);

        // Restrictions: Admin can only read their own audit logs, Super Admin can read all
        if ($user->isAdmin()) {
            $query->where('user_id', $user->id);
        }

        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }

        if ($request->filled('user_id') && $user->isSuperAdmin()) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('start_date')) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                  ->orWhere('user_name', 'like', "%{$search}%");
            });
        }

        $logs = $query->latest()->paginate(25);

        return response()->json($logs);
    }

    /**
     * GET /api/v1/online-users
     * Returns users who made an API request within the last 5 minutes.
     */
    public function onlineUsers(): JsonResponse
    {
        $threshold = now()->subMinutes(5);

        $users = User::whereNotNull('last_seen_at')
            ->where('last_seen_at', '>=', $threshold)
            ->orderByDesc('last_seen_at')
            ->get(['id', 'name', 'role', 'last_seen_at'])
            ->map(fn (User $user) => [
                'id'          => $user->id,
                'name'        => $user->name,
                'role'        => $user->role->value,
                'role_label'  => $user->role->label(),
                'last_seen_at' => $user->last_seen_at,
            ]);

        return response()->json([
            'data'  => $users,
            'count' => $users->count(),
        ]);
    }

    /**
     * GET /api/v1/audit-logs/{auditLog}
     */
    public function show(AuditLog $auditLog): JsonResponse
    {
        $user = auth()->user();
        if ($user->isAdmin() && $auditLog->user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        return response()->json([
            'data' => $auditLog->load(['user']),
        ]);
    }
}
