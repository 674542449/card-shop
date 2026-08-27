<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ArticleCategory;
use App\Models\OperationLog;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ArticleCategoryController extends Controller
{
    public function index()
    {
        $categories = ArticleCategory::withCount('articles')->ordered()->get();
        return response()->json(['data' => $categories]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'slug' => 'nullable|string|max:100|unique:article_categories,slug',
            'sort_order' => 'nullable|integer',
        ]);

        if (empty($data['slug'])) {
            $data['slug'] = Str::slug($data['name']) ?: Str::slug(Str::random(8));
        }

        $category = ArticleCategory::create($data);
        OperationLog::log('创建文章分类', 'article_category', $category->id, $category->name);

        return response()->json($category, 201);
    }

    public function update(Request $request, ArticleCategory $articleCategory)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'slug' => 'nullable|string|max:100|unique:article_categories,slug,' . $articleCategory->id,
            'sort_order' => 'nullable|integer',
        ]);

        if (empty($data['slug'])) {
            $data['slug'] = Str::slug($data['name']) ?: Str::slug(Str::random(8));
        }

        $articleCategory->update($data);
        OperationLog::log('更新文章分类', 'article_category', $articleCategory->id, $articleCategory->name);

        return response()->json($articleCategory);
    }

    public function destroy(ArticleCategory $articleCategory)
    {
        if ($articleCategory->articles()->count() > 0) {
            return response()->json(['message' => '该分类下有文章，无法删除。'], 422);
        }

        OperationLog::log('删除文章分类', 'article_category', $articleCategory->id, $articleCategory->name);
        $articleCategory->delete();

        return response()->json(['message' => 'ok']);
    }
}
