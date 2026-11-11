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
use Throwable;

class AuthController extends Controller
{
    public function login(Request $request, HotelUsuarioSync $sync): JsonResponse
    {
        $datos = $request->validate([
            'email' => ['required', 'email', 'max:50'],
            'password' => ['required', 'string'],
            'dispositivo' => ['sometimes', 'string', 'max:50'],
        ]);
        $usuario = User::where('email', $datos['email'])->first();
        if (! $usuario || ! Hash::check($datos['password'], $usuario->password)) {
            return response()->json(['mensaje' => 'Credenciales invalidas.'], 401);
        }

        try {
            $token = DB::transaction(function () use ($usuario, $datos, $sync): string {
                $usuario = User::whereKey($usuario->id)->lockForUpdate()->firstOrFail();
                $usuario->tokens()->delete();
                $token = $usuario->createToken($datos['dispositivo'] ?? 'soapui')->plainTextToken;
                $sync->sincronizar($usuario->email, $usuario->password, $token);

                return $token;
            });
        } catch (Throwable) {
            Log::warning('No se pudo sincronizar el login con SOAP.');

            return response()->json(['mensaje' => 'No se pudo sincronizar el token con la base de datos del servidor SOAP.'], 503);
        }

        return response()->json([
            'mensaje' => 'Autenticacion exitosa.',
            'usuario' => ['id' => $usuario->id, 'nombre' => $usuario->name, 'email' => $usuario->email],
            'token' => $token,
            'token_type' => 'Bearer',
            'soap_username' => $usuario->email,
            'soap_token' => $token,
            'expira' => null,
        ])->header('Cache-Control', 'no-store');
    }

    public function me(Request $request): JsonResponse
    {
        $usuario = $request->user();

        return response()->json(['id' => $usuario->id, 'nombre' => $usuario->name, 'email' => $usuario->email, 'soap_username' => $usuario->email]);
    }

    public function logout(Request $request, HotelUsuarioSync $sync): JsonResponse
    {
        try {
            DB::transaction(function () use ($request, $sync): void {
                $usuario = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                $sync->revocar($usuario->email, (string) $request->bearerToken());
                $usuario->tokens()->whereKey($request->user()->currentAccessToken()->getKey())->delete();
            });
        } catch (Throwable) {
            Log::warning('No se pudo sincronizar el logout con SOAP.');

            return response()->json(['mensaje' => 'No se pudo completar el cierre de sesion en SOAP. Reintente.'], 503);
        }

        return response()->json(['mensaje' => 'Sesion cerrada correctamente.']);
    }
}
