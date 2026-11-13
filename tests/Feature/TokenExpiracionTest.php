<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\HotelUsuarioSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Pruebas de la vigencia del token emitido por el login.
 */
class TokenExpiracionTest extends TestCase
{
    use RefreshDatabase;

    private function crearUsuario(): User
    {
        return User::factory()->create([
            'email' => 'administrador@hotel.test',
            'password' => Hash::make('123456'),
        ]);
    }

    public function test_el_login_anuncia_el_vencimiento_y_el_token_deja_de_servir(): void
    {
        $this->freezeTime();
        $this->crearUsuario();
        $this->mock(HotelUsuarioSync::class, function ($mock) {
            $mock->shouldReceive('sincronizar')->once();
        });

        $login = $this->postJson('/api/login', [
            'email' => 'administrador@hotel.test',
            'password' => '123456',
        ])->assertOk();

        $token = $login->json('token');
        $expira = now()->addMinutes(480)->toIso8601String();

        $login->assertJsonPath('expira', $expira);

        $this->withToken($token)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('expira', $expira);

        // Un minuto despues del vencimiento el token ya no autentica.
        $this->travel(481)->minutes();
        auth()->forgetGuards();

        $this->withToken($token)->getJson('/api/me')
            ->assertUnauthorized()
            ->assertJsonPath('mensaje', 'No autenticado. Se requiere un token Bearer valido.');
    }

    public function test_el_token_vigente_se_guarda_con_su_fecha_de_vencimiento(): void
    {
        $this->freezeTime();
        $this->crearUsuario();
        $this->mock(HotelUsuarioSync::class, function ($mock) {
            $mock->shouldReceive('sincronizar')->once();
        });

        $this->postJson('/api/login', [
            'email' => 'administrador@hotel.test',
            'password' => '123456',
        ])->assertOk();

        $registro = DB::table('personal_access_tokens')->first();

        $this->assertNotNull($registro->expires_at);
        $this->assertSame(
            now()->addMinutes(480)->format('Y-m-d H:i:s'),
            Carbon::parse($registro->expires_at)->format('Y-m-d H:i:s')
        );
    }
}
