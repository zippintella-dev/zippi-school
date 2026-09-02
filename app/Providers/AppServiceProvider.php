<?php

namespace App\Providers;

use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        /**
         * Laravel's default pagination views are Tailwind-classed. This project
         * ships hand-written CSS (no Tailwind build step), so `w-5 h-5` on the
         * chevron SVGs was a no-op and the icons expanded to fill the page.
         * Point every paginator at our own view instead of patching class names.
         */
        Paginator::defaultView('vendor.pagination.zippi');
        Paginator::defaultSimpleView('vendor.pagination.zippi');
    }
}
