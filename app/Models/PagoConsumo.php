<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PagoConsumo extends Model
{
    use HasFactory;

    protected $table = 'pagos_consumos';

    protected $fillable = [
        'consumo_id',
        'metodo_pago',
        'fecha_pago',
    ];

    // Relación inversa con Consumo
    public function consumo()
    {
        return $this->belongsTo(Consumo::class, 'consumo_id');
    }
}