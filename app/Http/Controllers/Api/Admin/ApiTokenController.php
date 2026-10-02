<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\OperationLog;
use App\Support\AdminListQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Http\Resources\Admin\AdminRecordResource;

class ApiTokenController extends Controller
{
    public function index(Request $request)
    {
        $pageSize = AdminListQuery::pageSize($request, 20, [
            'name' => 'nullable|string|max:100',
            'is_active' => 'nullable|boolean',
        ]);
        $query = ApiToken::query();
        if ($request->filled('name')) {
            $query->where('name', 'ilike', '%' . $request->input('name') . '%');
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $tokens = $query->orderByDesc('id')->paginate($pageSize);

        return response()->json(['data' => AdminRecordResource::collection($tokens->items())->resolve($request), 'total' => $tokens->total()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:100'] + $this->quotaRules());
        $plainToken = Str::random(64);
        $token = ApiToken::create([
            'name' => $data['name'],
            'token' => hash('sha256', $plainToken),
            'is_active' => true,
        ] + $data);
        OperationLog::log('创建 API 令牌', 'api_token', $token->id, $token->name);

        // Only this response reveals the secret. Never store it or put it in logs.
        return response()->json(['data' => (new AdminRecordResource($token->refresh()))->resolve($request), 'plain_token' => $plainToken], 201)
            ->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, ApiToken $apiToken)
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:100',
            'is_active' => 'sometimes|required|boolean',
        ] + $this->quotaRules());
        $apiToken->update($data);
        OperationLog::log('更新 API 令牌', 'api_token', $apiToken->id, $apiToken->name);

        return response()->json((new AdminRecordResource($apiToken))->resolve($request));
    }

    public function destroy(ApiToken $apiToken)
    {
        OperationLog::log('撤销 API 令牌', 'api_token', $apiToken->id, $apiToken->name);
        $apiToken->delete();

        return response()->json(['message' => 'API 令牌已撤销。']);
    }

    private function quotaRules(): array
    {
        return [
            'requests_per_minute' => 'sometimes|required|integer|min:1|max:6000',
            'orders_per_minute' => 'sometimes|required|integer|min:1|max:600',
            'max_pending_orders' => 'sometimes|required|integer|min:1|max:1000',
            'max_pending_quantity' => 'sometimes|required|integer|min:1|max:100000',
            'expires_at' => 'nullable|date',
            'scopes' => 'nullable|array',
            'scopes.*' => 'string|in:products:read,orders:create,orders:query,orders:cancel',
            'allowed_ips' => 'nullable|array|max:100',
            'allowed_ips.*' => ['bail', 'string', function ($attribute, $value, $fail) {
                $parts = explode('/', $value); $address = $parts[0];
                if (!filter_var($address, FILTER_VALIDATE_IP) || count($parts) > 2
                    || (isset($parts[1]) && (!ctype_digit($parts[1]) || (int) $parts[1] > (str_contains($address, ':') ? 128 : 32)))) {
                    $fail('请填写有效 IP 地址或 CIDR 网段。');
                }
            }],
        ];
    }
}
