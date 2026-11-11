<?php

namespace Tests\Feature;

use App\Services\SoapService;
use RuntimeException;
use Tests\TestCase;

class SoapServiceTest extends TestCase
{
    public function test_no_genera_credenciales_internas_si_falta_el_token_del_usuario(): void
    {
        $this->expectException(RuntimeException::class);
        app(SoapService::class)->crearClienteConToken();
    }
}
