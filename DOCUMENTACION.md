# Documentación del proyecto `hotel-consumos-api`

> Revisión inicial: 09/11/2026 (solo lectura).
> Actualización: 11/11/2026. Se implementaron roles, middlewares de permisos y autorización SOAP por rol.
> Los puntos marcados como **corregido** ya están en el código y cubiertos por pruebas.

---

## 1. Qué es

API REST en **Laravel 10 / PHP 8.1** para registrar y cobrar consumos extras de reservas de hotel. Se apoya en dos sistemas externos que viven fuera de este proyecto:

| Sistema | Ubicación | Rol |
|---|---|---|
| API Laravel | `C:\laragon\www\hotel-consumos-api` | Autenticación, consumos, pagos y checkout |
| Servidor SOAP | `C:\laragon\www\hotel` (`server.php`) | Fuente de verdad de reservas, habitaciones y huéspedes |
| BD Laravel | MySQL `hotel_consumos_api` | Usuarios, tokens, consumos y pagos |
| BD SOAP | MySQL `hotel_db` | `habitacion`, `huesped`, `reserva`, `usuarios` |

**Stack:** Laravel 10, Sanctum 3, Guzzle, MySQL, PHPUnit 10 y NuSOAP del lado externo.

**Estado verificado localmente:**

- PHP 8.1.10 con `pdo_mysql` y `soap` cargados.
- 7 rutas registradas en `/api` (incluye login, logout, me y las cuatro acciones de negocio).
- 7 migraciones ejecutadas (la última agrega `users.role`).
- `hotel_consumos_api`: 7 tablas.
- `hotel_db`: 4 tablas (`habitacion`, `huesped`, `reserva`, `usuarios`).
- Git inicializado en los dos proyectos; etiqueta base `linea-base` y commits por bloque.
- 43 pruebas automatizadas en verde (176 aserciones).
- Zona horaria `America/Bogota` (UTC-5), igual que el sistema y MySQL.

---

## 2. Estructura y responsabilidades

```text
app/
├── Console/
│   ├── Commands/CrearUsuarioSoap.php   Comando usuario:crear (acepta --role)
│   └── Kernel.php                      Registro de comandos y schedule vacío
├── Exceptions/
│   ├── Handler.php                     Handler estándar de Laravel
│   └── SoapUnavailableException.php    Distingue SOAP caído (503) de 404
├── Http/
│   ├── Controllers/Api/
│   │   ├── AuthController.php          Login, perfil y logout
│   │   └── ConsumoController.php       Consumos, pagos y checkout
│   ├── Middleware/
│   │   ├── AutorizaRoles.php           Trait con el 403 y el mensaje exacto
│   │   ├── IsAdministrator.php         Solo administrador
│   │   ├── CanRegisterConsumptions.php administrador + servicio_habitacion
│   │   ├── CanConsultAccount.php       administrador + servicio + recepcionista
│   │   ├── CanProcessPayments.php      administrador + cajero
│   │   ├── CanCheckOut.php             administrador + cajero + recepcionista
│   │   └── ...                         Scaffold de Laravel
│   └── Kernel.php                      Middleware global/api/web y alias de rol
├── Models/
│   ├── User.php                        Usuario + Sanctum + constantes de rol
│   ├── Consumo.php                     Tabla consumos
│   └── PagoConsumo.php                 Tabla pagos_consumos
├── Providers/                          Providers estándar
└── Services/
    ├── SoapService.php                 Cliente SOAP con WS-Security
    └── HotelUsuarioSync.php            Sincroniza usuario, token y rol a hotel_db

routes/
├── api.php                             Rutas funcionales con middleware de rol
├── web.php                             Solo la página de bienvenida de Laravel
└── console.php                         Comando de ejemplo inspire

database/
├── migrations/                         7 migraciones
├── seeders/                            DatabaseSeeder + UsuarioRolSeeder
└── factories/UserFactory.php           Estados por rol y sinRol()

tests/
├── Feature/                            Auth, consumos, roles y SOAP
└── Unit/                               Sync y test de ejemplo

postman/                                Dos colecciones y un entorno compartido
config/services.php                     Configuración SOAP y soap_db
```

