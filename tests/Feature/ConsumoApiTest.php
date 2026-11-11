<?php

// Declara el espacio de nombres de las pruebas de funcionalidad.
namespace Tests\Feature;

// Importa el modelo Consumo para preparar datos de prueba.
use App\Models\Consumo;

// Importa el modelo User para autenticar las peticiones.
use App\Models\User;

// Importa el servicio SOAP que se simula en las pruebas.
use App\Services\SoapService;

// Importa el trait que reinicia la base de datos de pruebas.
use Illuminate\Foundation\Testing\RefreshDatabase;

// Importa Sanctum para autenticar sin generar tokens reales.
use Laravel\Sanctum\Sanctum;

// Importa la clase base de las pruebas de Laravel.
use Tests\TestCase;

/**
 * Pruebas que confirman que las rutas de consumo exigen token.
 */
class ConsumoApiTest extends TestCase
{
    // Reinicia la base de datos de pruebas antes de cada prueba.
    use RefreshDatabase;

    /**
     * Comprueba que las cuatro rutas de consumo rechacen peticiones sin token.
     *
     * @return void
     */
    public function test_rutas_de_consumo_exigen_token(): void
    {
        // Comprueba la consulta de consumos por reserva.
        $this->getJson('/api/consumos/reserva/1')->assertUnauthorized();

        // Comprueba el registro de un consumo.
        $this->postJson('/api/consumos', [
            // Reserva usada en la peticion.
            'id_reserva' => '1',
            // Concepto del consumo.
            'concepto' => 'Consumo de prueba',
            // Monto del consumo.
            'monto' => 100,
        ])->assertUnauthorized();

        // Comprueba el pago de un consumo.
        $this->postJson('/api/consumos/1/pagar', [
            // Metodo de pago permitido por el controlador.
            'metodo_pago' => 'Efectivo',
        ])->assertUnauthorized();

        // Comprueba el estado de checkout de una reserva.
        $this->getJson('/api/checkout-status/1')->assertUnauthorized();
    }

    /**
     * Comprueba que el estado de checkout funcione con un token valido.
     *
     * @return void
     */
    public function test_checkout_status_funciona_con_token(): void
    {
        // Crea el usuario que autenticara la peticion.
        $usuario = User::factory()->create();

        // Autentica la peticion con el guard de Sanctum.
        Sanctum::actingAs($usuario);

        // Registra un consumo pendiente para la reserva 1.
        Consumo::create([
            // Reserva a la que pertenece el consumo.
            'id_reserva' => '1',
            // Concepto del consumo.
            'concepto' => 'Minibar',
            // Monto pendiente del consumo.
            'monto' => 150,
            // Estado inicial del consumo.
            'estado' => 'Pendiente',
        ]);

        // Consulta el estado de checkout de la reserva.
        $respuesta = $this->getJson('/api/checkout-status/1');

        // Comprueba la respuesta y el saldo pendiente.
        $respuesta->assertOk()
            ->assertJsonPath('id_reserva', '1')
            ->assertJsonPath('paz_y_salvo', false);

        // Comprueba que el saldo calculado sea el esperado.
        $this->assertEquals(150.0, $respuesta->json('saldo_pendiente'));
    }

    /**
     * Comprueba que la consulta por reserva combine Laravel y SOAP.
     *
     * @return void
     */
    public function test_por_reserva_combina_datos_de_soap(): void
    {
        // Crea el usuario que autenticara la peticion.
        $usuario = User::factory()->create();

        // Autentica la peticion con el guard de Sanctum.
        Sanctum::actingAs($usuario);

        // Simula la respuesta del servidor SOAP con una reserva activa.
        $this->mock(SoapService::class, function ($mock) {
            // Devuelve los datos que usa el controlador para componer la respuesta.
            $mock->shouldReceive('obtenerReserva')
                ->once()
                ->with('1')
                ->andReturn((object) [
                    // Numero de habitacion devuelto por SOAP.
                    'numero_habitacion' => '101',
                    // Nombre del huesped devuelto por SOAP.
                    'nombre_huesped' => 'Carlos Andres Mendoza',
                    // Estado de la reserva devuelto por SOAP.
                    'estado' => 'Activa',
                ]);
        });

        // Consulta los consumos de la reserva 1.
        $respuesta = $this->getJson('/api/consumos/reserva/1');

        // Comprueba que la respuesta incluya los datos de SOAP.
        $respuesta->assertOk()
            ->assertJsonPath('numero_habitacion', '101')
            ->assertJsonPath('nombre_huesped', 'Carlos Andres Mendoza')
            ->assertJsonPath('estado_reserva', 'Activa');
    }

    /**
     * Comprueba que el registro de consumo funcione con un token valido.
     *
     * @return void
     */
    public function test_store_registra_consumo_con_token(): void
    {
        // Crea el usuario que autenticara la peticion.
        $usuario = User::factory()->create();

        // Autentica la peticion con el guard de Sanctum.
        Sanctum::actingAs($usuario);

        // Simula una reserva activa devuelta por el servidor SOAP.
        $this->mock(SoapService::class, function ($mock) {
            // Permite una sola consulta de la reserva indicada.
            $mock->shouldReceive('obtenerReserva')
                ->once()
                ->with('1')
                ->andReturn((object) [
                    // Identificador de la reserva.
                    'id' => '1',
                    // Estado necesario para aceptar el consumo.
                    'estado' => 'Activa',
                ]);
        });

        // Registra un consumo valido.
        $respuesta = $this->postJson('/api/consumos', [
            // Reserva a la que se carga el consumo.
            'id_reserva' => '1',
            // Concepto del consumo.
            'concepto' => 'Cena',
            // Monto del consumo.
            'monto' => 200,
        ]);

        // Comprueba que el consumo se haya creado.
        $respuesta->assertCreated()
            ->assertJsonPath('mensaje', 'Consumo registrado exitosamente');

        // Comprueba que el consumo exista en la base de datos.
        $this->assertDatabaseHas('consumos', [
            // Reserva asociada al consumo.
            'id_reserva' => '1',
            // Concepto guardado.
            'concepto' => 'Cena',
            // Estado inicial del consumo.
            'estado' => 'Pendiente',
        ]);
    }
}
