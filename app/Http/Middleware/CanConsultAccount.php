<?php

namespace App\Http\Middleware;

use App\Models\User;

/**
 * Consultar el estado de cuenta: administrador, servicio de habitacion y
 * recepcionista. El cajero no consulta la cuenta.
 */
class CanConsultAccount
{
    use AutorizaRoles;

    protected function rolesPermitidos(): array
    {
        return [User::ROLE_ADMIN, User::ROLE_SERVICIO, User::ROLE_RECEPCION];
    }
}
