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

        $logs = $query->orderByDesc('id')->paginate($request->get('pageSize', 20));

        return response()->json([
            'data' => $logs->items(),
            'total' => $logs->total(),
        ]);
    }
}
