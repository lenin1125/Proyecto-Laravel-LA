<?php

// Declara el espacio de nombres de las pruebas de funcionalidad.

namespace Tests\Feature;

// Importa el modelo User para crear usuarios de prueba.
use App\Models\User;
// Importa el servicio que se simula para no escribir en hotel_db.
use App\Services\HotelUsuarioSync;
// Importa el trait que reinicia la base de datos de pruebas.
use Illuminate\Foundation\Testing\RefreshDatabase;
// Importa el facade Hash para generar contrasenas validas.
use Illuminate\Support\Facades\Hash;
// Importa Mockery para definir expectativas sobre el servicio simulado.
use Mockery;
// Importa RuntimeException para simular un fallo de sincronizacion.
use RuntimeException;
// Importa la clase base de las pruebas de Laravel.
use Tests\TestCase;

/**
 * Pruebas del flujo de autenticacion con tokens de Laravel Sanctum.
 *
 * La base de datos hotel_db se simula con HotelUsuarioSync para que las
 * pruebas no dependan del servidor SOAP ni modifiquen datos reales.
 */
class AuthApiTest extends TestCase
{
    // Reinicia la base de datos de pruebas antes de cada prueba.
    use RefreshDatabase;

    /**
     * Crea un usuario de prueba con una contrasena conocida.
     *
     * @return User Usuario listo para autenticarse.
     */
    protected function crearUsuario(): User
    {
        // Crea el usuario con el correo y la contrasena del flujo real.
        return User::factory()->create([
            // Correo que se usara en Postman y SoapUI.
            'email' => 'lenin@hotel.test',
            // Nombre visible del usuario.
            'name' => 'Lenin',
            // Contrasena cifrada que coincide con 123456.
            'password' => Hash::make('123456'),
        ]);
    }

