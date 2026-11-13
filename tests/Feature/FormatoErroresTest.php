<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Formato comun de los errores: la clave "mensaje" en todas las respuestas,
 * salvo el 403 de permisos, que conserva "error" por contrato.
 */
class FormatoErroresTest extends TestCase
{
    use RefreshDatabase;

    public function test_sin_token_devuelve_401_con_mensaje(): void
    {
        $this->getJson('/api/me')
            ->assertUnauthorized()
            ->assertExactJson(['mensaje' => 'No autenticado. Se requiere un token Bearer valido.']);
    }

    public function test_validacion_devuelve_422_con_mensaje_y_errores(): void
    {
        $this->postJson('/api/login', [])
            ->assertUnprocessable()
            ->assertJsonPath('mensaje', 'Los datos enviados no son validos.')
            ->assertJsonValidationErrors(['usuario', 'email', 'password']);
    }

    public function test_ruta_inexistente_devuelve_404_con_mensaje(): void
    {
        $this->getJson('/api/ruta-que-no-existe')
            ->assertNotFound()
            ->assertExactJson(['mensaje' => 'Recurso no encontrado.']);
    }

    public function test_metodo_no_permitido_devuelve_405_con_mensaje(): void
    {
        $this->getJson('/api/login')
            ->assertStatus(405)
            ->assertExactJson(['mensaje' => 'Metodo no permitido para esta ruta.']);
    }

    public function test_consumo_inexistente_devuelve_404_con_mensaje(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));

        $this->postJson('/api/consumos/999999/pagar', ['metodo_pago' => 'Efectivo'])
            ->assertNotFound()
            ->assertExactJson(['mensaje' => 'Consumo no encontrado']);
    }

    public function test_el_403_conserva_la_clave_error_por_contrato(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_CAJERO]));

        $this->postJson('/api/consumos', [
            'id_reserva' => '1',
            'concepto' => 'Cena',
            'monto' => 10,
        ])->assertStatus(403)
            ->assertExactJson(['error' => 'Permisos insuficientes para ejecutar este método']);
    }
}
