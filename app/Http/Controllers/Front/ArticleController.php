<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Http\Middleware\NoIndex;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Support\ContentRenderer;
use App\Support\Seo;
use Illuminate\Http\Request;

class ArticleController extends Controller
{
    public function index(Request $request)
    {
        $request->validate(['page' => 'nullable|integer|min:1']);

        $categories = ArticleCategory::ordered()
            ->withCount(['articles' => fn ($q) => $q->published()])
            ->get();
        $totalPublishedArticles = Article::published()->count();

        $articles = Article::published()
            ->recent()
            ->with('articleCategory')
            ->paginate(10);

        if ($articles->currentPage() > 1 && $articles->isEmpty()) {
            NoIndex::mark($request, NoIndex::SEARCH_DIRECTIVE);
        }

        $seoTitle = Seo::paginationTitle('文章中心', $articles) . ' - ' . setting('site_name', 'CardShop');
        $seoDescription = setting('seo_default_description', '') ?: setting('site_description', '');
        $seoKeywords = setting('seo_default_keywords', '');
        $seoCanonical = Seo::paginationCanonical(Seo::url('/articles'), $articles);
        $currentCategory = null;

        return theme_view('article.list', compact(
            'categories',
            'totalPublishedArticles',
            'articles',
            'seoTitle',
            'seoDescription',
            'seoKeywords',
            'seoCanonical',
            'currentCategory',
        ));
    }

    public function category(Request $request, string $slug)
    {
        $request->validate(['page' => 'nullable|integer|min:1']);

        $currentCategory = ArticleCategory::where('slug', $slug)->firstOrFail();

        $categories = ArticleCategory::ordered()
            ->withCount(['articles' => fn ($q) => $q->published()])
            ->get();
        $totalPublishedArticles = Article::published()->count();

        $articles = Article::published()
            ->recent()
            ->where('article_category_id', $currentCategory->id)
            ->with('articleCategory')
            ->paginate(10);

        if ($articles->currentPage() > 1 && $articles->isEmpty()) {
            NoIndex::mark($request, NoIndex::SEARCH_DIRECTIVE);
        }

        $seoTitle = Seo::paginationTitle($currentCategory->name, $articles) . ' - 文章中心 - ' . setting('site_name', 'CardShop');
        $baseDesc = setting('seo_default_description', '') ?: setting('site_description', '');
        $seoDescription = Seo::excerpt(
            $currentCategory->name . '专栏共收录 ' . $articles->total() . ' 篇使用指南与常见问题说明。' . ($baseDesc !== '' ? ' ' . $baseDesc : ''),
            160,
        );
        $seoKeywords = setting('seo_default_keywords', '');
        $seoCanonical = Seo::paginationCanonical(Seo::url('/articles/category/' . $currentCategory->slug), $articles);

        return theme_view('article.list', compact(
            'categories',
            'totalPublishedArticles',
            'articles',
            'seoTitle',
            'seoDescription',
            'seoKeywords',
            'seoCanonical',
            'currentCategory',
        ));
    }

    public function show(string $slug)
    {
        $article = Article::published()
            ->where('slug', $slug)
            ->with('articleCategory')
            ->firstOrFail();

        $article->increment('views');

        $contentHtml = ContentRenderer::toHtml($article->content, true);

        $relatedArticles = Article::published()
            ->where('article_category_id', $article->article_category_id)
            ->where('id', '!=', $article->id)
            ->recent()
            ->limit(5)
            ->get();

        $seoTitle = ($article->seo_title ?: $article->title) . ' - ' . setting('site_name', 'CardShop');
        $seoDescription = $article->seo_description
            ?: ($article->summary ? Seo::excerpt($article->summary, 160) : Seo::excerpt($contentHtml, 160))
            ?: setting('seo_default_description', '')
            ?: setting('site_description', '');
        $seoKeywords = $article->seo_keywords ?: setting('seo_default_keywords', '');

        return theme_view('article.show', compact(
            'article',
            'contentHtml',
            'relatedArticles',
            'seoTitle',
            'seoDescription',
            'seoKeywords',
        ));
    }
}
