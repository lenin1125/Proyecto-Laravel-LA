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
        $this->soapDb = new PDO('sqlite::memory:');
        $this->soapDb->exec('CREATE TABLE usuarios (username TEXT PRIMARY KEY, api_token TEXT)');
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
                $this->soapDb->prepare('INSERT OR REPLACE INTO usuarios VALUES (?, ?)')->execute([$email, hash('sha256', $token)]);
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

    public function test_no_acepta_token_en_body_ni_password_digest_ni_xml_invalido(): void
    {
        $xml = $this->xml('ana@test.com', '1|token');
        $invalidos = ['', '<invalid', strtr($xml, ['s:Header' => 's:Body', 's:Body' => 's:Header']), str_replace('<w:Password>', '<w:Password Type="PasswordDigest">', $xml)];
        foreach ($invalidos as $invalido) {
            $this->assertNull(obtenerCredencialesWsSecurity($invalido)['token']);
        }
    }
}
