# Token de Laravel para Postman y SoapUI

Este directorio contiene dos archivos listos para importar en Postman:

- `Hotel API - Token para SoapUI.postman_collection.json`
- `Hotel Local.postman_environment.json`

## 1. Crear el usuario

El usuario compartido entre Laravel y SoapUI se puede crear con el comando:

```bash
php artisan usuario:crear lenin@hotel.test 123456 --name=Lenin
```

Credenciales usadas por la coleccion:

| Campo | Valor |
|---|---|
| Correo | `lenin@hotel.test` |
| Contrasena | `123456` |

El comando es idempotente: si el usuario ya existe, actualiza su contrasena.

## 2. Importar en Postman

1. Abre Postman.
2. Importa la coleccion y el entorno.
3. Selecciona el entorno `Hotel Local` en la esquina superior derecha.
4. Si tu API no responde en `http://hotel-consumos-api.test`, cambia la variable `base_url`.
5. Ejecuta `1. Autenticacion -> Login - genera token`.

El script de pruebas guarda el token en la variable de entorno `soap_token` y el
usuario en `soap_username`. En la consola de Postman veras ambos valores.

Despues del login puedes ejecutar la carpeta `2. API REST - Consumos`. Todas las
peticiones de esa carpeta heredan la cabecera `Authorization: Bearer {{soap_token}}`.

La peticion `Sin token - debe responder 401` sirve para comprobar que la API ya no
permite consultar consumos sin autenticacion.

## 3. Usar el token en SoapUI

1. Crea un proyecto SOAP con el WSDL:
   `http://127.0.0.1/hotel/server.php?wsdl`
2. Abre la peticion `consultarReservas`.
3. En la pestana `WS-Security Configurations` agrega una configuracion:
   - Tipo: `Username`
   - Username: `lenin@hotel.test`
   - Password: el token que genero Postman
   - Password type: `PasswordText`
4. Asocia esa configuracion a la peticion y ejecutala.

SoapUI solo necesita el usuario y el token. No envia la contrasena.

## 4. Como funciona

1. `POST /api/login` valida el correo y la contrasena en Laravel.
2. Laravel elimina los tokens anteriores y emite un token Sanctum nuevo.
3. El usuario y el hash SHA-256 del token completo se copian a `hotel_db.usuarios`.
4. `server.php` lee el `UsernameToken`, busca al usuario y compara el hash del token recibido.
5. Si el par no coincide, responde `401 Unauthorized` con un SOAP Fault.

El mismo token sirve para:

- `Authorization: Bearer {token}` en las rutas de la API.
- `wsse:Username` + `wsse:Password` en el servidor SOAP.

## 5. Rutas protegidas

Todas estas rutas exigen token Bearer:

| Metodo | Ruta |
|---|---|
| `POST` | `/api/consumos` |
| `GET` | `/api/consumos/reserva/{id_reserva}` |
| `POST` | `/api/consumos/{id}/pagar` |
| `GET` | `/api/checkout-status/{id_reserva}` |
| `GET` | `/api/me` |
| `POST` | `/api/logout` |

`POST /api/login` es la unica ruta publica.

## 6. Notas

- Cada login revoca el token anterior. Si vuelves a iniciar sesion, copia el
  token nuevo en SoapUI; el anterior deja de funcionar en ambas aplicaciones.
- `POST /api/logout` revoca el token en Laravel y en `hotel_db`.
- Laravel reenvia el mismo token de la peticion REST a SOAP en WS-Security.
- Se retiraron HMAC, sus claves compartidas y la operacion `login` del WSDL SOAP.
  El login se realiza exclusivamente en Laravel.
- Los tokens antiguos almacenados en texto plano ya no se aceptan: vuelve a
  iniciar sesion en Laravel para guardar su hash y recarga el WSDL en SoapUI.
- Si SOAP no puede revocar el token, logout devuelve `503` y mantiene el token
  local para permitir reintentar; no se anuncia un cierre exitoso.
- Si falla la sincronizacion durante login, la transaccion local conserva el
  token anterior. No existe una transaccion distribuida entre ambas bases:
  un fallo de commit local posterior a una escritura remota requiere reintentar
  el login para recuperar la sincronizacion.
- `cliente.php` usa `SOAP_USERNAME` y `SOAP_TOKEN` del entorno, emitidos por Laravel.
- La columna `usuarios.api_token` almacena un hash hexadecimal de 64 caracteres;
  su longitud actual de 80 admite el cambio sin migracion de esquema.
- Las pruebas automatizadas usan la base de datos `hotel_consumos_api_test` y
  simulan la sincronizacion con `hotel_db`, por lo que no modifican datos reales.
