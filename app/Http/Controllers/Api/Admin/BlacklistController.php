<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Blacklist;
use App\Models\OperationLog;
use App\Support\AdminListQuery;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BlacklistController extends Controller
{
    public function index(Request $request)
    {
        $pageSize = AdminListQuery::pageSize($request, 20, [
            'type' => 'nullable|in:ip,email',
            'keyword' => 'nullable|string|max:200',
            'value' => 'nullable|string|max:200',
            'source' => 'nullable|in:manual,honeypot',
            'active' => 'nullable|boolean',
        ]);
        $query = Blacklist::query();

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        // The table's search form submits the column name (`value`); accept both.
        $keyword = $request->input('keyword', $request->input('value'));
        if (filled($keyword)) {
            $query->where('value', 'ilike', '%' . $keyword . '%');
        }

        if ($request->filled('source')) {
            $request->input('source') === 'manual'
                ? $query->where(fn ($q) => $q->whereNull('source')->orWhere('source', 'manual'))
                : $query->where('source', 'honeypot');
        }
        if ($request->filled('active')) {
            $request->boolean('active')
                ? $query->active()
                : $query->whereNotNull('expires_at')->where('expires_at', '<=', now());
        }

        $blacklists = $query->orderByDesc('id')->paginate($pageSize);

        return response()->json([
            'data' => $blacklists->items(),
            'total' => $blacklists->total(),
        ]);
    }

    private function validatedData(Request $request, ?Blacklist $blacklist = null): array
    {
        $data = $request->validate([
            'type' => 'required|in:ip,email',
            'value' => ['required', 'string', 'max:200', $request->input('type') === 'ip' ? 'ip' : 'email:rfc'],
            'reason' => 'nullable|string|max:500',
            'expires_at' => 'nullable|date',
        ], [
            'value.ip' => '请输入有效的 IPv4 或 IPv6 地址。',
            'value.email' => '请输入有效的邮箱地址。',
        ]);
        $data['value'] = $data['type'] === 'email' ? mb_strtolower($data['value']) : $data['value'];
        $duplicate = Blacklist::where('type', $data['type'])
            ->whereRaw('lower(value) = ?', [mb_strtolower($data['value'])]);
        if ($blacklist) {
            $duplicate->whereKeyNot($blacklist->id);
        }
        if ($duplicate->exists()) {
            throw ValidationException::withMessages(['value' => '该地址已经在黑名单中，请编辑已有记录。']);
        }

        return $data;
    }

    public function store(Request $request)
    {
        $data = $this->validatedData($request);
        $data['source'] = 'manual';
        $blacklist = Blacklist::create($data);
        OperationLog::log('添加黑名单', 'blacklist', $blacklist->id, "{$data['type']}: {$data['value']}");

        return response()->json($blacklist, 201);
    }

    public function update(Request $request, Blacklist $blacklist)
    {
        $data = $this->validatedData($request, $blacklist);
        // An operator's correction is a manual rule; later scanner probes must not
        // silently replace its reason or expiry with the honeypot defaults.
        $data['source'] = 'manual';
        $blacklist->update($data);
        OperationLog::log('更新黑名单', 'blacklist', $blacklist->id, "{$blacklist->type}: {$blacklist->value}");

        return response()->json($blacklist);
    }

    public function destroy(Blacklist $blacklist)
    {
        OperationLog::log('删除黑名单', 'blacklist', $blacklist->id, "{$blacklist->type}: {$blacklist->value}");
        $blacklist->delete();

        return response()->json(['message' => 'ok']);
    }
}
