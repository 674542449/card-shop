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
