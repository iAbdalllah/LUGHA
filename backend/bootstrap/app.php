<?php

use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\SetLocale;
use App\Support\ApiProblem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            SetLocale::class,
            EnsureAccountActive::class,
        ]);
        // Gateways can't send our CSRF token; webhook authenticity is checked by the gateway adapter instead.
        $middleware->preventRequestForgery(except: ['webhooks/payments/*']);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->alias([
            'ability' => CheckForAnyAbility::class,
            'abilities' => CheckAbilities::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // API errors as RFC 9457 problem+json with a stable machine-readable `code`.
        $exceptions->render(fn (Throwable $e, Request $request) => $request->is('api/*') ? ApiProblem::from($e) : null);
    })->create();