---

## 3. Flujo de autenticación

Archivo principal: `app/Http/Controllers/Api/AuthController.php`.

### 3.1 `POST /api/login`

1. Valida `email` (máx. 50), `password` (obligatorio) y `dispositivo` (opcional, máx. 50).
2. Busca el usuario en `users` y compara con `Hash::check`.
3. Abre una transacción local:
   - bloquea la fila del usuario (`lockForUpdate`);
   - **elimina todos los tokens anteriores** (`tokens()->delete()`);
   - crea un token Sanctum nuevo con nombre `dispositivo` o `"soapui"`;
   - llama a `HotelUsuarioSync::sincronizar(email, password_hash, token_plano, role)`.
4. `HotelUsuarioSync` abre una conexión **PDO directa** a `hotel_db` reutilizando host, puerto, usuario y clave de la conexión MySQL de Laravel:
   - si el usuario existe en `hotel_db.usuarios`, actualiza `password`, `api_token` y `role`;
   - si no existe, lo inserta;
   - guarda **SHA-256 del token**, no el token plano.
5. Devuelve:

```json
{
  "mensaje": "Autenticacion exitosa.",
  "usuario": { "id": 1, "nombre": "Administrador", "email": "administrador@hotel.test", "rol": "administrador" },
  "token": "...",
  "token_type": "Bearer",
  "soap_username": "administrador@hotel.test",
  "soap_token": "...",
  "expira": null
}
```

6. Si falla cualquier cosa de la sincronización → `503` y se revierte la transacción local, conservando el token anterior.

> **Consecuencia:** solo hay una sesión activa por usuario. Cada login invalida el token anterior en Laravel **y** en `hotel_db`, por lo que SoapUI debe actualizarse tras cada login.

### 3.2 `GET /api/me`

Devuelve `id`, `nombre`, `email`, `rol` y `soap_username` del usuario autenticado. `rol` puede ser `null` si la cuenta no tiene rol asignado.

### 3.3 `POST /api/logout`

1. Transacción local: bloquea usuario y llama a `HotelUsuarioSync::revocar(email, bearer_token)`.
2. `revocar()` ejecuta `UPDATE usuarios SET api_token = NULL WHERE username = ? AND api_token = SHA256(token)`.
3. Elimina el token actual de `personal_access_tokens`.
4. Si falla el paso remoto → `503` y **conserva** el token local para reintentar.

### 3.4 Roles y permisos (REST)

Los roles se guardan en `users.role` (varchar 30, nullable). Las constantes viven en `App\Models\User`:

| Constante | Valor |
|---|---|
| `ROLE_ADMIN` | `administrador` |
| `ROLE_SERVICIO` | `servicio_habitacion` |
| `ROLE_CAJERO` | `cajero` |
| `ROLE_RECEPCION` | `recepcionista` |

Cada acción de negocio tiene su middleware, registrado en `app/Http/Kernel.php` y aplicado en `routes/api.php`:

| Acción | Ruta | Middleware | Roles permitidos |
|---|---|---|---|
| Registrar consumo | `POST /api/consumos` | `CanRegisterConsumptions` | administrador, servicio_habitacion |
| Consultar estado de cuenta | `GET /api/consumos/reserva/{id}` | `CanConsultAccount` | administrador, servicio_habitacion, recepcionista |
| Pagar consumo | `POST /api/consumos/{id}/pagar` | `CanProcessPayments` | administrador, cajero |
| Verificar checkout | `GET /api/checkout-status/{id}` | `CanCheckOut` | administrador, cajero, recepcionista |

Reglas:

