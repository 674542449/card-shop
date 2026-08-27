<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\OperationLog;
use App\Http\Requests\Admin\CategoryRequest;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    public function index()
    {
        $categories = Category::withCount('products')->ordered()->get();

        return view('admin.categories.index', compact('categories'));
    }

    public function create()
    {
        $category = null;

        return view('admin.categories.form', compact('category'));
    }

    public function store(CategoryRequest $request)
    {
        $data = $request->validated();

        if (empty($data['slug'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        if (empty($data['slug'])) {
            $data['slug'] = Str::slug(Str::random(8));
        }

        $category = Category::create($data);

        OperationLog::log('创建分类', 'category', $category->id, $category->name);

        return redirect()->route('admin.categories.index')->with('success', '分类创建成功。');
    }

    public function edit(Category $category)
    {
        return view('admin.categories.form', compact('category'));
    }

    public function update(CategoryRequest $request, Category $category)
    {
        $data = $request->validated();

        if (empty($data['slug'])) {
            $data['slug'] = Str::slug($data['name']) ?: Str::slug(Str::random(8));
        }

        $category->update($data);

        OperationLog::log('更新分类', 'category', $category->id, $category->name);

        return redirect()->route('admin.categories.index')->with('success', '分类更新成功。');
    }

    public function destroy(Category $category)
    {
        if ($category->products()->count() > 0) {
            return redirect()->back()->with('error', '该分类下有商品，无法删除。');
        }

        $category->delete();

        OperationLog::log('删除分类', 'category', $category->id, $category->name);

        return redirect()->back()->with('success', '分类删除成功。');
    }
}
