<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OperationLog;
use Illuminate\Http\Request;

class LogController extends Controller
{
    public function index(Request $request)
    {
        $query = OperationLog::with('admin');

        if ($request->filled('action')) {
            $query->where('action', 'like', "%{$request->action}%");
        }

        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->date_to . ' 23:59:59');
        }

        $logs = $query->orderByDesc('created_at')
            ->paginate(50)
            ->appends($request->query());

        return view('admin.logs.index', compact('logs'));
    }
}