- El rol se lee del usuario autenticado en base de datos; **nunca** del cuerpo de la petición.
- `auth:sanctum` corre antes del middleware de rol, así que un token ausente o inválido devuelve `401` y no `403`.
- Un rol `null` o desconocido recibe `403`.
- Cuerpo exacto del `403`:

```json
{ "error": "Permisos insuficientes para ejecutar este método" }
```

- `CanRegisterConsumptions` y el resto repiten `administrador` de forma explícita (variante A1).
- `IsAdministrator` existe y está registrado, aunque hoy ninguna ruta lo usa en exclusiva.
- Cambiar el rol con `usuario:crear --role` elimina los tokens de Sanctum y anula el `api_token` en `hotel_db`, obligando a un login nuevo.

---

## 4. Integración SOAP

Archivo: `app/Services/SoapService.php`.

- Construye la URL WSDL desde `SOAP_SERVICE_URL` + `?wsdl` (o usa `SOAP_WSDL_URL`).
- Crea un `SoapClient` con timeout y `WSDL_CACHE_NONE`.
- Agrega cabecera **WS-Security UsernameToken**:
  - `Username` = email del usuario autenticado;
  - `Password` = token Sanctum **en texto plano** (PasswordText).
- `obtenerReserva($id)` llama a `consultarReservas()` **sin parámetros**, parsea la respuesta (JSON, array u objeto), normaliza a lista y filtra en PHP por `id`.
- Cualquier excepción se captura, se registra como warning y devuelve `null`.

El servidor externo (`C:\laragon\www\hotel\server.php` + `auth.php`) valida el par usuario/token contra `hotel_db.usuarios` y compara el SHA-256 recibido.

### 4.1 Autorización de operaciones SOAP por rol

`auth.php` incorpora tres funciones:

- `obtenerRolUsuarioSOAP($username, $pdo = null)`: lee `usuarios.role`.
- `operacionesPermitidasPorRol()`: mapa cerrado rol → operaciones.
- `autorizarOperacionSOAP($operacion, $pdo = null)`: valida credenciales, rol y operación.

Mapa vigente:

| Rol | Operaciones permitidas |
|---|---|
| administrador | las 6 |
| servicio_habitacion | `consultarReservas` |
| recepcionista | `consultarHabitacion`, `listarHabitaciones`, `consultarReservas` |
| cajero | ninguna |
| rol `null` o desconocido | ninguna |

Operaciones registradas: `registrarHabitacion`, `consultarHabitacion`, `listarHabitaciones`, `actualizarHabitacion`, `registrarReserva`, `consultarReservas`. Cualquier operación fuera de esa lista se niega por defecto.

`server.php` valida **antes** de llamar a `$server->service()`:

1. Credenciales inválidas → HTTP `401` con fault `401 Unauthorized: usuario o token invalido.`
2. Sobre ilegible → HTTP `400`.
3. Operación desconocida o rol sin permiso → HTTP `403` con fault `403 Forbidden: permisos insuficientes para ejecutar este método.`
4. Operación permitida → NuSOAP ejecuta y responde `200`.

Se hace antes de `service()` porque NuSOAP convierte cualquier `soap_fault` en HTTP `500`. `ejecutarOperacionHotel()` conserva las mismas verificaciones como defensa en profundidad.

La petición WSDL (`GET ?wsdl`) queda abierta: describe el contrato y no ejecuta ninguna operación.

---

## 5. Endpoints

Todas las rutas están en `routes/api.php`. Prefijo `/api`.

| Método | Ruta | Auth | Rol permitido | Qué hace | Códigos |
|---|---|---|---|---|---|
| POST | `/login` | No | — | Genera token y sincroniza con SOAP | 200, 401, 422, 503 |
| GET | `/me` | Bearer | cualquiera autenticado | Datos del usuario y su rol | 200, 401 |
| POST | `/logout` | Bearer | cualquiera autenticado | Revoca token local y remoto | 200, 401, 503 |
| POST | `/consumos` | Bearer | admin, servicio | Valida reserva en SOAP y registra consumo | 201, 400, 404, 422, 401, 403, 503 |
| GET | `/consumos/reserva/{id_reserva}` | Bearer | admin, servicio, recepción | Consumos + datos de reserva desde SOAP | 200, 401, 403, 503 |
| POST | `/consumos/{id}/pagar` | Bearer | admin, cajero | Marca consumo como pagado y registra pago | 200, 404, 409, 422, 401, 403 |
| GET | `/checkout-status/{id_reserva}` | Bearer | admin, cajero, recepción | Saldo pendiente y paz y salvo | 200, 401, 403 |

