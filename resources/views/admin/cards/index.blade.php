@extends('layouts.admin')
@section('breadcrumb', '卡密管理 - ' . $product->name)
@section('content')

{{-- 标题 --}}
<h4 class="mb-3">{{ $product->name }} - 卡密管理</h4>

{{-- 统计卡片 --}}
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <div class="text-muted mb-1">总数</div>
                <span class="badge bg-primary fs-5">{{ $total }}</span>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <div class="text-muted mb-1">未售</div>
                <span class="badge bg-success fs-5">{{ $unsold }}</span>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <div class="text-muted mb-1">已售</div>
                <span class="badge bg-info fs-5">{{ $sold }}</span>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <div class="text-muted mb-1">锁定</div>
                <span class="badge bg-warning fs-5">{{ $locked }}</span>
            </div>
        </div>
    </div>
</div>

{{-- 导入卡密 --}}
<div class="card mb-4">
    <div class="card-header">导入卡密</div>
    <div class="card-body">
        <form method="POST" action="{{ url('/admin/products/' . $product->id . '/cards/import') }}" enctype="multipart/form-data">
            @csrf
            <div class="mb-3">
                <label for="content" class="form-label">卡密内容</label>
                <textarea name="content" id="content" class="form-control" rows="5"
                          placeholder="每行一个卡密">{{ old('content') }}</textarea>
            </div>
            <div class="mb-3">
                <label for="file" class="form-label">或上传文件</label>
                <input type="file" name="file" id="file" class="form-control" accept=".txt,.csv">
            </div>
            <button type="submit" class="btn btn-primary">导入</button>
        </form>
    </div>
</div>

{{-- 筛选 --}}
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" action="{{ url('/admin/products/' . $product->id . '/cards') }}" class="row g-3 align-items-end">
            <div class="col-auto">
                <label class="form-label">状态</label>
                <select name="status" class="form-select">
                    <option value="">全部状态</option>
                    <option value="unsold" {{ request('status') === 'unsold' ? 'selected' : '' }}>未售</option>
                    <option value="sold" {{ request('status') === 'sold' ? 'selected' : '' }}>已售</option>
                    <option value="locked" {{ request('status') === 'locked' ? 'selected' : '' }}>锁定</option>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary">筛选</button>
            </div>
        </form>
    </div>
</div>

{{-- 卡密列表 --}}
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>卡密列表</span>
        <button type="button" id="batch-delete" class="btn btn-sm btn-outline-danger" disabled onclick="batchDelete()">
            批量删除
        </button>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th><input type="checkbox" id="select-all" class="form-check-input"></th>
                    <th>ID</th>
                    <th>内容</th>
                    <th>状态</th>
                    <th>关联订单</th>
                    <th>创建时间</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                @forelse($cards as $card)
                    <tr>
                        <td>
                            <input type="checkbox" class="form-check-input card-checkbox" value="{{ $card->id }}">
                        </td>
                        <td>{{ $card->id }}</td>
                        <td>{{ Str::limit($card->content, 50) }}</td>
                        <td>
                            @if($card->status === 'unsold')
                                <span class="badge bg-success">未售</span>
                            @elseif($card->status === 'sold')
                                <span class="badge bg-info">已售</span>
                            @elseif($card->status === 'locked')
                                <span class="badge bg-warning">锁定</span>
                            @endif
                        </td>
                        <td>{{ $card->order_no ?? '-' }}</td>
                        <td>{{ $card->created_at }}</td>
                        <td>
                            @if($card->status === 'unsold')
                                <form action="{{ url('/admin/products/' . $product->id . '/cards/' . $card->id) }}"
                                      method="POST" class="d-inline confirm">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-danger">删除</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">暂无卡密数据</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- 批量删除表单 --}}
<form id="batch-delete-form" method="POST"
      action="{{ url('/admin/products/' . $product->id . '/cards/batch-delete') }}" class="d-none">
    @csrf
    @method('DELETE')
    <input type="hidden" name="ids" id="batch-delete-ids">
</form>

{{-- 分页 --}}
<div class="mt-3">
    {{ $cards->links() }}
</div>

@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const selectAll = document.getElementById('select-all');
        const batchDeleteBtn = document.getElementById('batch-delete');
        const checkboxes = document.querySelectorAll('.card-checkbox');

        function updateBatchButton() {
            const checked = document.querySelectorAll('.card-checkbox:checked');
            batchDeleteBtn.disabled = checked.length === 0;
        }

        if (selectAll) {
            selectAll.addEventListener('change', function () {
                checkboxes.forEach(function (cb) {
                    cb.checked = selectAll.checked;
                });
                updateBatchButton();
            });
        }

        checkboxes.forEach(function (cb) {
            cb.addEventListener('change', updateBatchButton);
        });
    });

    function batchDelete() {
        if (!confirm('确定要删除选中的卡密吗？')) return;

        const checked = document.querySelectorAll('.card-checkbox:checked');
        const ids = Array.from(checked).map(function (cb) { return cb.value; });
        document.getElementById('batch-delete-ids').value = ids.join(',');
        document.getElementById('batch-delete-form').submit();
    }
</script>
@endpush
