<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ArticleCategory;
use App\Models\OperationLog;
use App\Support\SlugGenerator;
use App\Support\AdminListQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ArticleCategoryController extends Controller
{
    public function index(Request $request)
    {
        $pageSize = AdminListQuery::pageSize($request, 20, [
            'keyword' => 'nullable|string|max:100',
            'name' => 'nullable|string|max:100',
        ]);
        $query = ArticleCategory::withCount('articles');

        // The table ships a 名称 search box and this method took no Request at all, so
        // every keyword was discarded and the operator got the full list back looking
        // like a successful search that matched everything.
        $keyword = $request->input('keyword', $request->input('name'));
        if (filled($keyword)) {
            $query->where('name', 'ilike', '%' . $keyword . '%');
        }

        if (!$request->has('page')) {
            return response()->json(['data' => $query->ordered()->get()]);
        }

        $categories = $query->ordered()->paginate($pageSize);
        return response()->json(['data' => $categories->items(), 'total' => $categories->total()]);
    }

    public function store(Request $request)
    {
        $request->merge(['sort_order' => $request->input('sort_order') ?? 0]);
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'slug' => 'nullable|string|max:100|regex:/^[\pL\pN_-]+$/u|unique:article_categories,slug',
            'sort_order' => 'nullable|integer|min:-2147483648|max:2147483647',
        ]);

        $category = SlugGenerator::persist('article_categories', $data, $data['name'], fn ($values) => ArticleCategory::create($values));
        OperationLog::log('创建文章分类', 'article_category', $category->id, $category->name);

        return response()->json($category, 201);
    }

    public function update(Request $request, ArticleCategory $articleCategory)
    {
        if ($request->has('sort_order') && $request->input('sort_order') === null) {
            $request->merge(['sort_order' => 0]);
        }
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'slug' => 'nullable|string|max:100|regex:/^[\pL\pN_-]+$/u|unique:article_categories,slug,' . $articleCategory->id,
            'sort_order' => 'nullable|integer|min:-2147483648|max:2147483647',
        ]);

        SlugGenerator::persist('article_categories', $data, $data['name'], fn ($values) => $articleCategory->update($values), $articleCategory->id);
        OperationLog::log('更新文章分类', 'article_category', $articleCategory->id, $articleCategory->name);

        return response()->json($articleCategory);
    }

    public function destroy(ArticleCategory $articleCategory)
    {
        $deleted = DB::transaction(function () use ($articleCategory) {
            $locked = ArticleCategory::whereKey($articleCategory->id)->lockForUpdate()->first();
            if (! $locked || $locked->articles()->exists()) { return false; }
            $locked->delete();
            OperationLog::log('删除文章分类', 'article_category', $locked->id, $locked->name);
            return true;
        });
        if (! $deleted) {
            return response()->json(['message' => '该分类下有文章，无法删除。'], 422);
        }

        return response()->json(['message' => 'ok']);
    }
}
