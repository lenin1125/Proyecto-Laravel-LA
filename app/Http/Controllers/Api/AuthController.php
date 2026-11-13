<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\HotelUsuarioSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

class AuthController extends Controller
{
    public function login(Request $request, HotelUsuarioSync $sync): JsonResponse
    {
        $datos = $request->validate([
            'usuario' => ['required_without:email', 'string', 'max:50'],
            'email' => ['required_without:usuario', 'email', 'max:50'],
            'password' => ['required', 'string'],
            'dispositivo' => ['sometimes', 'string', 'max:50'],
        ]);

        // El enunciado identifica las cuentas por nombre (administrador,
        // cajero, ...). Se acepta tambien el correo para no romper las
        // cuentas anteriores.
        $identificador = $datos['usuario'] ?? $datos['email'];
        $usuario = User::where('username', $identificador)
            ->orWhere('email', $identificador)
            ->first();

        if (! $usuario || ! Hash::check($datos['password'], $usuario->password)) {
            return response()->json(['mensaje' => 'Credenciales invalidas.'], 401);
        }

        $expira = now()->addMinutes($this->minutosDeVigencia());

        try {
            $token = DB::transaction(function () use ($usuario, $datos, $sync, $expira): string {
                $usuario = User::whereKey($usuario->id)->lockForUpdate()->firstOrFail();
                $usuario->tokens()->delete();
                $token = $usuario->createToken(
                    $datos['dispositivo'] ?? 'soapui',
                    ['*'],
                    $expira
                )->plainTextToken;
                $sync->sincronizar($usuario->soapUsername(), $usuario->password, $token, $usuario->role);

                return $token;
            });
        } catch (Throwable) {
            Log::warning('No se pudo sincronizar el login con SOAP.');

            return response()->json(['mensaje' => 'No se pudo sincronizar el token con la base de datos del servidor SOAP.'], 503);
        }

        return response()->json([
            'mensaje' => 'Autenticacion exitosa.',
            'usuario' => [
                'id' => $usuario->id,
                'nombre' => $usuario->name,
                'usuario' => $usuario->username,
                'email' => $usuario->email,
                'rol' => $usuario->role,
            ],
            'token' => $token,
            'token_type' => 'Bearer',
            'soap_username' => $usuario->soapUsername(),
            'soap_token' => $token,
            'expira' => $expira->toIso8601String(),
        ])->header('Cache-Control', 'no-store');
    }

    public function me(Request $request): JsonResponse
    {
        $usuario = $request->user();
        $token = $request->user()->currentAccessToken();
        $expira = null;

        if ($token instanceof PersonalAccessToken && $token->exists) {
            $expira = ($token->expires_at ?? $token->created_at?->copy()->addMinutes($this->minutosDeVigencia()))
                ?->toIso8601String();
        }

        return response()->json([
            'id' => $usuario->id,
            'nombre' => $usuario->name,
            'usuario' => $usuario->username,
            'email' => $usuario->email,
            'rol' => $usuario->role,
            'soap_username' => $usuario->soapUsername(),
            'expira' => $expira,
        ]);
    }

    /**
     * Minutos de vigencia del token, tomados de config/sanctum.php.
     */
    private function minutosDeVigencia(): int
    {
        return max(1, (int) config('sanctum.expiration', 480));
    }

    public function logout(Request $request, HotelUsuarioSync $sync): JsonResponse
    {
        try {
            DB::transaction(function () use ($request, $sync): void {
                $usuario = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                $sync->revocar($usuario->soapUsername(), (string) $request->bearerToken());
                $usuario->tokens()->whereKey($request->user()->currentAccessToken()->getKey())->delete();
            });
        } catch (Throwable) {
            Log::warning('No se pudo sincronizar el logout con SOAP.');

            return response()->json(['mensaje' => 'No se pudo completar el cierre de sesion en SOAP. Reintente.'], 503);
        }

        return response()->json(['mensaje' => 'Sesion cerrada correctamente.']);
    }
}
