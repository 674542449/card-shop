<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Blacklist;
use App\Models\OperationLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class BlacklistController extends Controller
{
    public function index(Request $request)
    {
        $query = Blacklist::query();

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        $blacklists = $query->orderByDesc('created_at')
            ->paginate(20)
            ->appends($request->query());

        return view('admin.blacklists.index', compact('blacklists'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'type'   => 'required|in:ip,email',
            'value'  => 'required|string|max:200',
            'reason' => 'nullable|string|max:500',
        ], [
            'type.required'  => '请选择类型。',
            'value.required' => '请输入值。',
        ]);

        if (Blacklist::where('type', $request->type)->where('value', $request->value)->exists()) {
            return redirect()->back()->withInput()->with('error', '该记录已存在。');
        }

        $blacklist = Blacklist::create($request->only('type', 'value', 'reason'));

        Cache::forget('blacklist_list');

        OperationLog::log('添加黑名单', 'blacklist', $blacklist->id, "{$request->type}: {$request->value}");

        return redirect()->back()->with('success', '已添加到黑名单。');
    }

    public function destroy(Blacklist $blacklist)
    {
        $info = "{$blacklist->type}: {$blacklist->value}";

        $blacklist->delete();

        Cache::forget('blacklist_list');

        OperationLog::log('删除黑名单', 'blacklist', null, $info);

        return redirect()->back()->with('success', '已从黑名单移除。');
    }
}
