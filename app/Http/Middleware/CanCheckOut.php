<?php

namespace App\Http\Middleware;

use App\Models\User;

/**
 * Verificar el checkout: administrador, cajero y recepcionista.
 */
class CanCheckOut
{
    use AutorizaRoles;

    protected function rolesPermitidos(): array
    {
        return [User::ROLE_ADMIN, User::ROLE_CAJERO, User::ROLE_RECEPCION];
    }
}
