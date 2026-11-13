<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logica comun de los middlewares de rol.
 *
 * Cada middleware declara su lista de roles permitidos y este trait se
 * encarga de comparar contra el rol del usuario autenticado.
 *
 * Importante: el rol se lee del usuario de la base de datos, nunca del
 * cuerpo de la peticion.
 */
trait AutorizaRoles
{
    /**
     * Roles que pueden ejecutar la accion protegida.
     *
     * @return array<int, string>
     */
    abstract protected function rolesPermitidos(): array;

    public function handle(Request $request, Closure $next): Response
    {
        // El enunciado exige leer el rol manualmente desde el usuario
        // autenticado. Nunca se toma del cuerpo ni de la cabecera.
        $rol = $request->user()?->role;

        if ($rol === null || ! in_array($rol, $this->rolesPermitidos(), true)) {
            return response()->json(
                ['error' => 'Permisos insuficientes para ejecutar este método'],
                403,
                [],
                JSON_UNESCAPED_UNICODE
            );
        }

        return $next($request);
    }
}
