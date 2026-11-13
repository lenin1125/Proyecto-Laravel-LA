<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\HotelUsuarioSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Pruebas del limite de intentos de login.
 *
 * El limite real es de 5 intentos por minuto; aqui se baja a 3 para no
 * enviar peticiones innecesarias.
 */
class LoginThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'auth.login_throttle.max_attempts' => 3,
            'auth.login_throttle.decay_minutes' => 1,
        ]);
    }

    private function crearUsuario(): User
    {
        return User::factory()->create([
            'email' => 'administrador@hotel.test',
            'password' => Hash::make('123456'),
        ]);
    }

    private function intento(string $email, string $password)
    {
        return $this->postJson('/api/login', ['email' => $email, 'password' => $password]);
    }

    public function test_bloquea_el_login_tras_varios_intentos_fallidos(): void
    {
        $this->crearUsuario();
        $this->mock(HotelUsuarioSync::class, function ($mock) {
            $mock->shouldNotReceive('sincronizar');
        });

        for ($intento = 1; $intento <= 3; $intento++) {
            $this->intento('administrador@hotel.test', 'incorrecta')->assertStatus(401);
        }

        // El cuarto intento ya no se evalua, ni siquiera con la clave correcta.
        $respuesta = $this->intento('administrador@hotel.test', '123456');

        $respuesta->assertStatus(429)
            ->assertJsonPath('mensaje', 'Demasiadas solicitudes. Reintente en 60 segundos.');

        $this->assertNotNull($respuesta->headers->get('Retry-After'));
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_el_limite_se_cuenta_por_correo(): void
    {
        $this->crearUsuario();
        $this->mock(HotelUsuarioSync::class, function ($mock) {
            $mock->shouldNotReceive('sincronizar');
        });

        for ($intento = 1; $intento <= 3; $intento++) {
            $this->intento('administrador@hotel.test', 'incorrecta')->assertStatus(401);
        }

        // Otro correo conserva su propio contador.
        $this->intento('otro@hotel.test', 'incorrecta')->assertStatus(401);
    }

    public function test_un_login_correcto_dentro_del_limite_funciona(): void
    {
        $this->crearUsuario();
        $this->mock(HotelUsuarioSync::class, function ($mock) {
            $mock->shouldReceive('sincronizar')->once();
        });

        $this->intento('administrador@hotel.test', '123456')->assertOk();
    }
}
