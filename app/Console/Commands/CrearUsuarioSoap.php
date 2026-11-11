<?php

// Declara el espacio de nombres de los comandos de consola.
namespace App\Console\Commands;

// Importa el modelo User para crear o actualizar el usuario.
use App\Models\User;

// Importa el comando base de Laravel.
use Illuminate\Console\Command;

// Importa el facade Hash para cifrar la contrasena.
use Illuminate\Support\Facades\Hash;

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
                            {--name= : Nombre visible del usuario}';

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

        // Busca el usuario por correo o prepara uno nuevo si no existe.
        $usuario = User::firstOrNew(['email' => $email]);

        // Indica si el usuario ya existia antes de ejecutar el comando.
        $yaExistia = $usuario->exists;

        // Actualiza siempre la contrasena con un hash seguro.
        $usuario->password = Hash::make($password);

        // Actualiza el nombre solo cuando se envio la opcion --name.
        if ($nombre !== '') {
            // Guarda el nombre visible indicado por el usuario.
            $usuario->name = $nombre;
        }

        // Asigna un nombre derivado del correo cuando el usuario es nuevo.
        if (!$usuario->exists && $usuario->name === null) {
            // Toma la parte anterior al arroba como nombre visible.
            $usuario->name = explode('@', $email)[0];
        }

        // Marca el correo como verificado para evitar bloqueos de acceso.
        if ($usuario->email_verified_at === null) {
            // Guarda la fecha actual como fecha de verificacion.
            $usuario->email_verified_at = now();
        }

        // Guarda el usuario en la base de datos de Laravel.
        $usuario->save();

        // Muestra el resultado segun si el usuario ya existia o es nuevo.
        $this->info($yaExistia ? 'Usuario actualizado correctamente.' : 'Usuario creado correctamente.');

        // Muestra el correo y la contrasena que se usaran en Postman.
        $this->line('Correo: ' . $usuario->email);
        $this->line('Contrasena: ' . $password);

        // Explica el siguiente paso para obtener el token.
        $this->line('Siguiente paso: POST /api/login con ese correo y contrasena.');

        // Devuelve el codigo de exito del comando.
        return self::SUCCESS;
    }
}
