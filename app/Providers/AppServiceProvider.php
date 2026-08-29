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
        Paginator::defaultView('front.partials.pagination');
        Paginator::defaultSimpleView('front.partials.pagination');

        // Blade directive for fetching settings
        Blade::directive('setting', function (string $expression) {
            return "<?php echo e(setting({$expression})); ?>";
        });
    }
}
