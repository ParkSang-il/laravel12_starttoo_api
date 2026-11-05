<?php

use App\Http\Middleware\EnsureProjectAccount;
use App\Http\Middleware\ResolveProject;
use App\Http\Middleware\ValidateBroadcastAuthRequest;
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
    ->withBroadcasting(__DIR__.'/../routes/channels.php', [
        'prefix' => 'api/v1/projects/{projectCode}',
        'where' => ['projectCode' => '[a-z][a-z0-9-]{1,49}'],
        'middleware' => [
            'project.resolve',
            'auth:api',
            'project.account',
            'throttle:project-api',
            'broadcast.request',
        ],
    ])
    ->withMiddleware(function (Middleware $middleware): void {

        // Laravel sorts route middleware: select the DB before JWT loads a member.
        $middleware->prependToPriorityList(
            \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            ResolveProject::class,
        );
        $middleware->appendToPriorityList(
            \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            EnsureProjectAccount::class,
        );
        $middleware->alias([
            'project.resolve' => ResolveProject::class,
            'project.account' => EnsureProjectAccount::class,
            'broadcast.request' => ValidateBroadcastAuthRequest::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request, \Throwable $e) =>
            $request->is('api/*') || $request->expectsJson());
    })->create();
