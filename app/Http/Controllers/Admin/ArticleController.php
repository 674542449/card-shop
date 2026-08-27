<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ArticleRequest;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\OperationLog;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ArticleController extends Controller
{
    public function index(Request $request)
    {
        $query = Article::with('articleCategory');

        if ($request->filled('article_category_id')) {
            $query->where('article_category_id', $request->article_category_id);
        }

        if ($request->filled('status')) {
            $query->where('is_published', $request->status);
        }

        $articles = $query->recent()->paginate(20)->appends($request->query());
        $categories = ArticleCategory::ordered()->get();

        return view('admin.articles.index', compact('articles', 'categories'));
    }

    public function create()
    {
        $categories = ArticleCategory::ordered()->get();

        return view('admin.articles.form', ['article' => null, 'categories' => $categories]);
    }

    public function store(ArticleRequest $request)
    {
        $data = $request->validated();

        if (empty($data['slug'])) {
            $data['slug'] = Str::slug($data['title']) ?: Str::slug(Str::random(8));
        }

        if ($request->hasFile('cover_image')) {
            $data['cover_image'] = $request->file('cover_image')
                ->store('uploads/articles', 'public');
        }

        $article = Article::create($data);

        OperationLog::log('创建文章', 'article', $article->id, $article->title);

        return redirect('/admin/articles')->with('success', '文章创建成功。');
    }

    public function edit(Article $article)
    {
        $categories = ArticleCategory::ordered()->get();

        return view('admin.articles.form', compact('article', 'categories'));
    }

    public function update(ArticleRequest $request, Article $article)
    {
        $data = $request->validated();

        if (empty($data['slug'])) {
            $data['slug'] = Str::slug($data['title']) ?: Str::slug(Str::random(8));
        }

        if ($request->hasFile('cover_image')) {
            $data['cover_image'] = $request->file('cover_image')
                ->store('uploads/articles', 'public');
        }

        $article->update($data);

        OperationLog::log('更新文章', 'article', $article->id, $article->title);

        return redirect('/admin/articles')->with('success', '文章更新成功。');
    }

    public function destroy(Article $article)
    {
        $article->delete();

        OperationLog::log('删除文章', 'article', $article->id, $article->title);

        return redirect()->back()->with('success', '文章已删除。');
    }
}
