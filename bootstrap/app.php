<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /**
         * ⚠ Pins each API group to one kind of bearer-token holder. Sanctum
         * authenticates whoever the token belongs to and does not care which
         * model that is, so once Zippi Fleet started minting staff tokens the
         * parent API needed an explicit check. See EnsureTokenHolder.
         */
        $middleware->alias([
            'token.holder' => \App\Http\Middleware\EnsureTokenHolder::class,
        ]);

        /**
         * A signed-out guardian must land on the PARENT login, not the ops one.
         * Laravel's default redirects every guard to route('login'), which would
         * drop a parent on the staff email/password screen with no way forward.
         */
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('parent*')
            ? route('parent.login')
            : route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /**
         * ⚠ PART P7 — A REFUSAL IS PART OF THE PRODUCT, NOT AN ERROR PAGE.
         *
         * FleetTripService throws FleetDenied when an action would break one of
         * the four safety invariants, and its message is written to be read by
         * an attendant standing at a kerb: it says why, and what to do next.
         * Letting Laravel turn that into a generic 500 "Server Error" would
         * replace "Cannot complete: Aarav Mehta is still on board" with nothing
         * anybody can act on.
         *
         * `child_ids` travels with it so the app can offer a button straight to
         * the child rather than making somebody hunt for them.
         */
        $exceptions->render(function (\App\Services\FleetDenied $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return response()->json(array_filter([
                'status' => false,
                'message' => $e->getMessage(),
                'child_ids' => $e->children ?: null,
            ], fn ($v) => $v !== null), $e->status);
        });
    })->create();
