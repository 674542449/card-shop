@extends('layouts.admin')

@section('breadcrumb', '黑名单管理')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">黑名单管理</h4>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">添加黑名单</button>
</div>

<div class="card mb-4">
    <div class="card-body">
        <form method="GET" action="{{ url('/admin/blacklists') }}">
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <select name="type" class="form-select">
                        <option value="">全部</option>
                        <option value="ip" {{ request('type') === 'ip' ? 'selected' : '' }}>IP</option>
                        <option value="email" {{ request('type') === 'email' ? 'selected' : '' }}>邮箱</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary">筛选</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body">
        @if($blacklists->count())
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>类型</th>
                            <th>值</th>
                            <th>原因</th>
                            <th>时间</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($blacklists as $blacklist)
                            <tr>
                                <td>
                                    @if($blacklist->type === 'ip')
                                        <span class="badge bg-primary">IP</span>
                                    @elseif($blacklist->type === 'email')
                                        <span class="badge bg-info">邮箱</span>
                                    @endif
                                </td>
                                <td>{{ $blacklist->value }}</td>
                                <td>{{ $blacklist->reason }}</td>
                                <td>{{ $blacklist->created_at->format('Y-m-d H:i') }}</td>
                                <td>
                                    <form action="{{ url('/admin/blacklists/' . $blacklist->id) }}" method="POST" class="d-inline delete-form">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger">删除</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="text-center text-muted mb-0">暂无黑名单记录</p>
        @endif
    </div>
    @if($blacklists->hasPages())
        <div class="card-footer">
            {{ $blacklists->links() }}
        </div>
    @endif
</div>

<!-- 添加黑名单 Modal -->
<div class="modal fade" id="addModal" tabindex="-1" aria-labelledby="addModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ url('/admin/blacklists') }}">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title" id="addModalLabel">添加黑名单</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="关闭"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="type" class="form-label">类型</label>
                        <select name="type" id="type" class="form-select" required>
                            <option value="ip">IP 地址</option>
                            <option value="email">邮箱地址</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="value" class="form-label">值</label>
                        <input type="text" name="value" id="value" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label for="reason" class="form-label">原因</label>
                        <textarea name="reason" id="reason" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-primary">添加</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
