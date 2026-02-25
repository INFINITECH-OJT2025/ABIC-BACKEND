<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ActivityLog;

class ActivityLogController extends Controller
{
    /**
     * Check if user has permission to access activity logs.
     */
    protected function checkAuthorization(Request $request): void
    {
        $user = $request->user();

        if (!$user) {
            abort(401, 'Unauthorized');
        }

        $userRole = strtolower($user->role ?? '');
        $allowedRoles = ['accountant', 'super_admin', 'admin'];

        if (!in_array($userRole, $allowedRoles)) {
            abort(403, 'Insufficient permissions.');
        }
    }

    /**
     * List activity logs with pagination and optional filters.
     */
    public function index(Request $request)
    {
        $this->checkAuthorization($request);

        $perPage = min((int) $request->get('per_page', 20), 100);
        $activityType = $request->get('activity_type');
        $action = $request->get('action');
        $status = $request->get('status');
        $dateFrom = $request->get('date_from');
        $dateTo = $request->get('date_to');

        $query = ActivityLog::query()->orderBy('created_at', 'desc');

        if ($activityType) {
            $query->where('activity_type', $activityType);
        }
        if ($action) {
            $query->where('action', $action);
        }
        if ($status) {
            $query->where('status', $status);
        }
        if ($dateFrom) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        $paginator = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }
}
