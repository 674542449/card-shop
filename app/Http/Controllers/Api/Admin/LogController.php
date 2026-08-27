<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\OperationLog;
use Illuminate\Http\Request;

class LogController extends Controller
{
    public function index(Request $request)
    {
        $query = OperationLog::with('admin:id,username');

        if ($request->filled('action')) {
            $query->where('action', 'ilike', '%' . $request->action . '%');
        }
        if ($request->filled('target_type')) {
            $query->where('target_type', $request->target_type);
        }
        if ($request->filled('admin')) {
            $adminKeyword = $request->input('admin');
            $query->whereHas('admin', fn ($q) => $q->where('username', 'ilike', "%{$adminKeyword}%"));
        }

        // The table submits start_date/end_date from its date-range column.
        $dateFrom = $request->input('start_date', $request->input('date_from'));
        $dateTo = $request->input('end_date', $request->input('date_to'));
        if (filled($dateFrom)) {
            $query->where('created_at', '>=', $dateFrom);
        }
        if (filled($dateTo)) {
            $query->where('created_at', '<=', $dateTo . ' 23:59:59');
        }

        $logs = $query->orderByDesc('id')->paginate($request->get('pageSize', 20));

        return response()->json([
            'data' => $logs->items(),
            'total' => $logs->total(),
        ]);
    }
}
