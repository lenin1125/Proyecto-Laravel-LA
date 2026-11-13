<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SoapService;
use RuntimeException;
use Tests\TestCase;

class SoapServiceTest extends TestCase
{
    /**
     * Deja accesible la construccion de la cabecera para inspeccionarla.
     */
    private function servicioInspeccionable(): SoapService
    {
        return new class extends SoapService
        {
            public function xml(string $identidad, string $token): string
            {
                return $this->xmlSeguridad($identidad, $token);
            }
        };
    }

    public function test_no_genera_credenciales_internas_si_falta_el_token_del_usuario(): void
    {
        $this->expectException(RuntimeException::class);
        app(SoapService::class)->crearClienteConToken();
    }

    public function test_la_cabecera_ws_security_usa_el_username_del_enunciado(): void
    {
        $usuario = new User([
            'username' => 'administrador',
            'email' => 'administrador@hotel.test',
        ]);

        $xml = $this->servicioInspeccionable()->xml($usuario->soapUsername(), '1|token');

        $this->assertStringContainsString('<wsse:Username>administrador</wsse:Username>', $xml);
        $this->assertStringNotContainsString('administrador@hotel.test', $xml);
        $this->assertStringContainsString('<wsse:Password>1|token</wsse:Password>', $xml);
    }

    public function test_sin_username_conserva_el_correo_para_las_cuentas_anteriores(): void
    {
        $usuario = new User(['email' => 'lenin@hotel.test']);

        $xml = $this->servicioInspeccionable()->xml($usuario->soapUsername(), '1|token');

        $this->assertStringContainsString('<wsse:Username>lenin@hotel.test</wsse:Username>', $xml);
    }
}
