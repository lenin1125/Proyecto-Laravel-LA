<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConsumoController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

/*
|--------------------------------------------------------------------------
| Autenticacion
|--------------------------------------------------------------------------
|
| login entrega el token que se usa en Postman y en SoapUI.
| logout revoca ese token en Laravel y en la tabla usuarios de hotel_db.
| El resto de rutas existentes se mantienen sin cambios.
|
*/

// Genera un token nuevo a partir del correo y la contrasena del usuario.
Route::post('/login', [AuthController::class, 'login']);

// Devuelve los datos del usuario autenticado con el token Bearer.
Route::middleware('auth:sanctum')->get('/me', [AuthController::class, 'me']);

// Cierra la sesion y elimina el token actual.
Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout']);

/*
|--------------------------------------------------------------------------
| Consumos protegidos con token
|--------------------------------------------------------------------------
|
| Todas las rutas de consumo exigen el token Bearer emitido por
| POST /api/login. Se conservan los mismos metodos y las mismas URIs.
|
*/
Route::middleware('auth:sanctum')->group(function () {
    // Registra un consumo nuevo despues de validar la reserva en SOAP.
    Route::post('/consumos', [ConsumoController::class, 'store']);

    // Consulta los consumos de una reserva y los datos de la reserva en SOAP.
    Route::get('/consumos/reserva/{id_reserva}', [ConsumoController::class, 'porReserva']);

    // Marca un consumo como pagado y registra el pago.
    Route::post('/consumos/{id}/pagar', [ConsumoController::class, 'pagar']);

    // Indica si la reserva tiene saldo pendiente para el check-out.
    Route::get('/checkout-status/{id_reserva}', [ConsumoController::class, 'checkoutStatus']);
});