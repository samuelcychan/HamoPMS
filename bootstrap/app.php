<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\ResolvePropertyContext;
use App\Http\Responses\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->statefulApi();
        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'permission' => RequirePermission::class,
            'property.context' => ResolvePropertyContext::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*'));

        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                'VALIDATION_FAILED',
                'The request contains invalid data.',
                422,
                ['fields' => $exception->errors()],
            );
        });

        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            return $request->is('api/*')
                ? ApiResponse::error('UNAUTHENTICATED', 'Authentication is required.', 401)
                : null;
        });

        $exceptions->render(function (ModelNotFoundException $exception, Request $request) {
            return $request->is('api/*')
                ? ApiResponse::error('RESOURCE_NOT_FOUND', 'The requested resource was not found.', 404)
                : null;
        });

        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $status = $exception instanceof HttpExceptionInterface
                ? $exception->getStatusCode()
                : 500;
            $codes = [
                401 => 'UNAUTHENTICATED',
                403 => 'FORBIDDEN',
                404 => 'RESOURCE_NOT_FOUND',
                405 => 'METHOD_NOT_ALLOWED',
                409 => 'CONFLICT',
                429 => 'RATE_LIMIT_EXCEEDED',
            ];
            $messages = [
                401 => 'Authentication is required.',
                403 => 'You are not allowed to perform this action.',
                404 => 'The requested resource was not found.',
                405 => 'The HTTP method is not supported for this endpoint.',
                409 => 'The request conflicts with the current resource state.',
                429 => 'Too many requests. Please try again later.',
            ];

            return ApiResponse::error(
                $codes[$status] ?? 'HTTP_ERROR',
                $messages[$status] ?? 'The request could not be completed.',
                $status,
            );
        });
    })->create();
