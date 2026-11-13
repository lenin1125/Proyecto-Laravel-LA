<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of exception types with their corresponding custom log levels.
     *
     * @var array<class-string<\Throwable>, \Psr\Log\LogLevel::*>
     */
    protected $levels = [
        //
    ];

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<\Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed to the session on validation exceptions.
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
     * Todas las respuestas de error de la API usan la clave "mensaje".
     * El 403 de permisos conserva "error" porque es parte del contrato
     * acordado con el cliente.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        // 422: se mantiene "errors" para el detalle por campo y se agrega
        // "mensaje" como resumen legible.
        $this->renderable(function (ValidationException $e, Request $request) {
            if (! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'mensaje' => 'Los datos enviados no son validos.',
                'errors' => $e->errors(),
            ], $e->status);
        });

        // 401: token ausente, invalido o expirado.
        $this->renderable(function (AuthenticationException $e, Request $request) {
            if (! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'mensaje' => 'No autenticado. Se requiere un token Bearer valido.',
            ], 401);
        });

        // 429: limite de solicitudes o de intentos de login alcanzado.
        $this->renderable(function (ThrottleRequestsException $e, Request $request) {
            if (! $request->expectsJson()) {
                return null;
            }

            $segundos = (int) ($e->getHeaders()['Retry-After'] ?? 60);

            return response()->json([
                'mensaje' => "Demasiadas solicitudes. Reintente en {$segundos} segundos.",
            ], 429, $e->getHeaders());
        });

        // 404: ruta o recurso inexistente.
        $this->renderable(function (NotFoundHttpException $e, Request $request) {
            if (! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'mensaje' => 'Recurso no encontrado.',
            ], 404);
        });

        // 405: metodo HTTP no admitido por la ruta.
        $this->renderable(function (MethodNotAllowedHttpException $e, Request $request) {
            if (! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'mensaje' => 'Metodo no permitido para esta ruta.',
            ], 405);
        });
    }
}
