@extends('layouts.admin')

@section('breadcrumb'){{ $article ? '编辑文章' : '新增文章' }}@endsection

@section('content')
<div class="mb-4">
    <h4>{{ $article ? '编辑文章' : '新增文章' }}</h4>
</div>

<form action="{{ $article ? url('/admin/articles/' . $article->id) : url('/admin/articles') }}" method="POST" enctype="multipart/form-data">
    @csrf
    @if($article)
        @method('PUT')
    @endif

    <div class="row">
        {{-- 左侧主内容 --}}
        <div class="col-lg-8">
            <div class="card mb-4">
                <div class="card-body">
                    <div class="mb-3">
                        <label for="title" class="form-label">标题 <span class="text-danger">*</span></label>
                        <input type="text" class="form-control @error('title') is-invalid @enderror" id="title" name="title" value="{{ old('title', $article?->title) }}" required>
                        @error('title')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="slug" class="form-label">Slug</label>
                        <input type="text" class="form-control @error('slug') is-invalid @enderror" id="slug" name="slug" value="{{ old('slug', $article?->slug) }}" placeholder="留空自动生成">
                        @error('slug')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="excerpt" class="form-label">摘要</label>
                        <textarea class="form-control @error('excerpt') is-invalid @enderror" id="excerpt" name="excerpt" rows="3">{{ old('excerpt', $article?->excerpt) }}</textarea>
                        @error('excerpt')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="content-editor" class="form-label">内容</label>
                        <textarea class="form-control @error('content') is-invalid @enderror" id="content-editor" name="content" rows="15">{{ old('content', $article?->content) }}</textarea>
                        @error('content')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="preview-toggle">预览</button>
                        <div id="markdown-preview" class="mt-2 p-3 border rounded bg-light" style="display: none; min-height: 100px;"></div>
                    </div>
                </div>
            </div>
        </div>

        {{-- 右侧设置 --}}
        <div class="col-lg-4">
            <div class="card mb-4">
                <div class="card-body">
                    <div class="mb-3">
                        <label for="article_category_id" class="form-label">分类</label>
                        <select class="form-select @error('article_category_id') is-invalid @enderror" id="article_category_id" name="article_category_id">
                            <option value="">-- 请选择分类 --</option>
                            @foreach($categories as $category)
                                <option value="{{ $category->id }}" {{ old('article_category_id', $article?->article_category_id) == $category->id ? 'selected' : '' }}>
                                    {{ $category->name }}
                                </option>
                            @endforeach
                        </select>
                        @error('article_category_id')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="cover_image" class="form-label">封面图</label>
                        <input type="file" class="form-control image-upload @error('cover_image') is-invalid @enderror" id="cover_image" name="cover_image" accept="image/*">
                        @error('cover_image')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <div class="image-preview mt-2">
                            @if($article && $article->cover_image)
                                <img src="{{ asset('storage/' . $article->cover_image) }}" alt="封面图" class="img-thumbnail" style="max-width: 100%; max-height: 200px;">
                            @endif
                        </div>
                    </div>

                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="is_published" name="is_published" value="1"
                                @checked(old('is_published', $article ? $article->is_published : false))>
                            <label class="form-check-label" for="is_published">发布状态</label>
                        </div>
                        @error('is_published')
                            <div class="text-danger small">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </div>

            {{-- SEO 设置 --}}
            <div class="card mb-4">
                <div class="card-header p-0">
                    <button class="btn btn-link text-decoration-none w-100 text-start p-3" type="button" data-bs-toggle="collapse" data-bs-target="#seoSection" aria-expanded="false" aria-controls="seoSection">
                        <i class="bi bi-search me-1"></i>SEO 设置
                    </button>
                </div>
                <div class="collapse" id="seoSection">
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="seo_title" class="form-label">SEO 标题</label>
                            <input type="text" class="form-control @error('seo_title') is-invalid @enderror" id="seo_title" name="seo_title" value="{{ old('seo_title', $article?->seo_title) }}">
                            @error('seo_title')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="seo_description" class="form-label">SEO 描述</label>
                            <textarea class="form-control @error('seo_description') is-invalid @enderror" id="seo_description" name="seo_description" rows="3">{{ old('seo_description', $article?->seo_description) }}</textarea>
                            @error('seo_description')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="seo_keywords" class="form-label">SEO 关键词</label>
                            <input type="text" class="form-control @error('seo_keywords') is-invalid @enderror" id="seo_keywords" name="seo_keywords" value="{{ old('seo_keywords', $article?->seo_keywords) }}">
                            @error('seo_keywords')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary">保存</button>
        <a href="{{ url('/admin/articles') }}" class="btn btn-secondary">返回</a>
    </div>
</form>

@endsection
