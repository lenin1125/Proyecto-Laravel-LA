<?php

namespace App\Http\Middleware;

use App\Models\User;

/**
 * Registrar consumos: administrador y servicio de habitacion.
 */
class CanRegisterConsumptions
{
    use AutorizaRoles;

    protected function rolesPermitidos(): array
    {
        return [User::ROLE_ADMIN, User::ROLE_SERVICIO];
    }
}
