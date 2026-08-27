<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ArticleCategoryRequest;
use App\Models\ArticleCategory;
use App\Models\OperationLog;
use Illuminate\Support\Str;

class ArticleCategoryController extends Controller
{
    public function index()
    {
        $categories = ArticleCategory::withCount('articles')->ordered()->get();

        return view('admin.article-categories.index', compact('categories'));
    }

    public function create()
    {
        return view('admin.article-categories.form', ['category' => null]);
    }

    public function store(ArticleCategoryRequest $request)
    {
        $data = $request->validated();

        if (empty($data['slug'])) {
            $data['slug'] = Str::slug($data['name']) ?: Str::slug(Str::random(8));
        }

        $category = ArticleCategory::create($data);

        OperationLog::log('创建文章分类', 'article_category', $category->id, $category->name);

        return redirect('/admin/article-categories')->with('success', '分类创建成功。');
    }

    public function edit(ArticleCategory $article_category)
    {
        $category = $article_category;

        return view('admin.article-categories.form', compact('category'));
    }

    public function update(ArticleCategoryRequest $request, ArticleCategory $article_category)
    {
        $data = $request->validated();

        if (empty($data['slug'])) {
            $data['slug'] = Str::slug($data['name']) ?: Str::slug(Str::random(8));
        }

        $article_category->update($data);

        OperationLog::log('更新文章分类', 'article_category', $article_category->id, $article_category->name);

        return redirect('/admin/article-categories')->with('success', '分类更新成功。');
    }

    public function destroy(ArticleCategory $article_category)
    {
        if ($article_category->articles()->count() > 0) {
            return redirect()->back()->with('error', '该分类下还有文章，无法删除。');
        }

        $article_category->delete();

        OperationLog::log('删除文章分类', 'article_category', $article_category->id, $article_category->name);

        return redirect()->back()->with('success', '分类已删除。');
    }
}
