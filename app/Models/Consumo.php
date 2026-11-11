<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Consumo extends Model
{
    use HasFactory;

    protected $table = 'consumos';

    protected $fillable = [
        'id_reserva',
        'concepto',
        'monto',
        'estado',
        'fecha_registro',
    ];

    /**
     * Mantiene el monto como numero en las respuestas JSON.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'monto' => 'float',
    ];

    // Relación uno a muchos con Pagos
    public function pagos()
    {
        return $this->hasMany(PagoConsumo::class, 'consumo_id');
    }
}