    /**
     * Comprueba que el login devuelva el token y sincronice con hotel_db.
     */
    public function test_login_entrega_token_y_sincroniza_con_soap(): void
    {
        // Crea el usuario que se autenticara.
        $usuario = $this->crearUsuario();

        // Simula el servicio de sincronizacion con hotel_db.
        $this->mock(HotelUsuarioSync::class, function ($mock) use ($usuario) {
            // Exige una sola llamada con el correo y el token recien emitido.
            $mock->shouldReceive('sincronizar')
                ->once()
                ->with($usuario->email, Mockery::type('string'), Mockery::type('string'));
        });

        // Envia el login con las credenciales correctas.
        $respuesta = $this->postJson('/api/login', [
            // Correo del usuario creado.
            'email' => 'lenin@hotel.test',
            // Contrasena en texto plano.
            'password' => '123456',
            // Etiqueta del token que se genera.
            'dispositivo' => 'postman',
        ]);

        // Comprueba la respuesta exitosa y su estructura.
        $respuesta->assertOk()
            ->assertJsonStructure([
                'mensaje',
                'usuario' => ['id', 'nombre', 'email'],
                'token',
                'token_type',
                'soap_username',
                'soap_token',
            ]);

        // Comprueba que el usuario de SoapUI sea el correo autenticado.
        $respuesta->assertJsonPath('soap_username', 'lenin@hotel.test');

        // Comprueba que el token de SoapUI sea el mismo token de la API.
        $this->assertSame(
            $respuesta->json('token'),
            $respuesta->json('soap_token')
        );

        // Comprueba que solo exista un token activo en la base de datos.
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    /**
     * Comprueba que una contrasena incorrecta devuelva 401.
     */
    public function test_login_rechaza_contrasena_incorrecta(): void
    {
        // Crea el usuario de prueba.
        $this->crearUsuario();

        // Simula el servicio sin permitir llamadas de sincronizacion.
        $this->mock(HotelUsuarioSync::class, function ($mock) {
            // El login invalido no debe llegar a escribir en hotel_db.
            $mock->shouldNotReceive('sincronizar');
        });

        // Envia el login con una contrasena equivocada.
        $respuesta = $this->postJson('/api/login', [
            // Correo valido del usuario.
            'email' => 'lenin@hotel.test',
            // Contrasena incorrecta.
            'password' => 'incorrecta',
        ]);

        // Comprueba que el acceso sea rechazado.
        $respuesta->assertUnauthorized();

        // Comprueba que no se haya creado ningun token.
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    /**
     * Comprueba que el login valide los campos obligatorios.
     */
    public function test_login_valida_campos_obligatorios(): void
    {
        // Envia una peticion vacia para provocar la validacion.
        $respuesta = $this->postJson('/api/login', []);

        // Comprueba el codigo de validacion de Laravel.
        $respuesta->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    /**
     * Comprueba que el perfil exija un token valido.
     */
    public function test_me_exige_token(): void
    {
        // Llama al perfil sin la cabecera Authorization.
        $respuesta = $this->getJson('/api/me');

        // Comprueba que Sanctum rechace la peticion.
        $respuesta->assertUnauthorized();
    }

    /**
     * Comprueba que el perfil devuelva el usuario autenticado.
     */
    public function test_me_devuelve_usuario_autenticado(): void
    {
        // Crea el usuario de prueba.
        $usuario = $this->crearUsuario();

        // Autentica la peticion con el guard de Sanctum.
        $this->actingAs($usuario, 'sanctum');

        // Solicita el perfil del usuario autenticado.
        $respuesta = $this->getJson('/api/me');

        // Comprueba la respuesta y los datos publicos.
        $respuesta->assertOk()
            ->assertJsonPath('email', 'lenin@hotel.test')
            ->assertJsonPath('soap_username', 'lenin@hotel.test');
    }

    /**
     * Comprueba que un login nuevo revoque los tokens anteriores.
     */
    public function test_login_revoca_tokens_anteriores(): void
    {
        // Crea el usuario de prueba.
        $usuario = $this->crearUsuario();

        // Crea dos tokens que simulan sesiones anteriores.
        $usuario->createToken('anterior-1');
        $usuario->createToken('anterior-2');

        // Simula la sincronizacion con hotel_db.
        $this->mock(HotelUsuarioSync::class, function ($mock) {
            // El login nuevo debe sincronizar una sola vez.
            $mock->shouldReceive('sincronizar')->once();
        });

        // Inicia sesion con las credenciales correctas.
        $respuesta = $this->postJson('/api/login', [
            // Correo del usuario.
            'email' => 'lenin@hotel.test',
            // Contrasena correcta.
            'password' => '123456',
        ]);

        // Comprueba que el login haya sido exitoso.
        $respuesta->assertOk();

        // Comprueba que solo quede el token recien emitido.
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    /**
     * Comprueba que el logout revoque el token en Laravel y en hotel_db.
     */
    public function test_logout_revoca_token_y_limpia_soap(): void
    {
        // Crea el usuario de prueba.
        $usuario = $this->crearUsuario();

        // Genera un token valido para autenticar el logout.
        $token = $usuario->createToken('soapui');

        // Simula la limpieza del token en hotel_db.
        $this->mock(HotelUsuarioSync::class, function ($mock) {
            // El logout debe revocar el token del usuario una sola vez.
            $mock->shouldReceive('revocar')->once();
        });

        // Envia el logout con el token Bearer.
        $respuesta = $this->withToken($token->plainTextToken)
            ->postJson('/api/logout');

        // Comprueba la respuesta exitosa.
        $respuesta->assertOk();

        // Comprueba que el token ya no exista en la base de datos.
        $this->assertDatabaseMissing('personal_access_tokens', [
            // Identificador del token revocado.
            'id' => $token->accessToken->id,
        ]);
    }

    /**
     * Comprueba que el login responda 503 si falla la sincronizacion.
     */
    public function test_login_responde_503_si_falla_la_sincronizacion(): void
    {
        // Crea el usuario de prueba.
        $this->crearUsuario();

        // Simula un fallo de conexion con la base de datos del servidor SOAP.
        $this->mock(HotelUsuarioSync::class, function ($mock) {
            // Hace que la sincronizacion lance una excepcion.
            $mock->shouldReceive('sincronizar')
                ->once()
                ->andThrow(new RuntimeException('hotel_db no disponible'));
        });

        // Intenta iniciar sesion con las credenciales correctas.
        $respuesta = $this->postJson('/api/login', [
            // Correo del usuario.
            'email' => 'lenin@hotel.test',
            // Contrasena correcta.
            'password' => '123456',
        ]);

        // Comprueba que el servicio no este disponible.
        $respuesta->assertStatus(503);

        // Comprueba que el token no quede valido solo en Laravel.
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_logout_fallido_conserva_el_token_para_reintentar(): void
    {
        $usuario = $this->crearUsuario();
        $token = $usuario->createToken('prueba');
        $this->mock(HotelUsuarioSync::class, function ($mock) {
            $mock->shouldReceive('revocar')->once()->andThrow(new RuntimeException('SOAP DB unavailable'));
        });
        $this->withToken($token->plainTextToken)->postJson('/api/logout')->assertStatus(503);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_login_fallido_conserva_el_token_anterior(): void
    {
        $usuario = $this->crearUsuario();
        $anterior = $usuario->createToken('anterior');
        $this->mock(HotelUsuarioSync::class, function ($mock) {
            $mock->shouldReceive('sincronizar')->andThrow(new RuntimeException('SOAP DB unavailable'));
        });
        $this->postJson('/api/login', ['email' => $usuario->email, 'password' => '123456'])->assertStatus(503);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $anterior->accessToken->id]);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }
}
