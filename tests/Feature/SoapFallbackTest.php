<?php

namespace Tests\Feature;

use App\Exceptions\SoapUnavailableException;
use App\Models\User;
use App\Services\SoapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pruebas que separan "SOAP caido" (503) de "reserva inexistente" (404).
 */
class SoapFallbackTest extends TestCase
{
    use RefreshDatabase;

    private function simularSoapCaido(): void
    {
        $this->mock(SoapService::class, function ($mock) {
            $mock->shouldReceive('obtenerReserva')
                ->andThrow(new SoapUnavailableException('caido'));
        });
    }

    public function test_store_devuelve_503_si_soap_no_responde(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->simularSoapCaido();

        $this->postJson('/api/consumos', [
            'id_reserva' => '1',
            'concepto' => 'Cena',
            'monto' => 10,
        ])->assertStatus(503)
            ->assertJsonPath('error', 'El servicio SOAP no esta disponible');
    }

    public function test_por_reserva_devuelve_503_si_soap_no_responde(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->simularSoapCaido();

        $this->getJson('/api/consumos/reserva/1')
            ->assertStatus(503)
            ->assertJsonPath('error', 'El servicio SOAP no esta disponible');
    }

    public function test_store_devuelve_404_si_la_reserva_no_existe(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->mock(SoapService::class, function ($mock) {
            $mock->shouldReceive('obtenerReserva')->andReturn(null);
        });

        $this->postJson('/api/consumos', [
            'id_reserva' => '1',
            'concepto' => 'Cena',
            'monto' => 10,
        ])->assertNotFound();
    }

    public function test_store_rechaza_un_id_reserva_que_no_es_texto(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/consumos', [
            'id_reserva' => ['1'],
            'concepto' => 'Cena',
            'monto' => 10,
        ])->assertStatus(422);
    }
}