### Reglas de negocio de consumos

- `id_reserva`: obligatorio, sin regla de tipo.
- `concepto`: obligatorio, string, máx. 255.
- `monto`: obligatorio, numérico, mínimo 0.01.
- La reserva debe existir en SOAP; si `obtenerReserva()` devuelve `null` → `404`.
- Si SOAP no responde o rechaza la autenticación → `503` (`SoapUnavailableException`), no `404`.
- El estado de la reserva debe ser `activo` o `activa` (comparación en minúsculas) → si no, `400`.
- El consumo se crea con estado `Pendiente`.
- `pagar` acepta exactamente: `Efectivo`, `Tarjeta`, `Cargo a la habitación`.
- Si ya esta `Pagado` → `409` (conflicto de estado, no peticion mal formada).
- `checkout-status` suma solo consumos en estado `Pendiente`; si la suma es 0, `paz_y_salvo = true`.

---

## 6. Base de datos

### 6.1 `hotel_consumos_api` (Laravel)

| Tabla | Uso | Campos relevantes |
|---|---|---|
| `users` | Usuarios de la API | `id`, `name`, `email` único, `password`, `role` (varchar 30, nullable), `email_verified_at` |
| `personal_access_tokens` | Tokens Sanctum | `tokenable`, `name`, `token(64)`, `abilities`, `expires_at` |
| `consumos` | Consumos extras | `id`, `id_reserva` (varchar, **sin FK**), `concepto`, `monto decimal(10,2)`, `estado enum(Pendiente, Pagado)`, `fecha_registro`, timestamps |
| `pagos_consumos` | Pagos | `id`, `consumo_id` FK → `consumos` con `cascade`, `metodo_pago`, `fecha_pago`, timestamps |
| `password_reset_tokens` | **Sin uso** | No hay recuperación de contraseña |
| `failed_jobs` | **Sin uso** | Cola `sync`, no hay jobs |
| `migrations` | Control interno | — |

Relación: `Consumo 1 ── N PagoConsumo` (`consumo_id`).
`id_reserva` apunta lógicamente a `hotel_db.reserva.id`, pero no hay integridad referencial porque están en bases distintas.

### 6.2 `hotel_db` (externa)

| Tabla | Uso |
|---|---|
| `habitacion` | Habitaciones |
| `huesped` | Huéspedes |
| `reserva` | Reservas; `estado` por defecto `CONFIRMADA`, pero el alta de la app inserta `ACTIVA` |
| `usuarios` | `id`, `username` (varchar 50, único), `password`, `api_token` (varchar 80, único, nullable), `role` (varchar 30, nullable), `created_at` |

> `hotel_db.sql` ya crea `usuarios` con `role`, así que un entorno nuevo puede autenticarse y autorizar sin pasos manuales. En una base existente hay que agregar la columna con `ALTER TABLE usuarios ADD COLUMN role VARCHAR(30) NULL AFTER api_token;` si todavía no está.

---

## 7. Configuración y entorno

### Variables realmente usadas

| Variable | Uso |
|---|---|
| `DB_*` | Conexión Laravel y base de `HotelUsuarioSync` |
| `SOAP_SERVICE_URL` | URL base del servidor SOAP |
| `SOAP_WSDL_URL` | Opcional; si falta se usa `SOAP_SERVICE_URL?wsdl` |
| `SOAP_TIMEOUT` | Timeout de llamadas SOAP |
| `SOAP_DB_DATABASE` | Nombre de la BD del servidor SOAP (`hotel_db`) |
| `APP_KEY`, `APP_ENV`, `APP_DEBUG`, `APP_URL`, `LOG_*` | Framework |

