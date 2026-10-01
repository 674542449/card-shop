<?php

namespace App\Providers;

use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach ([\App\Models\Article::class, \App\Models\Product::class, \App\Models\Category::class, \App\Models\Setting::class] as $model) {
            $model::saving(fn ($record) => app(\App\Services\AssetMaintenanceService::class)->restoreReferences($record->getAttributes()));
        }
        \App\Models\Article::saved(function ($article) {
            if ($article->is_published && ($article->wasRecentlyCreated || $article->wasChanged(['is_published', 'slug', 'content', 'title', 'seo_title', 'seo_description']))) {
                app(\App\Services\SeoQueue::class)->enqueue('/articles/'.$article->slug, hash('sha256', json_encode($article->getAttributes())));
            }
        });
        \App\Models\Product::saved(function ($product) {
            if ($product->is_active && ($product->wasRecentlyCreated || $product->wasChanged(['is_active', 'slug', 'name', 'description', 'price', 'seo_title', 'seo_description']))) {
                app(\App\Services\SeoQueue::class)->enqueue('/product/'.$product->slug, hash('sha256', json_encode($product->getAttributes())));
            }
        });
        \App\Models\Category::saved(function ($category) {
            if (!$category->wasRecentlyCreated && !$category->wasChanged(['is_active', 'slug', 'name', 'description'])) { return; }
            $queue = app(\App\Services\SeoQueue::class);
            $revision = 'category:'.$category->id.':'.hash('sha256', json_encode($category->getAttributes()));
            $queue->enqueue('/category/'.$category->slug, $revision);
            if ($category->wasChanged('slug')) { $queue->enqueue('/category/'.$category->getRawOriginal('slug'), $revision); }
            if ($category->wasChanged('is_active')) {
                $category->products()->select(['id', 'slug'])->chunkById(200, function ($items) use ($queue, $revision) {
                    foreach ($items as $item) { $queue->enqueue('/product/'.$item->slug, $revision); }
                });
            }
        });
        foreach ([\App\Models\Article::class => '/articles/', \App\Models\Product::class => '/product/'] as $model => $prefix) {
            $model::saved(function ($record) use ($prefix) {
                if ($record->wasChanged('slug')) { app(\App\Services\SeoQueue::class)->enqueue($prefix.$record->getRawOriginal('slug'), 'retired:'.$record->updated_at); }
                if ($record->wasChanged(['is_published', 'is_active']) && !($record->is_published ?? $record->is_active)) { app(\App\Services\SeoQueue::class)->enqueue($prefix.$record->slug, 'retired:'.$record->updated_at); }
            });
            $model::deleted(fn ($record) => app(\App\Services\SeoQueue::class)->enqueue($prefix.$record->slug, 'deleted:'.now()->toISOString()));
        }
        // A client-supplied Host must not become a signed payment callback URL.
        // APP_URL is the operator's canonical origin for links and notifications.
        URL::forceRootUrl(rtrim(config('app.url'), '/'));
        if (str_starts_with(config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // ->links() defaults to Laravel's Tailwind view, and this storefront has no
        // Tailwind — every class was inert, so both responsive blocks rendered at once
        // and the arrow SVGs had no size. front/partials/pagination.blade.php is the
        // markup front.css was written for.
        // 分页视图也跟着模板走。theme_view_path() 缺失时回落 default，所以模板不带
        // 自己的分页 partial 也不会出问题。
        Paginator::defaultView(theme_view_path('partials.pagination'));
        Paginator::defaultSimpleView(theme_view_path('partials.pagination'));

        // @themeInclude('partials.x', [...]) —— 和 @include 用法一样，区别是路径先过
        // theme_view_path()，所以模板内部互相引用不需要写死模板名，覆盖与回落都能生效。
        // 编译结果刻意照抄 Blade 自己 @include 的形状（make + get_defined_vars），
        // 这样父视图的变量照常传下去。
        Blade::directive('themeInclude', function (string $expression): string {
            return "<?php \$__themeArgs = [{$expression}];"
                . " echo \$__env->make(theme_view_path(\$__themeArgs[0]), \$__themeArgs[1] ?? [],"
                . " \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path', '__themeArgs']))->render();"
                . " unset(\$__themeArgs); ?>";
        });

        // Blade directive for fetching settings
        Blade::directive('setting', function (string $expression) {
            return "<?php echo e(setting({$expression})); ?>";
        });
    }
}
