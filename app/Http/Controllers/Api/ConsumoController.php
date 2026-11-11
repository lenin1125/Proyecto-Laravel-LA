<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consumo;
use App\Models\PagoConsumo;
use App\Services\SoapService;
use Illuminate\Http\Request;

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
        $request->validate([
            'id_reserva' => 'required',
            'concepto'   => 'required|string|max:255',
            'monto'      => 'required|numeric|min:0.01',
        ]);

        // Validación con el servicio SOAP
        $reserva = $this->soapService->obtenerReserva($request->id_reserva);

        if (!$reserva) {
            return response()->json([
                'error' => 'La reserva especificada no existe en el sistema SOAP'
            ], 404);
        }

        // Validar que la reserva esté Activa
        $estadoReserva = strtolower($reserva->estado ?? '');
        if ($estadoReserva !== 'activo' && $estadoReserva !== 'activa') {
            return response()->json([
                'error' => 'No se pueden cargar consumos a una reserva que no está Activa'
            ], 400);
        }

        // Guardar consumo en MySQL
        $consumo = Consumo::create([
            'id_reserva' => $request->id_reserva,
            'concepto'   => $request->concepto,
            'monto'      => $request->monto,
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
        $reservaSoap = $this->soapService->obtenerReserva($id_reserva);

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
        $request->validate([
            'metodo_pago' => 'required|string|in:Efectivo,Tarjeta,Cargo a la habitación',
        ]);

        $consumo = Consumo::find($id);

        if (!$consumo) {
            return response()->json(['error' => 'Consumo no encontrado'], 404);
        }

        if ($consumo->estado === 'Pagado') {
            return response()->json(['mensaje' => 'Este consumo ya se encuentra pagado'], 400);
        }

        // Actualizar estado del consumo
        $consumo->estado = 'Pagado';
        $consumo->save();

        // Registrar el pago
        $pago = PagoConsumo::create([
            'consumo_id'  => $consumo->id,
            'metodo_pago' => $request->metodo_pago,
        ]);

        return response()->json([
            'mensaje' => 'Pago realizado con éxito',
            'consumo' => $consumo,
            'pago'    => $pago
        ], 200);
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