### Configuración de pruebas (`phpunit.xml`)

- `APP_ENV=testing`
- Base de datos MySQL `hotel_consumos_api_test`
- Cache, sesión y cola en memoria
- `BCRYPT_ROUNDS=4`

### Diferencias de entorno detectadas

- **Corregido:** `.env.example` usa `APP_NAME`, `APP_URL=http://hotel-consumos-api.test` y `DB_DATABASE=hotel_consumos_api`.
- **Corregido:** `.env` ya no contiene `SOAP_URL` ni `SOAP_SECRET_KEY`.
- **Corregido:** `.env` y `.env.example` están alineados con `base_url` de Postman.
- `.env` contiene credenciales reales de MySQL y está excluido por `.gitignore`; nunca debe subirse.
- Los cuatro usuarios de demostración comparten la contraseña `123456`. Es solo para el entorno local.

---

## 8. Comandos útiles

```bash
php artisan migrate
php artisan db:seed                        # crea los cuatro usuarios de demostración
php artisan usuario:crear correo@dominio.com 123456 --name=Nombre --role=cajero
php artisan serve
php artisan test
php artisan route:list
```

`usuario:crear` crea o actualiza el usuario en `users` y valida email y contraseña (mínimo 6). `--role` acepta `administrador`, `servicio_habitacion`, `cajero` y `recepcionista`; si se omite, el rol no se modifica. Cuando el rol cambia, el comando revoca los tokens de Sanctum y el `api_token` de `hotel_db`. **No genera token**: eso se hace con `POST /api/login`.

Usuarios de demostración (`UsuarioRolSeeder`):

| Rol | Correo | Contraseña |
|---|---|---|
| administrador | `administrador@hotel.test` | `123456` |
| servicio_habitacion | `servicio@hotel.test` | `123456` |
| cajero | `cajero@hotel.test` | `123456` |
| recepcionista | `recepcionista@hotel.test` | `123456` |

`lenin@hotel.test` y `prueba@hotel.test` quedan sin rol: inician sesión, pero reciben `403` en las cuatro acciones de negocio.

---

## 9. Pruebas automatizadas

| Archivo | Pruebas | Cobertura |
|---|---|---|
| `tests/Feature/AuthApiTest.php` | 10 | Login, validación, token, rol en la respuesta, `/me`, revocación, logout, fallos 503 y conservación de tokens |
| `tests/Feature/ConsumoApiTest.php` | 4 | 401 sin token, checkout, `porReserva` con SOAP simulado y alta de consumo |
| `tests/Feature/ConsumoPagoTest.php` | 5 | Pago exitoso, doble pago, método inválido y consumo inexistente |
| `tests/Feature/RoleAccessTest.php` | 11 | Matriz 4x4 de permisos, `401` sin token, rol `null`, cuerpo exacto del `403`, rol falsificado en el body y rol sin permiso no llega a SOAP |
| `tests/Feature/SoapFallbackTest.php` | 4 | SOAP caído devuelve `503` y no `404` |
| `tests/Feature/SoapServiceTest.php` | 1 | Exige token para crear cliente SOAP |
| `tests/Feature/SoapTokenTest.php` | 4 | Flujo real contra `C:\laragon\www\hotel\auth.php`, mapa de permisos SOAP, detección de la operación y formatos inválidos |
| `tests/Feature/ZonaHorariaTest.php` | 1 | La app usa `America/Bogota` y un desfase de `-05:00` |
| `tests/Unit/HotelUsuarioSyncTest.php` | 1 | Guarda SHA-256 y el rol, y revoca solo el token presentado |
| `tests/Unit/ExampleTest.php` | 1 | `assertTrue(true)` — scaffold |
| `tests/Feature/ExampleTest.php` | 1 | `GET /` responde 200 — scaffold |

