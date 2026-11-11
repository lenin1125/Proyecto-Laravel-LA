<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\HotelUsuarioSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PDO;
use Tests\TestCase;

class SoapTokenTest extends TestCase
{
    use RefreshDatabase;

    private PDO $soapDb;

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 3).'/hotel/auth.php';
        $GLOBALS['soap_cuerpo_solicitud'] = '';
        $this->soapDb = new PDO('sqlite::memory:');
        $this->soapDb->exec('CREATE TABLE usuarios (username TEXT PRIMARY KEY, api_token TEXT, role TEXT)');
    }

    private function xml(string $usuario, string $token): string
    {
        return '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" xmlns:w="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd"><s:Header><w:Security><w:UsernameToken><w:Username>'
            .htmlspecialchars($usuario, ENT_QUOTES | ENT_XML1).'</w:Username><w:Password>'
            .htmlspecialchars($token, ENT_QUOTES | ENT_XML1).'</w:Password></w:UsernameToken></w:Security></s:Header><s:Body/></s:Envelope>';
    }

    public function test_el_mismo_token_de_login_funciona_en_api_y_validador_soap(): void
    {
        User::factory()->create(['email' => 'ana@test.com', 'password' => Hash::make('password')]);
        $this->mock(HotelUsuarioSync::class, function ($mock) {
            $mock->shouldReceive('sincronizar')->twice()->andReturnUsing(function ($email, $password, $token): void {
                $this->soapDb->prepare('INSERT OR REPLACE INTO usuarios (username, api_token) VALUES (?, ?)')->execute([$email, hash('sha256', $token)]);
            });
            $mock->shouldReceive('revocar')->once()->andReturnUsing(function ($email, $token): void {
                $this->soapDb->prepare('UPDATE usuarios SET api_token = NULL WHERE username = ? AND api_token = ?')->execute([$email, hash('sha256', $token)]);
            });
        });
        $login = fn () => $this->postJson('/api/login', ['email' => 'ana@test.com', 'password' => 'password'])->assertOk()->json('token');
        $token = $login();
        $credentials = obtenerCredencialesWsSecurity($this->xml('ana@test.com', $token));
        $this->assertSame($token, $credentials['token']);
        $this->assertTrue(validarUsuarioTokenBD($credentials['username'], $credentials['token'], $this->soapDb));
        $this->assertFalse(validarUsuarioTokenBD('otro@test.com', $token, $this->soapDb));
        $this->assertFalse(validarUsuarioTokenBD('ana@test.com', $token.'x', $this->soapDb));
        $this->withToken($token)->getJson('/api/me')->assertOk();
        auth()->forgetGuards();
        $nuevo = $this->withHeaders(['Authorization' => ''])->postJson('/api/login', ['email' => 'ana@test.com', 'password' => 'password'])->assertOk()->json('token');
        $this->assertFalse(validarUsuarioTokenBD('ana@test.com', $token, $this->soapDb));
        auth()->forgetGuards();
        $nuevoHash = $this->soapDb->query('SELECT api_token FROM usuarios')->fetchColumn();
        $this->assertNotSame(hash('sha256', $token), $nuevoHash);
        $this->withToken($nuevo)->postJson('/api/logout')->assertOk();
        $this->assertFalse(validarUsuarioTokenBD('ana@test.com', $nuevo, $this->soapDb));
        auth()->forgetGuards();
        $this->withToken($nuevo)->getJson('/api/me')->assertUnauthorized();
    }

    /**
     * El token correcto no basta: el rol decide que operacion SOAP se ejecuta.
     */
    public function test_el_rol_limita_las_operaciones_soap(): void
    {
        // Cada fila es un usuario valido con su rol.
        $filas = [
            ['admin@test.com', 'administrador'],
            ['servicio@test.com', 'servicio_habitacion'],
            ['cajero@test.com', 'cajero'],
            ['recepcion@test.com', 'recepcionista'],
            ['sinrol@test.com', null],
        ];

        $token = '1|tokenDePrueba';

        // Guarda el token y el rol como los dejaria un login.
        $stmt = $this->soapDb->prepare('INSERT INTO usuarios (username, api_token, role) VALUES (?, ?, ?)');
        foreach ($filas as [$email, $rol]) {
            $stmt->execute([$email, hash('sha256', $token), $rol]);
        }

        // Prepara el sobre SOAP que auth.php lee desde el global.
        $autenticado = fn (string $email): string => $this->xml($email, $token);

        // El administrador puede las seis operaciones.
        $GLOBALS['soap_cuerpo_solicitud'] = $autenticado('admin@test.com');
        foreach (operacionesSOAPConocidas() as $operacion) {
            $this->assertTrue(
                autorizarOperacionSOAP($operacion, $this->soapDb),
                "El administrador deberia poder ejecutar {$operacion}."
            );
        }

        // Servicio de habitacion solo consulta reservas.
        $GLOBALS['soap_cuerpo_solicitud'] = $autenticado('servicio@test.com');
        $this->assertTrue(autorizarOperacionSOAP('consultarReservas', $this->soapDb));
        foreach (['registrarHabitacion', 'consultarHabitacion', 'listarHabitaciones', 'actualizarHabitacion', 'registrarReserva'] as $operacion) {
            $this->assertFalse(autorizarOperacionSOAP($operacion, $this->soapDb));
        }

        // Recepcion consulta habitaciones y reservas, no modifica nada.
        $GLOBALS['soap_cuerpo_solicitud'] = $autenticado('recepcion@test.com');
        foreach (['consultarHabitacion', 'listarHabitaciones', 'consultarReservas'] as $operacion) {
            $this->assertTrue(autorizarOperacionSOAP($operacion, $this->soapDb));
        }
        foreach (['registrarHabitacion', 'actualizarHabitacion', 'registrarReserva'] as $operacion) {
            $this->assertFalse(autorizarOperacionSOAP($operacion, $this->soapDb));
        }

        // El cajero no tiene ninguna operacion SOAP.
        $GLOBALS['soap_cuerpo_solicitud'] = $autenticado('cajero@test.com');
        foreach (operacionesSOAPConocidas() as $operacion) {
            $this->assertFalse(autorizarOperacionSOAP($operacion, $this->soapDb));
        }

        // Sin rol tampoco entra.
        $GLOBALS['soap_cuerpo_solicitud'] = $autenticado('sinrol@test.com');
        $this->assertFalse(autorizarOperacionSOAP('consultarReservas', $this->soapDb));

        // Un usuario sin token valido no obtiene rol ni permiso.
        $GLOBALS['soap_cuerpo_solicitud'] = $this->xml('admin@test.com', '1|token-invalido');
        $this->assertFalse(autorizarOperacionSOAP('consultarReservas', $this->soapDb));

        // Una operacion desconocida se niega por defecto, incluso al admin.
        $GLOBALS['soap_cuerpo_solicitud'] = $autenticado('admin@test.com');
        $this->assertFalse(autorizarOperacionSOAP('borrarTodo', $this->soapDb));

        $GLOBALS['soap_cuerpo_solicitud'] = '';
    }

    public function test_detecta_la_operacion_del_sobre_soap(): void
    {
        $sobre = fn (string $cuerpo): string => '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:urn="urn:HotelService">'
            .'<soapenv:Body>'.$cuerpo.'</soapenv:Body></soapenv:Envelope>';

        $this->assertSame('consultarReservas', obtenerOperacionSOAP($sobre('<urn:consultarReservas/>')));
        $this->assertSame('registrarHabitacion', obtenerOperacionSOAP($sobre('<urn:registrarHabitacion><numero>10</numero></urn:registrarHabitacion>')));
        $this->assertSame('listarHabitaciones', obtenerOperacionSOAP($sobre('<listarHabitaciones xmlns="urn:HotelService"/>')));
        $this->assertNull(obtenerOperacionSOAP(''));
        $this->assertNull(obtenerOperacionSOAP('<roto'));
        $this->assertNull(obtenerOperacionSOAP($sobre('')));
    }

    public function test_no_acepta_token_en_body_ni_password_digest_ni_xml_invalido(): void
    {
        $xml = $this->xml('ana@test.com', '1|token');
        $invalidos = ['', '<invalid', strtr($xml, ['s:Header' => 's:Body', 's:Body' => 's:Header']), str_replace('<w:Password>', '<w:Password Type="PasswordDigest">', $xml)];
        foreach ($invalidos as $invalido) {
            $this->assertNull(obtenerCredencialesWsSecurity($invalido)['token']);
        }
    }
}
