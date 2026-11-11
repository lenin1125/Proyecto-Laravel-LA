<?php

namespace App\Http\Middleware;

use App\Models\User;

/**
 * Cobrar consumos: administrador y cajero.
 */
class CanProcessPayments
{
    use AutorizaRoles;

    protected function rolesPermitidos(): array
    {
        return [User::ROLE_ADMIN, User::ROLE_CAJERO];
    }
}
