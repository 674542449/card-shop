<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\OperationLog;
use App\Support\SlugGenerator;
use App\Support\AdminListQuery;
use Illuminate\Http\Request;

class ArticleController extends Controller
{
    public function productOptions(Request $request)
    {
        $data = $request->validate(['q' => 'nullable|string|max:200']);
        $products = \App\Models\Product::active()->when(filled($data['q'] ?? null),
            fn ($q) => $q->where('name', 'ilike', '%'.$data['q'].'%'))
            ->ordered()->limit(30)->get(['id', 'name']);
        return response()->json(['data' => $products]);
    }
    public function index(Request $request)
    {
        $pageSize = AdminListQuery::pageSize($request, 20, [
            'article_category_id' => 'nullable|integer',
            'is_published' => 'nullable|boolean',
            'keyword' => 'nullable|string|max:200',
            'title' => 'nullable|string|max:200',
        ]);
        $query = Article::with('articleCategory');

        if ($request->filled('article_category_id')) {
            $query->where('article_category_id', $request->article_category_id);
        }
        if ($request->filled('is_published')) {
            $query->where('is_published', $request->is_published);
        }
        // The table's search form submits the column name (`title`); accept both.
        $keyword = $request->input('keyword', $request->input('title'));
        if (filled($keyword)) {
            $query->where('title', 'ilike', '%' . $keyword . '%');
        }

        $articles = $query->recent()->paginate($pageSize);
        $categories = ArticleCategory::ordered()->get(['id', 'name']);

        return response()->json([
            'data' => $articles->items(),
            'total' => $articles->total(),
            'categories' => $categories,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => 'required|string|max:200',
            'slug' => 'nullable|string|max:200|regex:/^[\pL\pN_-]+$/u|unique:articles,slug',
            // articles.article_category_id is NOT NULL in the schema, so accepting null
            // here turns a missing category into a Postgres violation and a 500.
            'article_category_id' => 'required|integer|min:1|exists:article_categories,id',
            'summary' => 'nullable|string|max:500',
            'content' => 'required|string|max:1000000',
            'cover_image' => 'nullable|string|max:500',
            'is_published' => 'boolean',
            'seo_title' => 'nullable|string|max:200',
            'seo_description' => 'nullable|string|max:500',
            'seo_keywords' => 'nullable|string|max:200',
        ]);

        $article = SlugGenerator::persist('articles', $data, $data['title'], function ($values) {
            $this->lockCategory($values['article_category_id']);
            return Article::create($values);
        });
        OperationLog::log('创建文章', 'article', $article->id, $article->title);

        return response()->json($article, 201);
    }

    public function show(Article $article)
    {
        $article->load('articleCategory');
        return response()->json($article);
    }

    public function update(Request $request, Article $article)
    {
        $data = $request->validate([
            'title' => 'required|string|max:200',
            'slug' => 'nullable|string|max:200|regex:/^[\pL\pN_-]+$/u|unique:articles,slug,' . $article->id,
            // articles.article_category_id is NOT NULL in the schema, so accepting null
            // here turns a missing category into a Postgres violation and a 500.
            'article_category_id' => 'required|integer|min:1|exists:article_categories,id',
            'summary' => 'nullable|string|max:500',
            'content' => 'required|string|max:1000000',
            'cover_image' => 'nullable|string|max:500',
            'is_published' => 'boolean',
            'seo_title' => 'nullable|string|max:200',
            'seo_description' => 'nullable|string|max:500',
            'seo_keywords' => 'nullable|string|max:200',
        ]);

        SlugGenerator::persist('articles', $data, $data['title'], function ($values) use ($article) {
            $this->lockCategory($values['article_category_id']);
            $article->update($values);
        }, $article->id);
        OperationLog::log('更新文章', 'article', $article->id, $article->title);

        return response()->json($article);
    }

    public function destroy(Article $article)
    {
        OperationLog::log('删除文章', 'article', $article->id, $article->title);
        $article->delete();

        return response()->json(['message' => 'ok']);
    }

    private function lockCategory(int $id): void
    {
        if (! ArticleCategory::whereKey($id)->sharedLock()->first(['id'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['article_category_id' => '文章分类已删除，请重新选择。']);
        }
    }
}
