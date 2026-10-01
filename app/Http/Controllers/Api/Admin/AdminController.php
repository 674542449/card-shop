<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\OperationLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    public function index()
    {
        return response()->json(['data' => Admin::orderBy('id')->get()]);
    }

    private function rules(?Admin $admin = null): array
    {
        return ['username' => ['required', 'string', 'max:100', Rule::unique('admins')->ignore($admin?->id)],
            'password' => ['bail', $admin ? 'nullable' : 'required', 'string', 'min:12', 'max:72', function ($attribute, $value, $fail) {
                if (strlen($value) > 72) { $fail('密码最多 72 字节，请缩短密码。'); }
            }],
            'role' => 'required|in:owner,staff', 'permissions' => 'nullable|array',
            'permissions.*' => 'string|regex:/^(overview|catalog|orders|refunds|content|coupons|blacklists|logs|settings|tokens|notifications|maintenance):(read|write)$/D',
            'is_active' => 'required|boolean'];
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());
        $admin = Admin::create($data);
        OperationLog::log('创建管理员', 'admin', $admin->id, $admin->username);

        return response()->json($admin, 201);
    }

    public function update(Request $request, Admin $admin)
    {
        $data = $request->validate($this->rules($admin));
        if (empty($data['password'])) {
            unset($data['password']);
        }
        DB::transaction(function () use ($request, $admin, $data) {
            $owners = Admin::where('role', 'owner')->where('is_active', true)->lockForUpdate()->get();
            abort_if($admin->id === $request->attributes->get('admin')->id && (! $data['is_active'] || $data['role'] !== 'owner'), 422, '不能停用或降级当前登录账户。');
            abort_if($admin->role === 'owner' && $admin->is_active && $owners->count() <= 1 && (! $data['is_active'] || $data['role'] !== 'owner'), 422, '至少保留一个启用的店主管理员。');
            $admin->update($data);
        });
        OperationLog::log('更新管理员', 'admin', $admin->id, $admin->username);

        return response()->json($admin);
    }
}
