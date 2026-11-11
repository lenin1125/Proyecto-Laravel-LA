<?php

// Declara el espacio de nombres donde vive la clase generadora de tokens.
namespace App\Services;

// Importa la clase RuntimeException para reportar errores de configuracion.
use RuntimeException;

/**
 * Clase TokenGenerator.
 *
 * Su unica responsabilidad es construir un token firmado con HMAC-SHA256.
 * El token resultante tiene la forma: payloadBase64.firma
 *
 * El payload contiene dos marcas de tiempo:
 * - iat: instante en que se creo el token.
 * - exp: instante en que el token deja de ser valido (1 hora despues).
 *
 * La firma se calcula con la clave secreta compartida entre Laravel y el
 * servidor SOAP. De esta forma el servidor puede verificar que el token fue
 * emitido por la API y que no fue alterado en el camino.
 */
class TokenGenerator
{
    /**
     * Genera un token firmado listo para enviarse en la cabecera Authorization.
     *
     * @return string Token con la estructura payloadBase64.firma
     *
     * @throws RuntimeException Cuando la clave secreta no esta configurada.
     */
    public static function generarToken(): string
    {
        // Lee la clave secreta compartida desde la configuracion de Laravel.
        // Se usa config() y no env() para que el valor siga funcionando cuando
        // la aplicacion ejecuta php artisan config:cache.
        $secretKey = (string) config('services.soap.secret_key', '');

        // Verifica que la clave secreta tenga un valor antes de firmar.
        if ($secretKey === '') {
            // Lanza una excepcion clara si falta la configuracion.
            throw new RuntimeException('La clave secreta SOAP no esta configurada.');
        }

        // Obtiene el instante actual expresado en segundos Unix.
        $iat = time();

        // Calcula el instante de expiracion sumando una hora al instante actual.
        $exp = $iat + 3600;

        // Construye el arreglo que viajara dentro del token.
        $payload = [
            // Marca de tiempo de emision del token.
            'iat' => $iat,
            // Marca de tiempo de expiracion del token.
            'exp' => $exp,
        ];

        // Convierte el payload a una cadena JSON compacta.
        // JSON_UNESCAPED_SLASHES evita escapar las barras inclinadas.
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);

        // Comprueba que la conversion a JSON haya sido exitosa.
        if ($payloadJson === false) {
            // Lanza una excepcion si el payload no se pudo serializar.
            throw new RuntimeException('No se pudo codificar el payload del token.');
        }

        // Codifica el JSON en Base64 con variante URL safe.
        $payloadBase64 = self::base64UrlEncode($payloadJson);

        // Calcula la firma HMAC-SHA256 sobre el payload ya codificado.
        // La clave secreta nunca viaja dentro del token.
        $firma = hash_hmac('sha256', $payloadBase64, $secretKey);

        // Devuelve el token final separando payload y firma con un punto.
        return $payloadBase64 . '.' . $firma;
    }

    /**
     * Codifica una cadena en Base64 con alfabeto URL safe.
     *
     * Base64 normal usa los caracteres + y /. La variante URL safe los
     * reemplaza por - y _ para que el token pueda viajar sin problemas en
     * una cabecera HTTP o en una URL.
     * @param string $valor Cadena que se desea codificar.
     * @return string Cadena codificada sin relleno de signos igual.
     */
    public static function base64UrlEncode(string $valor): string
    {
        // Codifica la cadena con el Base64 estandar de PHP.
        $base64 = base64_encode($valor);

        // Reemplaza los caracteres no seguros para URL por sus equivalentes.
        $base64Url = strtr($base64, '+/', '-_');

        // Elimina el relleno de signos igual al final de la cadena.
        return rtrim($base64Url, '=');
    }
}
