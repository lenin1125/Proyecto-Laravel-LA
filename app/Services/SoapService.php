<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use SoapClient;
use Throwable;

class SoapService
{
    protected string $wsdlUrl;

    protected string $serviceUrl;

    protected int $timeout;

    public function __construct()
    {
        $serviceUrl = (string) config(
            'services.soap.service_url',
            'http://127.0.0.1/hotel/server.php'
        );

        $this->serviceUrl = rtrim($serviceUrl, '/');

        $wsdlUrl = (string) config('services.soap.wsdl_url', '');

        if ($wsdlUrl === '') {
            if (strpos($this->serviceUrl, '?') === false) {
                $wsdlUrl = $this->serviceUrl.'?wsdl';
            } else {
                $wsdlUrl = $this->serviceUrl;
            }
        }

        $this->wsdlUrl = $wsdlUrl;

        $timeout = (int) config('services.soap.timeout', 15);

        $this->timeout = $timeout > 0 ? $timeout : 15;
    }

    public function crearClienteConToken(): SoapClient
    {
        $request = request();
        $usuario = $request->user();
        $token = $request->bearerToken();
        if (! $usuario || ! $token) {
            throw new \RuntimeException('Se requiere el token del usuario autenticado para consultar SOAP.');
        }

        $client = new SoapClient($this->wsdlUrl, [
            'location' => $this->serviceUrl,
            'exceptions' => true,
            'connection_timeout' => $this->timeout,
            'cache_wsdl' => WSDL_CACHE_NONE,
            'stream_context' => stream_context_create(['http' => ['timeout' => $this->timeout]]),
        ]);
        $ns = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';
        $xml = '<wsse:Security xmlns:wsse="'.$ns.'"><wsse:UsernameToken><wsse:Username>'
            .htmlspecialchars($usuario->email, ENT_QUOTES | ENT_XML1, 'UTF-8')
            .'</wsse:Username><wsse:Password>'.htmlspecialchars($token, ENT_QUOTES | ENT_XML1, 'UTF-8')
            .'</wsse:Password></wsse:UsernameToken></wsse:Security>';
        $client->__setSoapHeaders(new \SoapHeader($ns, 'Security', new \SoapVar($xml, XSD_ANYXML)));

        return $client;
    }

    public function obtenerReserva($idReserva)
    {
        try {
            $client = $this->crearClienteConToken();

            $respuesta = $client->consultarReservas();

            if (is_string($respuesta)) {
                $decoded = json_decode($respuesta, true);

                if (json_last_error() === JSON_ERROR_NONE) {
                    $respuesta = $decoded;
                }
            }

            if (is_array($respuesta) && isset($respuesta['id'])) {
                $reservas = [$respuesta];
            } elseif (is_array($respuesta)) {
                $reservas = $respuesta;
            } else {
                $reservas = [$respuesta];
            }

            foreach ($reservas as $reserva) {
                if (is_array($reserva)) {
                    $reservaObj = (object) $reserva;
                } elseif (is_object($reserva)) {
                    $reservaObj = $reserva;
                } else {
                    continue;
                }

                if (isset($reservaObj->id) && (string) $reservaObj->id === (string) $idReserva) {
                    return $reservaObj;
                }
            }

            return null;
        } catch (Throwable $e) {
            Log::warning('SOAP consultarReservas fallido: '.$e->getMessage());

            return null;
        }
    }
}
