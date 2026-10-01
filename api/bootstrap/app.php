<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Mordomus\Financial\Console\Commands\ProjectBillsCommand;
use Mordomus\Http\Exceptions\ApiException;
use Mordomus\Http\Middleware\Can;
use Mordomus\Http\Middleware\ClearTenantContext;
use Mordomus\Http\Middleware\RequestContext;
use Mordomus\Http\Middleware\TenantScope;
use Mordomus\Http\Responses\ErrorEnvelope;
use Mordomus\Scheduling\Console\Commands\MaterializeOccurrencesCommand;
use Mordomus\Scheduling\Console\Commands\PublishDueNoticesCommand;
use Mordomus\Support\Logging\LogContext;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    // Comandos de console por módulo (ADR-011). As classes entram na mão
    // porque a descoberta por diretório do `withCommands` monta o FQCN a
    // partir do único prefixo do `app/` (`Mordomus\`), e aqui cada módulo tem
    // o seu (`Mordomus\Scheduling\` → `app/Modules/Scheduling`) — o nome
    // traduzido sairia `Mordomus\Modules\Scheduling\...` e não carregaria.
    ->withCommands([
        MaterializeOccurrencesCommand::class,
        PublishDueNoticesCommand::class,
        ProjectBillsCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware) {
        // sem rota de login: 401 JSON em vez de route('login')
        $middleware->redirectGuestsTo(fn () => null);

        // TenantContext é estático e o worker FPM é reutilizado — limpa antes
        // e depois de cada requisição
        $middleware->prepend(ClearTenantContext::class);
        $middleware->prepend(RequestContext::class);

        $middleware->alias([
            'tenant' => TenantScope::class,
            'capability' => Can::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $envelope = fn (Request $request, int $status, string $code, string $message, array $details = []) => ErrorEnvelope::make($request, $status, $code, $message, $details);

        $logFailure = function (Request $request, int $status, string $code, Throwable $exception): void {
            $context = [
                'status' => $status,
                'code' => $code,
                'method' => $request->method(),
                'path' => LogContext::path($request),
                'exception' => $exception,
            ];

            match (true) {
                $status >= 500 => Log::error('http.server_error', $context),
                $status === 429 => Log::warning('http.rate_limited', $context),
                $status === 403 => Log::warning('http.forbidden', $context),
                default => null,
            };
        };

        $exceptions->render(function (ValidationException $exception, Request $request) use ($envelope) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return $envelope($request, 422, 'validation_failed', $exception->getMessage(), $exception->errors());
        });

        $exceptions->render(function (AuthenticationException $exception, Request $request) use ($envelope) {
            if (! $request->is('api/*')) {
                return null;
            }

            Log::warning('auth.unauthenticated', [
                'method' => $request->method(),
                'path' => LogContext::path($request),
            ]);

            return $envelope($request, 401, 'unauthenticated', 'Autenticação necessária.');
        });

        $exceptions->render(function (ApiException $exception, Request $request) use ($envelope, $logFailure) {
            if (! $request->is('api/*')) {
                return null;
            }

            $status = $exception->status();

            $logFailure($request, $status, $exception->errorCode(), $exception);

            return $envelope($request, $status, $exception->errorCode(), $exception->getMessage(), $exception->details());
        });

        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) use ($envelope, $logFailure) {
            if (! $request->is('api/*')) {
                return null;
            }

            $status = $exception->getStatusCode();
            $message = trim($exception->getMessage());

            $defaultCodes = [
                401 => 'unauthenticated',
                403 => 'forbidden',
                404 => 'not_found',
                405 => 'method_not_allowed',
                409 => 'conflict',
                410 => 'gone',
                419 => 'page_expired',
                429 => 'rate_limited',
                500 => 'server_error',
            ];

            $code = preg_match('/^[a-z][a-z0-9_]*$/', $message) === 1
                ? $message
                : ($defaultCodes[$status] ?? 'http_error');

            if (preg_match('/^[a-z][a-z0-9_]*$/', $message) !== 1) {
                $message = match ($status) {
                    401 => 'Autenticação necessária.',
                    403 => 'Operação não permitida.',
                    404 => 'Recurso não encontrado.',
                    429 => 'Muitas requisições — tente novamente em instantes.',
                    default => $status >= 500 ? 'Erro interno.' : 'Requisição inválida.',
                };
            }

            $logFailure($request, $status, $code, $exception);

            return $envelope($request, $status, $code, $message);
        });
    })->create();
