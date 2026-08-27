@extends('layouts.admin')

@section('breadcrumb'){{ $coupon ? '编辑优惠码' : '新增优惠码' }}@endsection

@section('content')
<div class="mb-4">
    <h4>{{ $coupon ? '编辑优惠码' : '新增优惠码' }}</h4>
</div>

<div class="card">
    <div class="card-body">
        <form action="{{ $coupon ? url('/admin/coupons/' . $coupon->id) : url('/admin/coupons') }}" method="POST">
            @csrf
            @if($coupon)
                @method('PUT')
            @endif

            <div class="mb-3">
                <label for="code" class="form-label">优惠码 <span class="text-danger">*</span></label>
                <div class="input-group">
                    <input type="text" class="form-control @error('code') is-invalid @enderror" id="code" name="code" value="{{ old('code', $coupon?->code) }}" required>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="generate-code">自动生成</button>
                    @error('code')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">类型 <span class="text-danger">*</span></label>
                <div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="type" id="type_fixed" value="fixed"
                            @checked(old('type', $coupon?->type ?? 'fixed') === 'fixed')>
                        <label class="form-check-label" for="type_fixed">固定金额</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="type" id="type_percent" value="percent"
                            @checked(old('type', $coupon?->type) === 'percent')>
                        <label class="form-check-label" for="type_percent">百分比</label>
                    </div>
                </div>
                @error('type')
                    <div class="text-danger small">{{ $message }}</div>
                @enderror
            </div>

            <div class="mb-3">
                <label for="value" class="form-label">面值 <span class="text-danger">*</span></label>
                <input type="number" class="form-control @error('value') is-invalid @enderror" id="value" name="value" value="{{ old('value', $coupon?->value) }}" step="0.01" required>
                @error('value')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="mb-3">
                <label for="max_uses" class="form-label">使用上限</label>
                <input type="number" class="form-control @error('max_uses') is-invalid @enderror" id="max_uses" name="max_uses" value="{{ old('max_uses', $coupon?->max_uses ?? 0) }}" placeholder="0 为无限">
                @error('max_uses')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="mb-3">
                <label for="min_amount" class="form-label">最低消费</label>
                <input type="number" class="form-control @error('min_amount') is-invalid @enderror" id="min_amount" name="min_amount" value="{{ old('min_amount', $coupon?->min_amount) }}" step="0.01">
                @error('min_amount')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="mb-3">
                <label for="product_id" class="form-label">适用商品</label>
                <select class="form-select @error('product_id') is-invalid @enderror" id="product_id" name="product_id">
                    <option value="">全部商品</option>
                    @foreach($products as $product)
                        <option value="{{ $product->id }}" {{ old('product_id', $coupon?->product_id) == $product->id ? 'selected' : '' }}>
                            {{ $product->name }}
                        </option>
                    @endforeach
                </select>
                @error('product_id')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="mb-3">
                <label for="starts_at" class="form-label">开始时间</label>
                <input type="datetime-local" class="form-control @error('starts_at') is-invalid @enderror" id="starts_at" name="starts_at"
                    value="{{ old('starts_at', $coupon && $coupon->starts_at ? $coupon->starts_at->format('Y-m-d\TH:i') : '') }}">
                @error('starts_at')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="mb-3">
                <label for="expires_at" class="form-label">结束时间</label>
                <input type="datetime-local" class="form-control @error('expires_at') is-invalid @enderror" id="expires_at" name="expires_at"
                    value="{{ old('expires_at', $coupon && $coupon->expires_at ? $coupon->expires_at->format('Y-m-d\TH:i') : '') }}">
                @error('expires_at')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="mb-3">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
                        @checked(old('is_active', $coupon ? $coupon->is_active : true))>
                    <label class="form-check-label" for="is_active">状态</label>
                </div>
                @error('is_active')
                    <div class="text-danger small">{{ $message }}</div>
                @enderror
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">保存</button>
                <a href="{{ url('/admin/coupons') }}" class="btn btn-secondary">返回</a>
            </div>
        </form>
    </div>
</div>

@endsection
