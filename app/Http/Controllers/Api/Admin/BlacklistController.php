<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Blacklist;
use App\Models\OperationLog;
use Illuminate\Http\Request;

class BlacklistController extends Controller
{
    public function index(Request $request)
    {
        $query = Blacklist::query();

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        // The table's search form submits the column name (`value`); accept both.
        $keyword = $request->input('keyword', $request->input('value'));
        if (filled($keyword)) {
            $query->where('value', 'ilike', '%' . $keyword . '%');
        }

        $blacklists = $query->orderByDesc('id')->paginate($request->get('pageSize', 20));

        return response()->json([
            'data' => $blacklists->items(),
            'total' => $blacklists->total(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'type' => 'required|in:ip,email',
            'value' => 'required|string|max:200',
            'reason' => 'nullable|string|max:500',
        ]);

        $blacklist = Blacklist::create($data);
        OperationLog::log('添加黑名单', 'blacklist', $blacklist->id, "{$data['type']}: {$data['value']}");

        return response()->json($blacklist, 201);
    }

    public function destroy(Blacklist $blacklist)
    {
        OperationLog::log('删除黑名单', 'blacklist', $blacklist->id, "{$blacklist->type}: {$blacklist->value}");
        $blacklist->delete();

        return response()->json(['message' => 'ok']);
    }
}