**Total: 43 pruebas, 176 aserciones, en verde.**

**No cubierto:** concurrencia real de pagos, expiración de tokens, cambio de rol a mitad de sesión (queda forzado por la revocación) y pruebas de carga.

**Acoplamiento:** `SoapTokenTest` hace `require_once` de `C:\laragon\www\hotel\auth.php`, fuera de este proyecto. Si se mueve la carpeta hermana, la prueba falla.

---

## 10. Archivos que hoy no hacen nada

### A. Muertos o residuales del proyecto

| Archivo / elemento | Situación |
|---|---|
| `database/seeders/UsuarioSoapSeeder.php` | No lo llama `DatabaseSeeder`. Solo funciona con `db:seed --class=...`. Está dormido; el seeder activo es `UsuarioRolSeeder`. |
| `tests/Unit/ExampleTest.php` | Solo `assertTrue(true)`. No aporta cobertura. |
| `tests/Feature/ExampleTest.php` | Solo verifica la portada de Laravel; no prueba el dominio. |
| `database/migrations/..._create_password_reset_tokens_table.php` | No existe recuperación de contraseña. |
| `database/migrations/..._create_failed_jobs_table.php` | `QUEUE_CONNECTION=sync`; no hay jobs. |
| `app/Http/Middleware/TrustHosts.php` | Su registro está comentado en `Kernel.php`. |
| `app/Http/Middleware/RedirectIfAuthenticated.php` | Alias `guest`; ninguna ruta lo usa. |
| `app/Http/Middleware/ValidateSignature.php` | Alias `signed`; ninguna ruta lo usa. |
| `app/Http/Middleware/IsAdministrator.php` | Registrado, pero ninguna ruta lo usa en exclusiva (variante A1). |
| `routes/console.php` (`inspire`) | Comando de ejemplo del scaffold. |
| `app/Models/Consumo.php`: `pagos()` | Relación nunca invocada. |
| `app/Models/PagoConsumo.php`: `consumo()` | Relación nunca invocada. |
| `Consumo` / `PagoConsumo`: trait `HasFactory` | No existen `ConsumoFactory` ni `PagoConsumoFactory`; se conserva porque las pruebas crean los modelos directamente. |
| `User`: trait `Notifiable` | No se envían notificaciones. |
| `UserFactory::unverified()` | Nunca se usa. |
| `$fillable`: `fecha_registro`, `fecha_pago` | Nunca se asignan; los pone la BD por defecto. |
| Ruta `sanctum/csrf-cookie` | Registrada por Sanctum, pero no hay SPA que la use. |
| `resources/views/welcome.blade.php` + `routes/web.php` | Solo sirven la portada de Laravel; el proyecto es API-only. No están rotos, pero están fuera del alcance funcional. |

### B. Scaffold estándar que conviene conservar

Aunque no se usen directamente, **no se recomienda borrar sin evaluar**: `app/Providers/*`, `app/Exceptions/Handler.php`, `app/Http/Controllers/Controller.php`, el resto de middleware (`TrustProxies`, `TrimStrings`, `EncryptCookies`, `VerifyCsrfToken`, `Authenticate`, `PreventRequestsDuringMaintenance`), `config/*` y los alias de middleware no usados (`auth.basic`, `auth.session`, `cache.headers`, `can`, `password.confirm`, `verified`). Laravel los espera en la estructura estándar.

Dentro de `config/services.php`, las secciones `mailgun`, `postmark` y `ses` no se usan; solo `soap` y `soap_db` son del proyecto.

### C. Artefactos generados (no son código fuente)

