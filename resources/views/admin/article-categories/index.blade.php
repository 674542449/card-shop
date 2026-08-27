@extends('layouts.admin')

@section('breadcrumb', '文章分类')

@section('content')

{{-- 标题栏 --}}
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">文章分类</h4>
    <a href="{{ url('/admin/article-categories/create') }}" class="btn btn-primary">新增分类</a>
</div>

<div class="card">
    <div class="card-body">
        @if($categories->count())
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>名称</th>
                            <th>Slug</th>
                            <th>文章数</th>
                            <th>排序</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($categories as $category)
                            <tr>
                                <td>{{ $category->id }}</td>
                                <td>{{ $category->name }}</td>
                                <td><code>{{ $category->slug }}</code></td>
                                <td>{{ $category->articles_count ?? 0 }}</td>
                                <td>{{ $category->sort_order }}</td>
                                <td>
                                    <a href="{{ url('/admin/article-categories/' . $category->id . '/edit') }}" class="btn btn-sm btn-outline-primary">编辑</a>
                                    <form action="{{ url('/admin/article-categories/' . $category->id) }}" method="POST" class="d-inline delete-form">
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
            <div class="text-center text-muted py-4">暂无分类</div>
        @endif
    </div>
</div>

@endsection
