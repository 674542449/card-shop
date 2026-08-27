@extends('layouts.admin')

@section('breadcrumb'){{ $category ? '编辑分类' : '新增分类' }}@endsection

@section('content')
<div class="mb-4">
    <h4>{{ $category ? '编辑分类' : '新增分类' }}</h4>
</div>

<div class="card">
    <div class="card-body">
        <form action="{{ $category ? route('admin.categories.update', $category) : route('admin.categories.store') }}" method="POST">
            @csrf
            @if($category)
                @method('PUT')
            @endif

            <div class="mb-3">
                <label for="name" class="form-label">名称 <span class="text-danger">*</span></label>
                <input type="text" class="form-control @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name', $category?->name) }}" required>
                @error('name')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="mb-3">
                <label for="slug" class="form-label">Slug</label>
                <input type="text" class="form-control @error('slug') is-invalid @enderror" id="slug" name="slug" value="{{ old('slug', $category?->slug) }}" placeholder="留空自动生成">
                @error('slug')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="mb-3">
                <label for="description" class="form-label">描述</label>
                <textarea class="form-control @error('description') is-invalid @enderror" id="description" name="description" rows="3">{{ old('description', $category?->description) }}</textarea>
                @error('description')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="mb-3">
                <label for="sort_order" class="form-label">排序</label>
                <input type="number" class="form-control @error('sort_order') is-invalid @enderror" id="sort_order" name="sort_order" value="{{ old('sort_order', $category?->sort_order ?? 0) }}">
                @error('sort_order')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="mb-3">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
                        @checked(old('is_active', $category ? $category->is_active : true))>
                    <label class="form-check-label" for="is_active">状态</label>
                </div>
                @error('is_active')
                    <div class="text-danger small">{{ $message }}</div>
                @enderror
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">保存</button>
                <a href="{{ route('admin.categories.index') }}" class="btn btn-secondary">返回</a>
            </div>
        </form>
    </div>
</div>
@endsection