| Ruta | Qué es |
|---|---|
| `bootstrap/cache/services.php`, `packages.php` | Caché de arranque |
| `storage/framework/views/6510b033...php` | Vista Blade compilada |
| `storage/framework/sessions/*` | Sesiones de Laravel |
| `storage/logs/laravel.log` | Log con errores históricos de octubre y noviembre |
| `.phpunit.result.cache` | Caché de PHPUnit; `.gitignore` ignora `/.phpunit.cache`, no este nombre |
| `public/favicon.ico`, `public/robots.txt` | Assets por defecto |
| `vendor/` | Dependencias de Composer |

---

## 11. Hallazgos y riesgos técnicos

### 11.1 Corregidos

**11/11/2026**

1. **SOAP caído ya no se confunde con reserva inexistente.** `SoapService` lanza `SoapUnavailableException` y la API responde `503`; `404` queda para reserva realmente ausente.
2. **`pagar()` es transaccional.** Va dentro de `DB::transaction` con `lockForUpdate`, así que no queda un consumo pagado sin registro de pago.
3. **`hotel_db.usuarios` está en `hotel_db.sql`**, con `role`. Un entorno nuevo queda operativo sin pasos manuales.
4. **`monto` se devuelve como número** en las respuestas (cast a `float`).
5. **`porReserva` devuelve `503` si SOAP falla**, en vez de `200` con datos `N/A`.
6. **`store` valida la entrada** (`id_reserva` como texto acotado, `concepto`, `monto`) antes de llamar a SOAP.
7. **`ext-soap` y `ext-pdo_mysql`** están declaradas en `composer.json`.
8. **`WSDL_CACHE_NONE` se referencia como `\WSDL_CACHE_NONE`** y la extensión está cargada.
9. **`.env.example` y `APP_URL`** reflejan el proyecto; se eliminaron `SOAP_URL` y `SOAP_SECRET_KEY`.
10. **`/api/user` duplicado eliminado**; queda solo `/api/me`, que ahora incluye `rol`.
11. **Autorización por rol implementada en REST y en SOAP** (ver secciones 3.4 y 4.1).
12. **Cambio de rol revoca tokens** en Laravel y en `hotel_db`.

**12/11/2026**

13. **"Ya pagado" devuelve `409 Conflict`** en vez de `400`. El cuerpo conserva la clave `mensaje`.
14. **Zona horaria `America/Bogota`** (UTC-5), alineada con la hora del sistema y de MySQL. Los timestamps de Laravel y los valores por defecto de MySQL coinciden con la hora local del hotel.
15. **Dependencias actualizadas:** `laravel/framework` 10.50.3 → 10.50.4 y componentes Symfony 6.4.46-6.4.48 → 6.4.49-6.4.50. Se corrigieron las advisories con parche disponible en esas ramas.

### 11.2 Pendientes

**Prioridad alta**

1. **Sincronización distribuida sin transacción.** La escritura en `hotel_db` ocurre fuera de la transacción de Laravel. Si el commit local falla después de escribir el token remoto, ambas bases quedan desalineadas. Mitigado en parte: si falla la sincronización, el login devuelve `503` y revierte lo local; el caso residual exige reintentar el login.
2. **Credenciales en texto plano.** `.env` y el entorno Postman contienen contraseñas reales y los cuatro usuarios comparten `123456`. El tipo `secret` de Postman solo las oculta en la interfaz. `.env` está en `.gitignore`.

**Prioridad media**

3. **Sin paginación** en `GET /api/consumos/reserva/{id}`.
4. **`SoapService` depende de `request()`**; no se puede reutilizar desde un job o comando.
5. **`obtenerReserva` descarga todas las reservas y filtra en PHP**, en lugar de pedir una por ID.
6. **Tokens sin expiración** y solo el throttle global de 60 req/min; no hay límite específico de intentos de login.
7. **Las pruebas dependen de MySQL real** (`hotel_consumos_api_test`) y de la carpeta hermana `C:\laragon\www\hotel`.
8. **Advisories sin parche en Laravel 10.** `composer.json` declara `config.policy.advisories.ignore-id` para cuatro avisos que ninguna version 10.x corrige: CRLF en la regla `email`, path confusion en URLs firmadas, XSS en la pagina de debug y una variante del mismo CRLF. Mitigaciones: la app no envia correos ni usa URLs firmadas, y en produccion `APP_DEBUG` debe ser `false`. Actualizar a Laravel 11 o 12 requiere PHP 8.2 o superior (hoy hay 8.1.10).

