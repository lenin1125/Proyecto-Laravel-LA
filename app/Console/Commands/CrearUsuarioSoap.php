<?php

// Declara el espacio de nombres de los comandos de consola.

namespace App\Console\Commands;

use App\Models\User;
use App\Services\HotelUsuarioSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * Comando que crea o actualiza el usuario compartido entre Laravel y SoapUI.
 *
 * El usuario se guarda en la tabla users de Laravel. El token no se crea
 * aqui: se genera al llamar a POST /api/login, que ademas replica el token
 * en hotel_db.usuarios para que SoapUI lo valide.
 */
class CrearUsuarioSoap extends Command
{
    /**
     * Nombre y argumentos del comando.
     *
     * @var string
     */
    protected $signature = 'usuario:crear
                            {email : Correo electronico del usuario}
                            {password : Contrasena en texto plano}
                            {--username= : Nombre de usuario para el login y SOAP (ej. administrador)}
                            {--name= : Nombre visible del usuario}
                            {--role= : Rol del usuario (administrador, servicio_habitacion, cajero, recepcionista)}';

    /**
     * Descripcion que aparece en php artisan list.
     *
     * @var string
     */
    protected $description = 'Crea o actualiza un usuario de Laravel listo para generar su token';

    /**
     * Ejecuta la creacion o actualizacion del usuario.
     *
     * @return int Codigo de salida del comando.
     */
    public function handle(): int
    {
        // Lee el correo recibido como argumento.
        $email = trim((string) $this->argument('email'));

        // Lee la contrasena recibida como argumento.
        $password = (string) $this->argument('password');

        // Lee el nombre opcional recibido con la opcion --name.
        $nombre = trim((string) $this->option('name'));

        // Comprueba que el correo tenga un formato valido.
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            // Informa el error y termina sin crear el usuario.
            $this->error('El correo no tiene un formato valido.');

            // Devuelve el codigo de fallo del comando.
            return self::FAILURE;
        }

        // Comprueba que la contrasena tenga al menos seis caracteres.
        if (strlen($password) < 6) {
            // Informa el error y termina sin crear el usuario.
            $this->error('La contrasena debe tener al menos 6 caracteres.');

            // Devuelve el codigo de fallo del comando.
            return self::FAILURE;
        }

        // Lee el rol opcional recibido con la opcion --role.
        $rol = trim((string) $this->option('role'));

        // Comprueba que el rol, si se envio, sea uno de los permitidos.
        if ($rol !== '' && ! in_array($rol, User::ROLES, true)) {
            $this->error('El rol no es valido. Valores permitidos: '.implode(', ', User::ROLES));

            return self::FAILURE;
        }

        // Lee el nombre de usuario opcional recibido con --username.
        $username = trim((string) $this->option('username'));

        // Solo admite letras, numeros y guion bajo: es el identificador del
        // login y el nombre que viaja en WS-Security hacia SOAP.
        if ($username !== '' && preg_match('/^[A-Za-z0-9_]{3,50}$/', $username) !== 1) {
            $this->error('El nombre de usuario debe tener entre 3 y 50 caracteres: letras, numeros o guion bajo.');

            return self::FAILURE;
        }

        // Busca el usuario por correo o prepara uno nuevo si no existe.
        $usuario = User::firstOrNew(['email' => $email]);

        // Guarda el correo anterior para revocar el token remoto si cambia.
        $identidadAnterior = $usuario->exists ? $usuario->soapUsername() : null;

        // Guarda el rol anterior para detectar cambios al final.
        $rolAnterior = $usuario->role;

        // Indica si el usuario ya existia antes de ejecutar el comando.
        $yaExistia = $usuario->exists;

        // Actualiza siempre la contrasena con un hash seguro.
        $usuario->password = Hash::make($password);

        // Actualiza el nombre solo cuando se envio la opcion --name.
        if ($nombre !== '') {
            // Guarda el nombre visible indicado por el usuario.
            $usuario->name = $nombre;
        }

        // Actualiza el identificador solo cuando se envio --username.
        if ($username !== '') {
            $usuario->username = $username;
        }

        // Asigna un nombre derivado del correo cuando el usuario es nuevo.
        if (! $usuario->exists && $usuario->name === null) {
            // Toma la parte anterior al arroba como nombre visible.
            $usuario->name = explode('@', $email)[0];
        }

        // Marca el correo como verificado para evitar bloqueos de acceso.
        if ($usuario->email_verified_at === null) {
            // Guarda la fecha actual como fecha de verificacion.
            $usuario->email_verified_at = now();
        }

        // Asigna el rol solo cuando se envio la opcion --role.
        if ($rol !== '') {
            $usuario->role = $rol;
        }

        // Guarda el usuario en la base de datos de Laravel.
        $usuario->save();

        // Si el rol o el nombre de usuario cambiaron, invalida los pases para
        // forzar un login nuevo y limpia la identidad anterior en SOAP.
        $identidadNueva = $usuario->soapUsername();
        $identidadCambio = $identidadAnterior !== null && $identidadAnterior !== $identidadNueva;

        if ($rolAnterior !== $usuario->role || $identidadCambio) {
            $usuario->tokens()->delete();

            try {
                if ($identidadAnterior !== null) {
                    app(HotelUsuarioSync::class)->revocarTodos($identidadAnterior);
                }

                if ($identidadCambio) {
                    app(HotelUsuarioSync::class)->revocarTodos($identidadNueva);
                }
            } catch (Throwable $e) {
                $this->warn('No se pudo revocar el token en SOAP: '.$e->getMessage());
            }

            $this->line('El rol o el usuario cambio: se cerraron las sesiones y se exigira un login nuevo.');
        }

        // Muestra el resultado segun si el usuario ya existia o es nuevo.
        $this->info($yaExistia ? 'Usuario actualizado correctamente.' : 'Usuario creado correctamente.');

        // Muestra el identificador, el correo y la contrasena de acceso.
        $this->line('Usuario: '.$usuario->soapUsername());
        $this->line('Correo: '.$usuario->email);
        $this->line('Contrasena: '.$password);

        // Muestra el rol asignado o advierte que el usuario queda bloqueado.
        $this->line('Rol: '.($usuario->role ?? 'sin rol (bloqueado por los middlewares)'));

        // Explica el siguiente paso para obtener el token.
        $this->line('Siguiente paso: POST /api/login con ese usuario (o correo) y contrasena.');

        // Devuelve el codigo de exito del comando.
        return self::SUCCESS;
    }
}
