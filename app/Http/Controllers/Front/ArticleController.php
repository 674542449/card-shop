<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\ArticleCategory;
use League\CommonMark\CommonMarkConverter;

class ArticleController extends Controller
{
    public function index()
    {
        $categories = ArticleCategory::ordered()->get();

        $articles = Article::published()
            ->recent()
            ->with('articleCategory')
            ->paginate(10);

        $seoTitle = '文章中心 - ' . setting('site_name', 'CardShop');
        $seoDescription = setting('seo_default_description', '');
        $seoKeywords = setting('seo_default_keywords', '');
        $currentCategory = null;

        return view('front.article.list', compact(
            'categories',
            'articles',
            'seoTitle',
            'seoDescription',
            'seoKeywords',
            'currentCategory',
        ));
    }

    public function category(string $slug)
    {
        $currentCategory = ArticleCategory::where('slug', $slug)->firstOrFail();

        $categories = ArticleCategory::ordered()->get();

        $articles = Article::published()
            ->recent()
            ->where('article_category_id', $currentCategory->id)
            ->with('articleCategory')
            ->paginate(10);

        $seoTitle = $currentCategory->name . ' - 文章中心 - ' . setting('site_name', 'CardShop');
        $seoDescription = setting('seo_default_description', '');
        $seoKeywords = setting('seo_default_keywords', '');

        return view('front.article.list', compact(
            'categories',
            'articles',
            'seoTitle',
            'seoDescription',
            'seoKeywords',
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

        $converter = new CommonMarkConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
        $contentHtml = $converter->convert($article->content)->getContent();

        $relatedArticles = Article::published()
            ->where('article_category_id', $article->article_category_id)
            ->where('id', '!=', $article->id)
            ->recent()
            ->limit(5)
            ->get();

        $seoTitle = ($article->seo_title ?: $article->title) . ' - ' . setting('site_name', 'CardShop');
        $seoDescription = $article->seo_description ?: ($article->summary ?: setting('seo_default_description', ''));
        $seoKeywords = $article->seo_keywords ?: setting('seo_default_keywords', '');

        return view('front.article.show', compact(
            'article',
            'contentHtml',
            'relatedArticles',
            'seoTitle',
            'seoDescription',
            'seoKeywords',
        ));
    }
}