**Prioridad baja**

9. `laravel/sail` está instalado pero no hay configuración Docker.
10. `tests/Unit/ExampleTest.php` y `tests/Feature/ExampleTest.php` son scaffold sin cobertura de dominio.
11. El log histórico muestra errores de octubre y noviembre (`server` en vez de `serve`, `WSDL_CACHE_NONE` sin extensión). Son ruido anterior a las correcciones.

---

## 12. Recomendación de limpieza

**Ya aplicado:**

- `.env.example`, `APP_URL` y eliminación de `SOAP_URL`/`SOAP_SECRET_KEY`.
- Eliminación de la ruta duplicada `/api/user`.
- README del proyecto en lugar del genérico de Laravel.
- `DatabaseSeeder` conectado a `UsuarioRolSeeder`.
- `hotel_db.sql` con la tabla `usuarios` y su columna `role`.

**Sigue vigente:**

- `tests/Unit/ExampleTest.php` y `tests/Feature/ExampleTest.php`.
- `UsuarioSoapSeeder` (o eliminarlo, o conectarlo si aún se necesita).
- Migraciones de `password_reset_tokens` y `failed_jobs` si no se van a usar esas funciones.
- `TrustHosts`, `RedirectIfAuthenticated` y `ValidateSignature` si no se van a usar.
- Relaciones `Consumo::pagos()` y `PagoConsumo::consumo()` si no se van a exponer.

**Conservar:** middleware y providers estándar, configuración del framework, `users` y `personal_access_tokens`, los middlewares de rol, `postman/`, las pruebas reales y `CrearUsuarioSoap`.

---

## 13. Alcance de esta actualización

- Se modificaron ambos proyectos con autorización expresa: `hotel-consumos-api` y `C:\laragon\www\hotel`.
- Cambios respaldados antes de empezar en `C:\laragon\www\_respaldos\2026-11-11\`.
- Git inicializado/verificado en los dos proyectos con etiqueta base `linea-base`.
- Se ejecutaron migraciones y seeders en la base de desarrollo y la suite completa de pruebas.
- Verificación manual de extremo a extremo con los cuatro roles, por REST y por SOAP.

---

## 14. Roles implementados

| Requisito de la profesora | Estado |
|---|---|
| Cuatro perfiles (`administrador`, `servicio_habitacion`, `cajero`, `recepcionista`) | Implementado en `users.role` y en `hotel_db.usuarios.role` |
| Middleware `IsAdministrator` | Implementado y registrado (sin ruta exclusiva, variante A1) |
| Middleware `CanRegisterConsumptions` | Implementado y aplicado a `POST /api/consumos` |
| Middleware `CanProcessPayments` | Implementado y aplicado a `POST /api/consumos/{id}/pagar` |
| Middleware `CanCheckOut` | Implementado y aplicado a `GET /api/checkout-status/{id}` |
| Middleware `CanConsultAccount` | Implementado y aplicado a `GET /api/consumos/reserva/{id}` |
| Control por accion y no por dato | El middleware decide antes del controlador; el rol no se lee del body |
| `401` sin token / `403` sin permiso | Verificado en REST y en SOAP |
| Cuerpo exacto del `403` | `{"error":"Permisos insuficientes para ejecutar este método"}` |
| Rol `null` bloqueado | `lenin@hotel.test` y `prueba@hotel.test` reciben `403` |
| Autorización en SOAP | Mapa por rol; operaciones desconocidas denegadas por defecto |
| Cambio de rol | Revoca tokens y exige login nuevo |

**Estado:** todos los requisitos acordados quedaron implementados. Los riesgos residuales estan en la seccion 11.2.
