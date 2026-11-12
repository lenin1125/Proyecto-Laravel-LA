<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * La aplicacion debe trabajar en la hora local del hotel.
 */
class ZonaHorariaTest extends TestCase
{
    public function test_la_aplicacion_usa_la_zona_horaria_del_hotel(): void
    {
        $this->assertSame('America/Bogota', config('app.timezone'));
        $this->assertSame('-05:00', now()->format('P'));
    }
}
