<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Indica que el servidor SOAP no respondio o respondio con un error.
 *
 * Se distingue de una reserva inexistente: aqui el servicio esta caido o
 * rechazo la llamada, por lo que la respuesta correcta es 503 y no 404.
 */
class SoapUnavailableException extends RuntimeException
{
}
