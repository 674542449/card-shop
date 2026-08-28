<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\OperationLog;
use App\Support\SlugGenerator;
use Illuminate\Http\Request;

class ArticleController extends Controller
{
    public function index(Request $request)
    {
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

        $articles = $query->recent()->paginate($request->get('pageSize', 20));
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
            'slug' => 'nullable|string|max:200|unique:articles,slug',
            // articles.article_category_id is NOT NULL in the schema, so accepting null
            // here turns a missing category into a Postgres violation and a 500.
            'article_category_id' => 'required|exists:article_categories,id',
            'summary' => 'nullable|string|max:500',
            'content' => 'required|string',
            'cover_image' => 'nullable|string|max:500',
            'is_published' => 'boolean',
            'seo_title' => 'nullable|string|max:200',
            'seo_description' => 'nullable|string|max:500',
            'seo_keywords' => 'nullable|string|max:200',
        ]);

        if (empty($data['slug'])) {
            $data['slug'] = SlugGenerator::unique($data['title'], 'articles');
        }

        $article = Article::create($data);
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
            'slug' => 'nullable|string|max:200|unique:articles,slug,' . $article->id,
            // articles.article_category_id is NOT NULL in the schema, so accepting null
            // here turns a missing category into a Postgres violation and a 500.
            'article_category_id' => 'required|exists:article_categories,id',
            'summary' => 'nullable|string|max:500',
            'content' => 'required|string',
            'cover_image' => 'nullable|string|max:500',
            'is_published' => 'boolean',
            'seo_title' => 'nullable|string|max:200',
            'seo_description' => 'nullable|string|max:500',
            'seo_keywords' => 'nullable|string|max:200',
        ]);

        if (empty($data['slug'])) {
            $data['slug'] = SlugGenerator::unique($data['title'], 'articles', $article->id);
        }

        $article->update($data);
        OperationLog::log('更新文章', 'article', $article->id, $article->title);

        return response()->json($article);
    }

    public function destroy(Article $article)
    {
        OperationLog::log('删除文章', 'article', $article->id, $article->title);
        $article->delete();

        return response()->json(['message' => 'ok']);
    }
}
