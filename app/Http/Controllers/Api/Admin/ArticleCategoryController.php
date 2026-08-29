<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ArticleCategory;
use App\Models\OperationLog;
use App\Support\SlugGenerator;
use Illuminate\Http\Request;

class ArticleCategoryController extends Controller
{
    public function index(Request $request)
    {
        $query = ArticleCategory::withCount('articles');

        // The table ships a 名称 search box and this method took no Request at all, so
        // every keyword was discarded and the operator got the full list back looking
        // like a successful search that matched everything.
        $keyword = $request->input('keyword', $request->input('name'));
        if (filled($keyword)) {
            $query->where('name', 'ilike', '%' . $keyword . '%');
        }

        return response()->json(['data' => $query->ordered()->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'slug' => 'nullable|string|max:100|unique:article_categories,slug',
            'sort_order' => 'nullable|integer',
        ]);

        if (empty($data['slug'])) {
            $data['slug'] = SlugGenerator::unique($data['name'], 'article_categories');
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
            $data['slug'] = SlugGenerator::unique($data['name'], 'article_categories', $articleCategory->id);
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
