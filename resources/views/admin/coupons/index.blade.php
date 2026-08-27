@extends('layouts.admin')

@section('breadcrumb', '优惠码管理')

@section('content')

{{-- 标题栏 --}}
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">优惠码管理</h4>
    <a href="{{ url('/admin/coupons/create') }}" class="btn btn-primary">新增优惠码</a>
</div>

{{-- 优惠码列表 --}}
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>优惠码</th>
                    <th>类型</th>
                    <th>面值</th>
                    <th>已用/上限</th>
                    <th>适用商品</th>
                    <th>有效期</th>
                    <th>状态</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                @forelse($coupons as $coupon)
                    <tr>
                        <td><code>{{ $coupon->code }}</code></td>
                        <td>
                            @if($coupon->type === 'fixed')
                                固定金额
                            @else
                                百分比
                            @endif
                        </td>
                        <td>
                            @if($coupon->type === 'fixed')
                                &yen;{{ number_format($coupon->value, 2) }}
                            @else
                                {{ $coupon->value }}%
                            @endif
                        </td>
                        <td>
                            {{ $coupon->used_count }} /
                            @if($coupon->max_uses > 0)
                                {{ $coupon->max_uses }}
                            @else
                                无限
                            @endif
                        </td>
                        <td>
                            @if($coupon->product_id)
                                {{ $coupon->product->name ?? '-' }}
                            @else
                                全部
                            @endif
                        </td>
                        <td>
                            @if($coupon->starts_at || $coupon->expires_at)
                                {{ $coupon->starts_at ? $coupon->starts_at->format('Y-m-d') : '' }}
                                ~
                                {{ $coupon->expires_at ? $coupon->expires_at->format('Y-m-d') : '' }}
                            @else
                                永久
                            @endif
                        </td>
                        <td>
                            @if($coupon->is_active)
                                <span class="badge bg-success">启用</span>
                            @else
                                <span class="badge bg-secondary">禁用</span>
                            @endif
                        </td>
                        <td>
                            <a href="{{ url('/admin/coupons/' . $coupon->id . '/edit') }}" class="btn btn-sm btn-outline-primary">编辑</a>
                            <form action="{{ url('/admin/coupons/' . $coupon->id) }}" method="POST" class="d-inline delete-form">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger">删除</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">暂无优惠码</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- 分页 --}}
<div class="mt-3">
    {{ $coupons->links() }}
</div>

@endsection
