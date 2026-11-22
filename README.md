# Hotel Consumos API

API REST en Laravel 10 para registrar y cobrar consumos extras de reservas de hotel.
Se apoya en un servidor SOAP externo (proyecto `hotel`) que es la fuente de verdad de
reservas, habitaciones y huespedes.

## Requisitos

- PHP 8.1 con `pdo_mysql` y `soap`
- Composer
- MySQL
- Un servidor web local (Laragon) que publique esta carpeta y la carpeta `hotel`

## Instalacion

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

Ajustar en `.env`:

| Variable | Uso |
|---|---|
| `DB_*` | Conexion MySQL de Laravel |
| `SOAP_SERVICE_URL` | URL base del servidor SOAP |
| `SOAP_DB_DATABASE` | Base del servidor SOAP (`hotel_db`) |
| `SOAP_TIMEOUT` | Timeout de las llamadas SOAP |
| `SANCTUM_EXPIRATION` | Minutos de vigencia del token (480 por defecto) |
| `LOGIN_MAX_ATTEMPTS` | Intentos de login por usuario/correo e IP (5 por defecto) |
| `LOGIN_DECAY_MINUTES` | Minutos de la ventana del throttle (1 por defecto) |

La zona horaria es `America/Bogota` (UTC-5) y esta en `config/app.php`.

`hotel_db.sql` crea la tabla `usuarios` con las columnas `role` y `token_expira_en`,
asi que un entorno nuevo solo necesita importar ese script antes del primer login.

## Endpoints

| Metodo | Ruta | Descripcion |
|---|---|---|
| POST | `/api/login` | Genera el token y lo sincroniza con SOAP. Acepta `usuario` o `email` |
| GET | `/api/me` | Datos del usuario autenticado y su rol |
| POST | `/api/logout` | Revoca el token local y remoto |
| POST | `/api/consumos` | Registra un consumo validando la reserva en SOAP |
| GET | `/api/consumos/reserva/{id_reserva}` | Consumos de la reserva con datos de SOAP |
| POST | `/api/consumos/{id}/pagar` | Marca el consumo como pagado y registra el pago |
| GET | `/api/checkout-status/{id_reserva}` | Saldo pendiente y paz y salvo |

Todas salvo `login` exigen `Authorization: Bearer <token>`.

## Roles

Cada accion de negocio exige un rol distinto:

| Accion | administrador | servicio_habitacion | cajero | recepcionista |
|---|---|---|---|---|
| Registrar consumo | si | si | no | no |
| Consultar estado de cuenta | si | si | no | si |
| Pagar consumo | si | no | si | no |
| Verificar checkout | si | no | si | si |

- Sin token: `401` con `{"mensaje":"No autenticado. Se requiere un token Bearer valido."}`.
- Token valido sin permiso: `403` con `{"error":"Permisos insuficientes para ejecutar este método"}`.
- El middleware lee el rol con `$request->user()->role`, nunca del cuerpo.
- Un usuario sin rol queda bloqueado en las cuatro acciones.
- Pagar dos veces el mismo consumo devuelve `409 Conflict`.

Los usuarios de demostracion se crean con `php artisan db:seed` y comparten la
contrasena `123456`:

| Rol | Usuario | Correo |
|---|---|---|
| administrador | `administrador` | `administrador@hotel.test` |
| servicio_habitacion | `servicio_habitacion` | `servicio@hotel.test` |
| cajero | `cajero` | `cajero@hotel.test` |
| recepcionista | `recepcionista` | `recepcionista@hotel.test` |

En SOAP los tres primeros roles pueden ejecutar solo `consultarReservas`; el
cajero ninguna. `IsAdministrator` existe y esta probado con una ruta en memoria;
el administrador se repite en los cuatro middlewares (variante A1).
El detalle esta en `postman/README.md`.

## Seguridad

- `POST /api/login` admite 5 intentos por minuto por usuario/correo e IP. Al superarlo responde
  `429` con `Retry-After` y `{"mensaje":"Demasiadas solicitudes. Reintente en N segundos."}`.
  Se ajusta con `LOGIN_MAX_ATTEMPTS` y `LOGIN_DECAY_MINUTES`.
- El token vence a los 480 minutos (8 horas). `/api/login` y `/api/me` devuelven `expira`
  en formato ISO 8601. Se ajusta con `SANCTUM_EXPIRATION`.
- El servidor SOAP aplica la misma vigencia con `SOAP_TOKEN_EXPIRATION_MINUTES` (480 por
  defecto). Si cambia una, cambie la otra.
- Todas las respuestas de error usan `mensaje`; el `403` conserva `error` porque es el
  cuerpo exacto exigido.
- En produccion configure `APP_DEBUG=false`.

## Pruebas

```bash
php artisan test
```

Usan la base `hotel_consumos_api_test` definida en `phpunit.xml`.

## Documentacion

- `README.md`: instalacion, uso de la API y permisos.
- `postman/README.md`: uso de la coleccion y del entorno.
