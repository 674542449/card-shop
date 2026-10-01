<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\OperationLog;
use App\Support\SlugGenerator;
use App\Support\AdminListQuery;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function index(Request $request)
    {
        $pageSize = AdminListQuery::pageSize($request, 20, [
            'keyword' => 'nullable|string|max:100',
            'name' => 'nullable|string|max:100',
            'is_active' => 'nullable|boolean',
        ]);
        $query = Category::withCount('products');

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // The table ships a 名称 search box and this method took no Request at all, so
        // every keyword was discarded and the operator got the full list back looking
        // like a successful search that matched everything.
        $keyword = $request->input('keyword', $request->input('name'));
        if (filled($keyword)) {
            $query->where('name', 'ilike', '%' . $keyword . '%');
        }

        // Selectors request the complete category list without `page`. Tables pass
        // a page, so each page must contain only its own rows.
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
            'slug' => 'nullable|string|max:100|regex:/^[\pL\pN_-]+$/u|unique:categories,slug',
            'description' => 'nullable|string|max:500',
            'image' => 'nullable|string|max:500',
            'sort_order' => 'nullable|integer|min:-2147483648|max:2147483647',
            'is_active' => 'boolean',
        ]);

        if (empty($data['slug'])) {
            $data['slug'] = SlugGenerator::unique($data['name'], 'categories');
        }

        $category = Category::create($data);
        OperationLog::log('创建分类', 'category', $category->id, $category->name);

        return response()->json($category, 201);
    }

    public function update(Request $request, Category $category)
    {
        if ($request->has('sort_order') && $request->input('sort_order') === null) {
            $request->merge(['sort_order' => 0]);
        }
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'slug' => 'nullable|string|max:100|regex:/^[\pL\pN_-]+$/u|unique:categories,slug,' . $category->id,
            'description' => 'nullable|string|max:500',
            'image' => 'nullable|string|max:500',
            'sort_order' => 'nullable|integer|min:-2147483648|max:2147483647',
            'is_active' => 'boolean',
        ]);

        if (empty($data['slug'])) {
            $data['slug'] = SlugGenerator::unique($data['name'], 'categories', $category->id);
        }

        $category->update($data);
        OperationLog::log('更新分类', 'category', $category->id, $category->name);

        return response()->json($category);
    }

    public function destroy(Category $category)
    {
        if ($category->products()->count() > 0) {
            return response()->json(['message' => '该分类下有商品，无法删除。'], 422);
        }

        OperationLog::log('删除分类', 'category', $category->id, $category->name);
        $category->delete();

        return response()->json(['message' => 'ok']);
    }
}
