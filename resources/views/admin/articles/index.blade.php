@extends('layouts.admin')

@section('breadcrumb', '文章管理')

@section('content')

{{-- 筛选栏 --}}
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" action="{{ url('/admin/articles') }}" class="row g-3 align-items-end">
            <div class="col-auto">
                <label class="form-label">分类</label>
                <select name="article_category_id" class="form-select">
                    <option value="">全部分类</option>
                    @foreach($categories as $category)
                        <option value="{{ $category->id }}" {{ request('article_category_id') == $category->id ? 'selected' : '' }}>
                            {{ $category->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-auto">
                <label class="form-label">状态</label>
                <select name="status" class="form-select">
                    <option value="">全部状态</option>
                    <option value="1" {{ request('status') === '1' ? 'selected' : '' }}>已发布</option>
                    <option value="0" {{ request('status') === '0' ? 'selected' : '' }}>草稿</option>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary">筛选</button>
                <a href="{{ url('/admin/articles') }}" class="btn btn-secondary">重置</a>
            </div>
        </form>
    </div>
</div>

{{-- 标题栏 --}}
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">文章管理</h4>
    <a href="{{ url('/admin/articles/create') }}" class="btn btn-primary">新增文章</a>
</div>

{{-- 文章列表 --}}
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>标题</th>
                    <th>分类</th>
                    <th>状态</th>
                    <th>浏览量</th>
                    <th>创建时间</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                @forelse($articles as $article)
                    <tr>
                        <td>{{ $article->id }}</td>
                        <td>{{ $article->title }}</td>
                        <td>{{ $article->articleCategory->name ?? '-' }}</td>
                        <td>
                            @if($article->is_published)
                                <span class="badge bg-success">已发布</span>
                            @else
                                <span class="badge bg-secondary">草稿</span>
                            @endif
                        </td>
                        <td>{{ $article->views }}</td>
                        <td>{{ $article->created_at->format('Y-m-d') }}</td>
                        <td>
                            <a href="{{ url('/admin/articles/' . $article->id . '/edit') }}" class="btn btn-sm btn-outline-primary">编辑</a>
                            <form action="{{ url('/admin/articles/' . $article->id) }}" method="POST" class="d-inline delete-form">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger">删除</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">暂无文章</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- 分页 --}}
<div class="mt-3">
    {{ $articles->withQueryString()->links() }}
</div>

@endsection
