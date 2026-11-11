<?php

namespace Tests\Unit;

use App\Services\HotelUsuarioSync;
use PDO;
use PHPUnit\Framework\TestCase;

class HotelUsuarioSyncTest extends TestCase
{
    public function test_solo_guarda_el_hash_y_revoca_solo_el_token_presentado(): void
    {
        if (class_exists(\Pdo\Sqlite::class)) {
            $pdo = new \Pdo\Sqlite('sqlite::memory:');
            $pdo->createFunction('NOW', fn () => '2026-11-05 00:00:00');
        } else {
            $pdo = new PDO('sqlite::memory:');
            $pdo->sqliteCreateFunction('NOW', fn () => '2026-11-05 00:00:00');
        }
        $pdo->exec('CREATE TABLE usuarios (id INTEGER PRIMARY KEY, username TEXT UNIQUE, password TEXT, api_token TEXT, created_at TEXT)');
        $sync = new class($pdo) extends HotelUsuarioSync
        {
            public function __construct(private PDO $pdo) {}

            protected function conexion(): PDO
            {
                return $this->pdo;
            }
        };
        $sync->sincronizar('ana@test.com', 'password-hash', '1|primer-token');
        $this->assertSame(hash('sha256', '1|primer-token'), $pdo->query('SELECT api_token FROM usuarios')->fetchColumn());
        $sync->sincronizar('ana@test.com', 'password-hash', '2|nuevo-token');
        $sync->revocar('ana@test.com', '1|primer-token');
        $this->assertSame(hash('sha256', '2|nuevo-token'), $pdo->query('SELECT api_token FROM usuarios')->fetchColumn());
        $sync->revocar('ana@test.com', '2|nuevo-token');
        $this->assertNull($pdo->query('SELECT api_token FROM usuarios')->fetchColumn());
    }
}
