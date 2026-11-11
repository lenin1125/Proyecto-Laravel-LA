<?php

namespace Tests\Feature;

use App\Models\Consumo;
use App\Models\PagoConsumo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pruebas del pago de consumos: transaccion, doble pago y tipos de respuesta.
 */
class ConsumoPagoTest extends TestCase
{
    use RefreshDatabase;

    private function consumoPendiente(): Consumo
    {
        return Consumo::create([
            'id_reserva' => '1',
            'concepto' => 'Minibar',
            'monto' => 25.50,
            'estado' => 'Pendiente',
        ]);
    }

    public function test_pagar_marca_consumo_y_registra_pago(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $consumo = $this->consumoPendiente();

        $respuesta = $this->postJson("/api/consumos/{$consumo->id}/pagar", [
            'metodo_pago' => 'Efectivo',
        ]);

        $respuesta->assertOk()
            ->assertJsonPath('consumo.estado', 'Pagado')
            ->assertJsonPath('pago.metodo_pago', 'Efectivo');

        $this->assertDatabaseHas('consumos', [
            'id' => $consumo->id,
            'estado' => 'Pagado',
        ]);

        $this->assertDatabaseHas('pagos_consumos', [
            'consumo_id' => $consumo->id,
            'metodo_pago' => 'Efectivo',
        ]);
    }

    public function test_segundo_pago_no_duplica_el_registro(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $consumo = $this->consumoPendiente();

        $this->postJson("/api/consumos/{$consumo->id}/pagar", [
            'metodo_pago' => 'Efectivo',
        ])->assertOk();

        $this->postJson("/api/consumos/{$consumo->id}/pagar", [
            'metodo_pago' => 'Tarjeta',
        ])->assertStatus(400);

        $this->assertSame(1, PagoConsumo::where('consumo_id', $consumo->id)->count());
    }

    public function test_pagar_consumo_inexistente_devuelve_404(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/consumos/999999/pagar', [
            'metodo_pago' => 'Tarjeta',
        ])->assertNotFound();
    }

    public function test_pagar_valida_el_metodo_de_pago(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $consumo = $this->consumoPendiente();

        $this->postJson("/api/consumos/{$consumo->id}/pagar", [
            'metodo_pago' => 'Cheque',
        ])->assertStatus(422);
    }

    public function test_monto_se_devuelve_como_numero(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->consumoPendiente();

        $respuesta = $this->getJson('/api/checkout-status/1');

        $respuesta->assertOk();
        $this->assertIsFloat($respuesta->json('saldo_pendiente'));
    }
}
