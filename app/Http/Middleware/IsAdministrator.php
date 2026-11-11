<?php

namespace App\Http\Middleware;

use App\Models\User;

/**
 * Deja pasar solo al administrador.
 *
 * Con la variante A1 el administrador tambien aparece en la lista de los
 * demas middlewares; esta clase queda lista para una ruta exclusiva.
 */
class IsAdministrator
{
    use AutorizaRoles;

    protected function rolesPermitidos(): array
    {
        return [User::ROLE_ADMIN];
    }
}
