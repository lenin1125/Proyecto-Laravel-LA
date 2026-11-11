<?php

namespace Tests\Feature;

use App\Models\Consumo;
use App\Models\User;
use App\Services\SoapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Matriz de permisos por rol para los cuatro endpoints de negocio.
 */
class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    /** Cuerpo exacto que exige el requerimiento. */
    private const DENEGADO = ['error' => 'Permisos insuficientes para ejecutar este método'];

    private function actuarComo(string $rol): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => $rol]));
    }

    private function simularReservaActiva(): void
    {
        $this->mock(SoapService::class, function ($mock) {
            $mock->shouldReceive('obtenerReserva')->andReturn((object) [
                'id' => '1',
                'estado' => 'Activa',
                'numero_habitacion' => '101',
                'nombre_huesped' => 'Huesped de prueba',
            ]);
        });
    }

    public function test_registrar_consumo_solo_administrador_y_servicio(): void
    {
        $this->simularReservaActiva();
        $permitidos = [User::ROLE_ADMIN, User::ROLE_SERVICIO];

        foreach (User::ROLES as $rol) {
            $this->actuarComo($rol);

            $respuesta = $this->postJson('/api/consumos', [
                'id_reserva' => '1',
                'concepto' => 'Cena',
                'monto' => 10,
            ]);

            if (in_array($rol, $permitidos, true)) {
                $respuesta->assertCreated();
            } else {
                $respuesta->assertStatus(403)->assertExactJson(self::DENEGADO);
            }
        }
    }

    public function test_consultar_cuenta_permite_administrador_servicio_y_recepcion(): void
    {
        $this->simularReservaActiva();
        $permitidos = [User::ROLE_ADMIN, User::ROLE_SERVICIO, User::ROLE_RECEPCION];

        foreach (User::ROLES as $rol) {
            $this->actuarComo($rol);

            $respuesta = $this->getJson('/api/consumos/reserva/1');

            if (in_array($rol, $permitidos, true)) {
                $respuesta->assertOk();
            } else {
                $respuesta->assertStatus(403)->assertExactJson(self::DENEGADO);
            }
        }
    }

    public function test_pagar_solo_administrador_y_cajero(): void
    {
        $permitidos = [User::ROLE_ADMIN, User::ROLE_CAJERO];

        foreach (User::ROLES as $rol) {
            $this->actuarComo($rol);

            $consumo = Consumo::create([
                'id_reserva' => '1',
                'concepto' => 'Minibar',
                'monto' => 20,
                'estado' => 'Pendiente',
            ]);

            $respuesta = $this->postJson("/api/consumos/{$consumo->id}/pagar", [
                'metodo_pago' => 'Efectivo',
            ]);

            if (in_array($rol, $permitidos, true)) {
                $respuesta->assertOk();
            } else {
                $respuesta->assertStatus(403)->assertExactJson(self::DENEGADO);
            }
        }
    }

    public function test_checkout_permite_administrador_cajero_y_recepcion(): void
    {
        $permitidos = [User::ROLE_ADMIN, User::ROLE_CAJERO, User::ROLE_RECEPCION];

        foreach (User::ROLES as $rol) {
            $this->actuarComo($rol);

            $respuesta = $this->getJson('/api/checkout-status/1');

            if (in_array($rol, $permitidos, true)) {
                $respuesta->assertOk();
            } else {
                $respuesta->assertStatus(403)->assertExactJson(self::DENEGADO);
            }
        }
    }

    public function test_sin_token_devuelve_401_y_no_403(): void
    {
        $this->getJson('/api/consumos/reserva/1')->assertUnauthorized();
        $this->getJson('/api/checkout-status/1')->assertUnauthorized();
        $this->postJson('/api/consumos', [])->assertUnauthorized();
        $this->postJson('/api/consumos/1/pagar', [])->assertUnauthorized();
    }

    public function test_usuario_sin_rol_recibe_403(): void
    {
        Sanctum::actingAs(User::factory()->sinRol()->create());

        $this->getJson('/api/checkout-status/1')
            ->assertStatus(403)
            ->assertExactJson(self::DENEGADO);
    }

    public function test_el_rol_no_se_puede_falsificar_desde_el_body(): void
    {
        $this->actuarComo(User::ROLE_CAJERO);

        $this->postJson('/api/consumos', [
            'id_reserva' => '1',
            'concepto' => 'Cena',
            'monto' => 10,
            'role' => User::ROLE_ADMIN,
        ])->assertStatus(403)->assertExactJson(self::DENEGADO);
    }

    public function test_rol_sin_permiso_no_llega_a_soap(): void
    {
        $this->actuarComo(User::ROLE_CAJERO);

        $this->mock(SoapService::class, function ($mock) {
            $mock->shouldNotReceive('obtenerReserva');
        });

        $this->postJson('/api/consumos', [
            'id_reserva' => '1',
            'concepto' => 'Cena',
            'monto' => 10,
        ])->assertStatus(403);
    }

    public function test_el_cuerpo_del_403_conserva_la_tilde(): void
    {
        $this->actuarComo(User::ROLE_CAJERO);

        $respuesta = $this->getJson('/api/consumos/reserva/1');

        $respuesta->assertStatus(403);
        $this->assertStringContainsString('método', $respuesta->getContent());
        $this->assertStringContainsString('"error"', $respuesta->getContent());
    }

    public function test_administrador_puede_las_cuatro_acciones(): void
    {
        $this->simularReservaActiva();
        $this->actuarComo(User::ROLE_ADMIN);

        $this->postJson('/api/consumos', [
            'id_reserva' => '1',
            'concepto' => 'Cena',
            'monto' => 10,
        ])->assertCreated();

        $this->getJson('/api/consumos/reserva/1')->assertOk();

        $consumo = Consumo::create([
            'id_reserva' => '1',
            'concepto' => 'Minibar',
            'monto' => 20,
            'estado' => 'Pendiente',
        ]);

        $this->postJson("/api/consumos/{$consumo->id}/pagar", [
            'metodo_pago' => 'Tarjeta',
        ])->assertOk();

        $this->getJson('/api/checkout-status/1')->assertOk();
    }

    public function test_me_sigue_abierto_para_cualquier_rol(): void
    {
        $this->actuarComo(User::ROLE_CAJERO);

        $this->getJson('/api/me')->assertOk();
    }
}
