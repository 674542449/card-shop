@extends('layouts.admin')
@section('breadcrumb', '商品管理')
@section('content')

{{-- 筛选栏 --}}
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" action="{{ url('/admin/products') }}" class="row g-3 align-items-end">
            <div class="col-auto">
                <label class="form-label">分类</label>
                <select name="category_id" class="form-select">
                    <option value="">全部分类</option>
                    @foreach($categories as $category)
                        <option value="{{ $category->id }}" {{ request('category_id') == $category->id ? 'selected' : '' }}>
                            {{ $category->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-auto">
                <label class="form-label">状态</label>
                <select name="status" class="form-select">
                    <option value="">全部状态</option>
                    <option value="1" {{ request('status') === '1' ? 'selected' : '' }}>启用</option>
                    <option value="0" {{ request('status') === '0' ? 'selected' : '' }}>禁用</option>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary">筛选</button>
                <a href="{{ url('/admin/products') }}" class="btn btn-secondary">重置</a>
            </div>
        </form>
    </div>
</div>

{{-- 标题栏 --}}
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">商品管理</h4>
    <a href="{{ url('/admin/products/create') }}" class="btn btn-primary">新增商品</a>
</div>

{{-- 商品列表 --}}
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>商品名</th>
                    <th>分类</th>
                    <th>价格</th>
                    <th>库存</th>
                    <th>状态</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                @forelse($products as $product)
                    <tr>
                        <td>{{ $product->id }}</td>
                        <td>{{ $product->name }}</td>
                        <td>{{ $product->category->name ?? '-' }}</td>
                        <td>&yen;{{ number_format($product->price, 2) }}</td>
                        <td>{{ $product->stock_count }}</td>
                        <td>
                            @if($product->is_active)
                                <span class="badge bg-success">启用</span>
                            @else
                                <span class="badge bg-secondary">禁用</span>
                            @endif
                        </td>
                        <td>
                            <a href="{{ url('/admin/products/' . $product->id . '/edit') }}" class="btn btn-sm btn-primary">编辑</a>
                            <a href="{{ url('/admin/products/' . $product->id . '/cards') }}" class="btn btn-sm btn-outline-info">卡密</a>
                            <form action="{{ url('/admin/products/' . $product->id) }}" method="POST" class="d-inline confirm">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-danger">删除</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">暂无商品数据</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- 分页 --}}
<div class="mt-3">
    {{ $products->links() }}
</div>

@endsection
