<?php

namespace App\Exceptions;

use App\Models\AppErrorLog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     *
     * @return void
     */
    public function register()
    {
        $this->reportable(function (Throwable $e) {
            $this->persistError($e);
        });
    }

    /**
     * Exception types that should NOT be recorded in the error log table.
     * These are expected, user-driven conditions rather than application bugs.
     *
     * @var array<int, class-string<Throwable>>
     */
    protected array $dontPersist = [
        ValidationException::class,
        AuthenticationException::class,
        AuthorizationException::class,
        ModelNotFoundException::class,
        NotFoundHttpException::class,
    ];

    /**
     * Persist an exception to the app_error_logs table so a super admin can
     * review and download it later.
     *
     * Guarded heavily: any failure while logging is swallowed so it can never
     * cascade into a second error while handling the first one.
     */
    protected function persistError(Throwable $e): void
    {
        try {
            if (! $this->shouldPersist($e)) {
                return;
            }

            if (! Schema::hasTable('app_error_logs')) {
                return;
            }

            $request = request();
            $user    = $request ? $request->user() : null;

            AppErrorLog::create([
                'level'       => 'error',
                'type'        => get_class($e),
                'message'     => $e->getMessage() ?: '(no message)',
                'status_code' => $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500,
                'file'        => $e->getFile(),
                'line'        => $e->getLine(),
                'method'      => $request?->method(),
                'url'         => $request?->fullUrl(),
                'ip'          => $request?->ip(),
                'user_id'     => $user?->id,
                'user_name'   => $user?->name,
                'trace'       => $e->getTraceAsString(),
                'context'     => [
                    'previous' => $e->getPrevious() ? get_class($e->getPrevious()) : null,
                    'code'     => $e->getCode(),
                ],
                'created_at'  => now(),
            ]);
        } catch (Throwable $ignored) {
            // Never let error-logging break the response.
        }
    }

    /**
     * Decide whether a given exception is worth recording. We only record
     * genuine application errors (HTTP 5xx / uncaught exceptions), skipping
     * the expected user-facing ones (validation, auth, 404, 403, ...).
     */
    protected function shouldPersist(Throwable $e): bool
    {
        foreach ($this->dontPersist as $type) {
            if ($e instanceof $type) {
                return false;
            }
        }

        if ($e instanceof HttpExceptionInterface && $e->getStatusCode() < 500) {
            return false;
        }

        return true;
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Throwable  $e
     * @return \Symfony\Component\HttpFoundation\Response
     *
     * @throws \Throwable
     */
    public function render($request, Throwable $e)
    {
        if ($request->is('api/*') || $request->expectsJson()) {
            return $this->handleApiException($request, $e);
        }

        return parent::render($request, $e);
    }

    /**
     * Build a consistent JSON error response for the API.
     */
    protected function handleApiException(Request $request, Throwable $e)
    {
        if ($e instanceof ValidationException) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);
        }

        if ($e instanceof AuthenticationException) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if ($e instanceof ModelNotFoundException || $e instanceof NotFoundHttpException) {
            return response()->json([
                'success' => false,
                'message' => 'Resource not found.',
            ], 404);
        }

        $status  = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
        $message = $e->getMessage() ?: 'Server Error.';

        $payload = [
            'success' => false,
            'message' => $message,
        ];

        if (config('app.debug')) {
            $payload['exception'] = get_class($e);
            $payload['file']      = $e->getFile();
            $payload['line']      = $e->getLine();
        }

        return response()->json($payload, $status);
    }
}
