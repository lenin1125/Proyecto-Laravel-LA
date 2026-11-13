<?php

// Declara el espacio de nombres de los servicios de la aplicacion.

namespace App\Services;

// Importa PDO para ejecutar las consultas sobre la base de datos del hotel.
use PDO;
// Importa RuntimeException para informar errores de configuracion.
use RuntimeException;

/**
 * Servicio que mantiene sincronizado el usuario de Laravel con la tabla
 * usuarios de la base de datos hotel_db.
 *
 * El servidor SOAP valida el par usuario + token contra esa tabla, por lo
 * que cada vez que Laravel emite un token nuevo se guarda aqui el hash SHA-256 del
 * token. De esta forma SoapUI solo necesita dos datos: el usuario (correo)
 * y el token generado por la API.
 */
class HotelUsuarioSync
{
    /**
     * Crea una conexion PDO contra la base de datos del servidor SOAP.
     *
     * Reutiliza el host, el puerto y las credenciales de la conexion MySQL
     * principal de Laravel y solo cambia el nombre de la base de datos.
     *
     * @return PDO Conexion activa a hotel_db.
     *
     * @throws RuntimeException Cuando la extension PDO no esta disponible.
     */
    protected function conexion(): PDO
    {
        // Comprueba que la extension PDO de MySQL este cargada en PHP.
        if (! extension_loaded('pdo_mysql')) {
            // Detiene el proceso si no existe el driver necesario.
            throw new RuntimeException('La extension pdo_mysql no esta disponible.');
        }

        // Lee la configuracion de la conexion MySQL principal de Laravel.
        $base = (array) config('database.connections.mysql', []);

        // Obtiene el host de la base de datos con un valor por defecto.
        $host = (string) ($base['host'] ?? '127.0.0.1');

        // Obtiene el puerto de la base de datos con un valor por defecto.
        $puerto = (string) ($base['port'] ?? '3306');

        // Obtiene el usuario de MySQL configurado en Laravel.
        $usuario = (string) ($base['username'] ?? 'root');

        // Obtiene la contrasena de MySQL configurada en Laravel.
        $clave = (string) ($base['password'] ?? '');

        // Lee el nombre de la base de datos del servidor SOAP.
        $nombreBd = (string) config('services.soap_db.database', 'hotel_db');

        // Construye el DSN apuntando a la base de datos hotel_db.
        $dsn = "mysql:host={$host};port={$puerto};dbname={$nombreBd};charset=utf8mb4";

        // Crea y devuelve la conexion PDO con los errores como excepciones.
        return new PDO($dsn, $usuario, $clave, [
            // Hace que cualquier error SQL lance una excepcion.
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            // Devuelve los resultados como arreglos asociativos.
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    /**
     * Guarda o actualiza el usuario y el hash SHA-256 de su token en hotel_db.usuarios.
     *
     * @param  string  $usuario  Correo que se usara como nombre de usuario.
     * @param  string  $passwordHash  Hash de la contrasena del usuario.
     * @param  string  $token  Token emitido por Laravel Sanctum.
     * @param  string|null  $role  Rol que el servidor SOAP usara para autorizar.
     */
    public function sincronizar(string $usuario, string $passwordHash, string $token, ?string $role = null): void
    {
        // Abre la conexion contra la base de datos del servidor SOAP.
        $pdo = $this->conexion();
        $token = hash('sha256', $token);

        // El servidor SOAP aplica la misma vigencia que Laravel: la fecha se
        // guarda en UTC para compararla sin ambiguedades de zona horaria.
        $minutos = $this->minutosDeVigencia();
        $expira = $minutos > 0 ? gmdate('Y-m-d H:i:s', time() + $minutos * 60) : null;

        // Busca si el usuario ya existe en la tabla usuarios de hotel_db.
        $consulta = $pdo->prepare('SELECT id FROM usuarios WHERE username = ? LIMIT 1');

        // Ejecuta la busqueda con el correo recibido.
        $consulta->execute([$usuario]);

        // Obtiene el identificador del usuario si ya existe.
        $existente = $consulta->fetch();

        // Comprueba si el usuario ya estaba registrado en hotel_db.
        if ($existente !== false) {
            // Actualiza el hash de la contrasena, el token vigente y su vencimiento.
            $update = $pdo->prepare(
                'UPDATE usuarios SET password = ?, api_token = ?, role = ?, token_expira_en = ? WHERE id = ?'
            );

            // Ejecuta la actualizacion sobre el registro encontrado.
            $update->execute([$passwordHash, $token, $role, $expira, (int) $existente['id']]);

            // Termina la sincronizacion porque el usuario ya existia.
            return;
        }

        // Inserta un usuario nuevo con su token asociado.
        $insert = $pdo->prepare(
            'INSERT INTO usuarios (username, password, api_token, role, token_expira_en, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        );

        // Ejecuta la insercion del usuario en hotel_db.
        $insert->execute([$usuario, $passwordHash, $token, $role, $expira]);
    }

    /**
     * Minutos de vigencia del token emitido por Sanctum.
     *
     * Se lee de config/sanctum.php. El valor 0 desactiva el vencimiento.
     */
    protected function minutosDeVigencia(): int
    {
        // Las pruebas unitarias de este servicio no arrancan la aplicacion.
        $configurado = function_exists('app') && app()->bound('config')
            ? config('sanctum.expiration', 480)
            : 480;

        return max(0, (int) $configurado);
    }

    /**
     * Elimina el token activo del usuario en hotel_db.
     *
     * Se usa al cerrar sesion para que el token deje de ser valido tanto en
     * Laravel como en el servidor SOAP.
     *
     * @param  string  $usuario  Correo del usuario que cierra sesion.
     */
    public function revocar(string $usuario, string $token): void
    {
        $pdo = $this->conexion();
        $update = $pdo->prepare('UPDATE usuarios SET api_token = NULL WHERE username = ? AND api_token = ?');
        $update->execute([$usuario, hash('sha256', $token)]);
    }

    /**
     * Anula cualquier token del usuario en el servidor SOAP.
     *
     * Se usa cuando cambia el rol: el pase viejo deja de ser valido y el
     * usuario debe iniciar sesion de nuevo para recibir el rol actualizado.
     */
    public function revocarTodos(string $usuario): void
    {
        $pdo = $this->conexion();
        $update = $pdo->prepare('UPDATE usuarios SET api_token = NULL WHERE username = ?');
        $update->execute([$usuario]);
    }
}
