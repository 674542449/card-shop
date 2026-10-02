<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\{NotificationDelivery, OperationLog};
use App\Support\AdminListQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Resources\Admin\AdminRecordResource;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $pageSize = AdminListQuery::pageSize($request, 20, [
            'status' => 'nullable|in:pending,processing,sent,failed,skipped',
            'type' => 'nullable|in:order_email,refund_email,new_order,payment_review,low_stock',
        ]);
        $query = NotificationDelivery::query();
        foreach (['status', 'type'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }
        $result = $query->with(['order:id,order_no', 'product:id,name'])->orderByDesc('id')->paginate($pageSize);
        return response()->json(['data' => AdminRecordResource::collection($result->items())->resolve($request), 'total' => $result->total()]);
    }

    public function retry(NotificationDelivery $delivery)
    {
        $updated = DB::transaction(function () use ($delivery) {
            $locked = NotificationDelivery::whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            if (!in_array($locked->status, ['failed', 'skipped'], true)) {
                return false;
            }
            $locked->update(['status' => 'pending', 'attempts' => 0, 'available_at' => now(), 'reserved_at' => null, 'lease_token' => null, 'last_error' => null, 'health_acknowledged_at' => null]);
            return true;
        });
        if (!$updated) {
            return response()->json(['message' => '仅失败或已跳过的通知可以重新入队。'], 422);
        }
        OperationLog::log('重试通知', 'notification', $delivery->id, '通知重新加入发送队列');
        return response()->json(['message' => '通知已重新加入发送队列。'], 202);
    }
}
