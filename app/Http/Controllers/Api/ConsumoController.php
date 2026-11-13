<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\SoapUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\Consumo;
use App\Models\PagoConsumo;
use App\Services\SoapService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConsumoController extends Controller
{
    protected $soapService;

    public function __construct(SoapService $soapService)
    {
        $this->soapService = $soapService;
    }

    /**
     * 1. POST /api/consumos
     * Registrar un cargo extra validando primero en SOAP
     */
    public function store(Request $request)
    {
        $datos = $request->validate([
            'id_reserva' => ['required', 'string', 'max:50'],
            'concepto'   => ['required', 'string', 'max:255'],
            'monto'      => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
        ]);

        // Validación con el servicio SOAP
        try {
            $reserva = $this->soapService->obtenerReserva($datos['id_reserva']);
        } catch (SoapUnavailableException $e) {
            return response()->json([
                'mensaje' => 'El servicio SOAP no esta disponible'
            ], 503);
        }

        if (!$reserva) {
            return response()->json([
                'mensaje' => 'La reserva especificada no existe en el sistema SOAP'
            ], 404);
        }

        // Validar que la reserva esté Activa
        $estadoReserva = strtolower($reserva->estado ?? '');
        if ($estadoReserva !== 'activo' && $estadoReserva !== 'activa') {
            return response()->json([
                'mensaje' => 'No se pueden cargar consumos a una reserva que no está Activa'
            ], 400);
        }

        // Guardar consumo en MySQL
        $consumo = Consumo::create([
            'id_reserva' => $datos['id_reserva'],
            'concepto'   => $datos['concepto'],
            'monto'      => $datos['monto'],
            'estado'     => 'Pendiente',
        ]);

        return response()->json([
            'mensaje' => 'Consumo registrado exitosamente',
            'consumo' => $consumo
        ], 201);
    }

    /**
     * 2. GET /api/consumos/reserva/{id_reserva}
     * Consultar consumos enriquecidos con datos de SOAP (Habitación y Huésped)
     */
    public function porReserva($id_reserva)
    {
        // Obtener consumos registrados en Laravel
        $consumos = Consumo::where('id_reserva', $id_reserva)->get();

        // Consultar detalle en SOAP para composición de datos
        try {
            $reservaSoap = $this->soapService->obtenerReserva($id_reserva);
        } catch (SoapUnavailableException $e) {
            return response()->json([
                'mensaje' => 'El servicio SOAP no esta disponible'
            ], 503);
        }

        return response()->json([
            'id_reserva'        => $id_reserva,
            'numero_habitacion' => $reservaSoap->numero_habitacion ?? $reservaSoap->habitacion ?? 'N/A',
            'nombre_huesped'    => $reservaSoap->nombre_huesped ?? $reservaSoap->huesped ?? 'N/A',
            'estado_reserva'    => $reservaSoap->estado ?? 'Desconocido',
            'consumos'          => $consumos
        ], 200);
    }

    /**
     * 3. POST /api/consumos/{id}/pagar
     * Saldar un consumo cambiando su estado a 'Pagado'
     */
    public function pagar(Request $request, $id)
    {
        $datos = $request->validate([
            'metodo_pago' => 'required|string|in:Efectivo,Tarjeta,Cargo a la habitación',
        ]);

        // Todo el pago ocurre dentro de una transaccion y con la fila bloqueada.
        $resultado = DB::transaction(function () use ($id, $datos) {
            $consumo = Consumo::whereKey($id)->lockForUpdate()->first();

            if (!$consumo) {
                return [
                    'codigo' => 404,
                    'cuerpo' => ['mensaje' => 'Consumo no encontrado'],
                ];
            }

            if ($consumo->estado === 'Pagado') {
                // Un consumo ya pagado es un conflicto de estado, no una
                // peticion mal formada.
                return [
                    'codigo' => 409,
                    'cuerpo' => ['mensaje' => 'Este consumo ya se encuentra pagado'],
                ];
            }

            // Actualizar estado del consumo
            $consumo->estado = 'Pagado';
            $consumo->save();

            // Registrar el pago en la misma transaccion
            $pago = PagoConsumo::create([
                'consumo_id'  => $consumo->id,
                'metodo_pago' => $datos['metodo_pago'],
            ]);

            return [
                'codigo' => 200,
                'cuerpo' => [
                    'mensaje' => 'Pago realizado con éxito',
                    'consumo' => $consumo,
                    'pago'    => $pago,
                ],
            ];
        });

        return response()->json($resultado['cuerpo'], $resultado['codigo']);
    }

    /**
     * 4. GET /api/checkout-status/{id_reserva}
     * Verificación de saldo pendiente para Check-Out
     */
    public function checkoutStatus($id_reserva)
    {
        $saldoPendiente = Consumo::where('id_reserva', $id_reserva)
            ->where('estado', 'Pendiente')
            ->sum('monto');

        $pazYSalvo = ($saldoPendiente == 0);

        return response()->json([
            'id_reserva'      => $id_reserva,
            'paz_y_salvo'     => $pazYSalvo,
            'saldo_pendiente' => (float)$saldoPendiente
        ], 200);
    }
}