@extends('layouts.admin')
@section('breadcrumb'){{ $product ? '编辑商品' : '新增商品' }}@endsection
@section('content')

{{-- 标题 --}}
<h4 class="mb-3">{{ $product ? '编辑商品' : '新增商品' }}</h4>

{{-- 表单 --}}
<div class="card">
    <div class="card-body">
        <form method="POST"
              action="{{ $product ? url('/admin/products/' . $product->id) : url('/admin/products') }}">
            @csrf
            @if($product)
                @method('PUT')
            @endif

            <div class="row">
                {{-- 左栏 --}}
                <div class="col-lg-8">
                    <div class="mb-3">
                        <label for="category_id" class="form-label">分类 <span class="text-danger">*</span></label>
                        <select name="category_id" id="category_id" class="form-select" required>
                            <option value="">请选择分类</option>
                            @foreach($categories as $category)
                                <option value="{{ $category->id }}"
                                    {{ old('category_id', $product->category_id ?? '') == $category->id ? 'selected' : '' }}>
                                    {{ $category->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="name" class="form-label">名称 <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="name" class="form-control"
                               value="{{ old('name', $product->name ?? '') }}" required>
                    </div>

                    <div class="mb-3">
                        <label for="slug" class="form-label">Slug</label>
                        <input type="text" name="slug" id="slug" class="form-control"
                               value="{{ old('slug', $product->slug ?? '') }}" placeholder="留空自动生成">
                    </div>

                    <div class="mb-3">
                        <label for="price" class="form-label">价格 <span class="text-danger">*</span></label>
                        <input type="number" name="price" id="price" class="form-control" step="0.01"
                               value="{{ old('price', $product->price ?? '') }}" required>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="min_quantity" class="form-label">最小购买量</label>
                            <input type="number" name="min_quantity" id="min_quantity" class="form-control"
                                   value="{{ old('min_quantity', $product->min_quantity ?? 1) }}">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="max_quantity" class="form-label">最大购买量</label>
                            <input type="number" name="max_quantity" id="max_quantity" class="form-control"
                                   value="{{ old('max_quantity', $product->max_quantity ?? 100) }}">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="description" class="form-label">描述</label>
                        <textarea name="description" id="description" class="form-control" rows="8"
                                  placeholder="支持 Markdown 格式">{{ old('description', $product->description ?? '') }}</textarea>
                    </div>
                </div>

                {{-- 右栏 --}}
                <div class="col-lg-4">
                    <div class="mb-3">
                        <label for="sort_order" class="form-label">排序</label>
                        <input type="number" name="sort_order" id="sort_order" class="form-control"
                               value="{{ old('sort_order', $product->sort_order ?? 0) }}">
                    </div>

                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" name="is_active" id="is_active" class="form-check-input" value="1"
                                {{ old('is_active', $product->is_active ?? true) ? 'checked' : '' }}>
                            <label for="is_active" class="form-check-label">状态</label>
                        </div>
                    </div>
                </div>
            </div>

            {{-- 批发价格 --}}
            <hr>
            <div class="mb-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="mb-0">批发价格</h6>
                    <button type="button" id="add-wholesale" class="btn btn-sm btn-outline-primary">添加</button>
                </div>
                <div id="wholesale-prices">
                    @if($product && $product->wholesalePrices)
                        @foreach($product->wholesalePrices as $index => $wp)
                            <div class="wholesale-row row mb-2">
                                <div class="col">
                                    <input type="number" name="wholesale_prices[{{ $index }}][min_quantity]"
                                           class="form-control" placeholder="数量阈值"
                                           value="{{ old('wholesale_prices.' . $index . '.min_quantity', $wp->min_quantity) }}">
                                </div>
                                <div class="col">
                                    <input type="number" name="wholesale_prices[{{ $index }}][price]"
                                           class="form-control" step="0.01" placeholder="单价"
                                           value="{{ old('wholesale_prices.' . $index . '.price', $wp->price) }}">
                                </div>
                                <div class="col-auto">
                                    <button type="button" class="btn btn-outline-danger btn-sm remove-wholesale">移除</button>
                                </div>
                            </div>
                        @endforeach
                    @endif
                </div>
            </div>

            {{-- SEO 设置 --}}
            <hr>
            <div class="mb-3">
                <a class="btn btn-link text-decoration-none p-0" data-bs-toggle="collapse" href="#seoCollapse" role="button"
                   aria-expanded="false" aria-controls="seoCollapse">
                    SEO 设置
                </a>
                <div class="collapse mt-3" id="seoCollapse">
                    <div class="mb-3">
                        <label for="seo_title" class="form-label">SEO 标题</label>
                        <input type="text" name="seo_title" id="seo_title" class="form-control"
                               value="{{ old('seo_title', $product->seo_title ?? '') }}">
                    </div>
                    <div class="mb-3">
                        <label for="seo_description" class="form-label">SEO 描述</label>
                        <textarea name="seo_description" id="seo_description" class="form-control"
                                  rows="3">{{ old('seo_description', $product->seo_description ?? '') }}</textarea>
                    </div>
                    <div class="mb-3">
                        <label for="seo_keywords" class="form-label">SEO 关键词</label>
                        <input type="text" name="seo_keywords" id="seo_keywords" class="form-control"
                               value="{{ old('seo_keywords', $product->seo_keywords ?? '') }}">
                    </div>
                </div>
            </div>

            {{-- 提交按钮 --}}
            <div class="mt-4">
                <button type="submit" class="btn btn-primary">{{ $product ? '更新' : '创建' }}</button>
                <a href="{{ url('/admin/products') }}" class="btn btn-secondary">取消</a>
            </div>
        </form>
    </div>
</div>

@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        let wholesaleIndex = document.querySelectorAll('.wholesale-row').length;

        document.getElementById('add-wholesale').addEventListener('click', function () {
            const row = document.createElement('div');
            row.className = 'wholesale-row row mb-2';
            row.innerHTML = `
                <div class="col">
                    <input type="number" name="wholesale_prices[${wholesaleIndex}][min_quantity]"
                           class="form-control" placeholder="数量阈值">
                </div>
                <div class="col">
                    <input type="number" name="wholesale_prices[${wholesaleIndex}][price]"
                           class="form-control" step="0.01" placeholder="单价">
                </div>
                <div class="col-auto">
                    <button type="button" class="btn btn-outline-danger btn-sm remove-wholesale">移除</button>
                </div>
            `;
            document.getElementById('wholesale-prices').appendChild(row);
            wholesaleIndex++;
        });

        document.getElementById('wholesale-prices').addEventListener('click', function (e) {
            if (e.target.classList.contains('remove-wholesale')) {
                e.target.closest('.wholesale-row').remove();
            }
        });
    });
</script>
@endpush
