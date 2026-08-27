@extends('layouts.admin')

@section('breadcrumb', '操作日志')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">操作日志</h4>
</div>

<div class="card mb-4">
    <div class="card-body">
        <form method="GET" action="{{ url('/admin/logs') }}">
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">操作类型</label>
                    <input type="text" name="action" class="form-control" placeholder="搜索操作类型" value="{{ request('action') }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">开始日期</label>
                    <input type="date" name="date_from" class="form-control" value="{{ request('date_from') }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">结束日期</label>
                    <input type="date" name="date_to" class="form-control" value="{{ request('date_to') }}">
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary">筛选</button>
                    <a href="{{ url('/admin/logs') }}" class="btn btn-secondary">重置</a>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body">
        @if($logs->count())
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>时间</th>
                            <th>管理员</th>
                            <th>操作</th>
                            <th>目标</th>
                            <th>详情</th>
                            <th>IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($logs as $log)
                            <tr>
                                <td>{{ $log->created_at->format('Y-m-d H:i:s') }}</td>
                                <td>{{ $log->admin->username ?? '-' }}</td>
                                <td>{{ $log->action }}</td>
                                <td>{{ $log->target_type }}</td>
                                <td>{{ Str::limit($log->detail, 50) }}</td>
                                <td>{{ $log->ip }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="text-center text-muted mb-0">暂无操作日志</p>
        @endif
    </div>
    @if($logs->hasPages())
        <div class="card-footer">
            {{ $logs->links() }}
        </div>
    @endif
</div>
@endsection
