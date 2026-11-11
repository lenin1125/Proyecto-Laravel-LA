<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Servicio SOAP del hotel
    |--------------------------------------------------------------------------
    |
    | service_url: URL base del servidor SOAP en PHP nativo.
    | wsdl_url: URL completa del WSDL. Si esta vacia se construye a partir
    |           de service_url agregando el parametro ?wsdl.
    | timeout: tiempo maximo de espera de cada llamada SOAP en segundos.
    |
    | Nota: en este equipo se usa 127.0.0.1 en lugar de localhost porque
    | Docker/WSL intercepta localhost:80 y devuelve 404. Si en otro entorno
    | localhost funciona, basta con cambiar SOAP_SERVICE_URL en el archivo .env.
    |
    */
    'soap' => [
        // URL base del servidor SOAP que expone la base de datos hotel_db.
        'service_url' => env('SOAP_SERVICE_URL', 'http://127.0.0.1/hotel/server.php'),

        // URL opcional del WSDL. Si no se define, SoapService la construye.
        'wsdl_url' => env('SOAP_WSDL_URL'),

        // Tiempo maximo de espera de las llamadas SOAP.
        'timeout' => (int) env('SOAP_TIMEOUT', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Base de datos del servidor SOAP
    |--------------------------------------------------------------------------
    |
    | Nombre de la base de datos donde vive la tabla usuarios del proyecto
    | SOAP. Al iniciar sesion se replica alli el usuario y el token emitido
    | por Laravel, de modo que SoapUI solo necesite usuario y token.
    |
    */
    'soap_db' => [
        // Nombre de la base de datos del proyecto SOAP.
        'database' => env('SOAP_DB_DATABASE', 'hotel_db'),
    ],

];
