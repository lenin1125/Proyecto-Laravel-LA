# Documentación del proyecto `hotel-consumos-api`

> Revisión estática realizada sin modificar el código. Fecha: 09/11/2026.
> Solo se ejecutaron comandos de consulta (`route:list`, `migrate:status`, `php -m`, `SHOW TABLES`, `DESCRIBE`, `COUNT(*)`). No se ejecutó la suite de pruebas para no alterar la base `hotel_consumos_api_test`.

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
- 13 rutas registradas.
- 6 migraciones ejecutadas.
- `hotel_consumos_api`: 7 tablas.
- `hotel_db`: 4 tablas (`habitacion`, `huesped`, `reserva`, `usuarios`).
- No hay repositorio Git inicializado en la carpeta.

---

## 2. Estructura y responsabilidades

```text
app/
├── Console/
│   ├── Commands/CrearUsuarioSoap.php   Comando usuario:crear
│   └── Kernel.php                      Registro de comandos y schedule vacío
├── Exceptions/Handler.php              Handler estándar de Laravel
├── Http/
│   ├── Controllers/Api/
│   │   ├── AuthController.php          Login, perfil y logout
│   │   └── ConsumoController.php       Consumos, pagos y checkout
│   ├── Middleware/                     Scaffold de Laravel (ver sección 10)
│   └── Kernel.php                      Middleware global/api/web
├── Models/
│   ├── User.php                        Usuario + Sanctum
│   ├── Consumo.php                     Tabla consumos
│   └── PagoConsumo.php                 Tabla pagos_consumos
├── Providers/                          Providers estándar
└── Services/
    ├── SoapService.php                 Cliente SOAP con WS-Security
    └── HotelUsuarioSync.php            Sincroniza usuario/token a hotel_db

routes/
├── api.php                             Todas las rutas funcionales
├── web.php                             Solo la página de bienvenida de Laravel
└── console.php                         Comando de ejemplo inspire

database/
├── migrations/                         6 migraciones
├── seeders/                            DatabaseSeeder vacío + UsuarioSoapSeeder
└── factories/UserFactory.php

tests/
├── Feature/                            Auth, consumos y SOAP
└── Unit/                               Sync y test de ejemplo

postman/                                Colección y entorno listos para importar
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
   - llama a `HotelUsuarioSync::sincronizar(email, password_hash, token_plano)`.
4. `HotelUsuarioSync` abre una conexión **PDO directa** a `hotel_db` reutilizando host, puerto, usuario y clave de la conexión MySQL de Laravel:
   - si el usuario existe en `hotel_db.usuarios`, actualiza `password` y `api_token`;
   - si no existe, lo inserta;
   - guarda **SHA-256 del token**, no el token plano.
5. Devuelve:

```json
{
  "mensaje": "Autenticacion exitosa.",
  "usuario": { "id": 1, "nombre": "Lenin", "email": "lenin@hotel.test" },
  "token": "...",
  "token_type": "Bearer",
  "soap_username": "lenin@hotel.test",
  "soap_token": "...",
  "expira": null
}
```

6. Si falla cualquier cosa de la sincronización → `503` y se revierte la transacción local, conservando el token anterior.

> **Consecuencia:** solo hay una sesión activa por usuario. Cada login invalida el token anterior en Laravel **y** en `hotel_db`, por lo que SoapUI debe actualizarse tras cada login.

### 3.2 `GET /api/me`

Devuelve `id`, `nombre`, `email` y `soap_username` del usuario autenticado.

### 3.3 `POST /api/logout`

1. Transacción local: bloquea usuario y llama a `HotelUsuarioSync::revocar(email, bearer_token)`.
2. `revocar()` ejecuta `UPDATE usuarios SET api_token = NULL WHERE username = ? AND api_token = SHA256(token)`.
3. Elimina el token actual de `personal_access_tokens`.
4. Si falla el paso remoto → `503` y **conserva** el token local para reintentar.

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

---

## 5. Endpoints

Todas las rutas están en `routes/api.php`. Prefijo `/api`.

| Método | Ruta | Auth | Qué hace | Códigos |
|---|---|---|---|---|
| POST | `/login` | No | Genera token y sincroniza con SOAP | 200, 401, 422, 503 |
| GET | `/me` | Bearer | Datos del usuario | 200, 401 |
| POST | `/logout` | Bearer | Revoca token local y remoto | 200, 401, 503 |
| POST | `/consumos` | Bearer | Valida reserva en SOAP y registra consumo | 201, 400, 404, 422, 401 |
| GET | `/consumos/reserva/{id_reserva}` | Bearer | Consumos + datos de reserva desde SOAP | 200, 401 |
| POST | `/consumos/{id}/pagar` | Bearer | Marca consumo como pagado y registra pago | 200, 400, 404, 422, 401 |
| GET | `/checkout-status/{id_reserva}` | Bearer | Saldo pendiente y paz y salvo | 200, 401 |
| GET | `/user` | Bearer | Duplicado de `/me`, devuelve el modelo User completo | 200, 401 |

### Reglas de negocio de consumos

- `id_reserva`: obligatorio, sin regla de tipo.
- `concepto`: obligatorio, string, máx. 255.
- `monto`: obligatorio, numérico, mínimo 0.01.
- La reserva debe existir en SOAP; si `obtenerReserva()` devuelve `null` → `404`.
- El estado de la reserva debe ser `activo` o `activa` (comparación en minúsculas) → si no, `400`.
- El consumo se crea con estado `Pendiente`.
- `pagar` acepta exactamente: `Efectivo`, `Tarjeta`, `Cargo a la habitación`.
- Si ya está `Pagado` → `400` (semánticamente debería ser `409`).
- `checkout-status` suma solo consumos en estado `Pendiente`; si la suma es 0, `paz_y_salvo = true`.

---

## 6. Base de datos

### 6.1 `hotel_consumos_api` (Laravel)

| Tabla | Uso | Campos relevantes |
|---|---|---|
| `users` | Usuarios de la API | `id`, `name`, `email` único, `password`, `email_verified_at` |
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
| `usuarios` | `id`, `username` (varchar 50, único), `password`, `api_token` (varchar 80, único, nullable), `created_at` |

> `hotel_db.usuarios` **no aparece en `hotel_db.sql`**. La tabla existe en la base local, pero un entorno nuevo no la crea con ese script: hay que crearla a mano o agregarla al SQL. Si no existe, el login devuelve `503`.

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

- `.env` usa `DB_DATABASE=hotel_consumos_api`; `.env.example` sigue con `DB_DATABASE=laravel`. Un clon nuevo apuntaría a la base equivocada.
- `.env` define `APP_URL=http://localhost`; el entorno Postman usa `http://hotel-consumos-api.test`.
- `.env` conserva `SOAP_URL` y `SOAP_SECRET_KEY` (HMAC legado) que **ningún archivo PHP lee**.
- `.env` contiene credenciales reales de MySQL. `.gitignore` excluye `.env`, pero como no hay Git inicializado, el archivo está en disco sin control de versiones.

---

## 8. Comandos útiles

```bash
php artisan migrate
php artisan usuario:crear correo@dominio.com 123456 --name=Nombre
php artisan serve
php artisan test
php artisan route:list
```

`usuario:crear` crea o actualiza el usuario en `users` y valida email y contraseña (mínimo 6). **No genera el token**: eso se hace con `POST /api/login`.

---

## 9. Pruebas automatizadas

| Archivo | Pruebas | Cobertura |
|---|---|---|
| `tests/Feature/AuthApiTest.php` | 10 | Login, validación, token, `/me`, revocación, logout, fallos 503 y conservación de tokens |
| `tests/Feature/ConsumoApiTest.php` | 4 | 401 sin token, checkout, `porReserva` con SOAP simulado y alta de consumo |
| `tests/Feature/SoapServiceTest.php` | 1 | Exige token para crear cliente SOAP |
| `tests/Feature/SoapTokenTest.php` | 2 | Flujo real contra `C:\laragon\www\hotel\auth.php` y formatos inválidos |
| `tests/Unit/HotelUsuarioSyncTest.php` | 1 | Guarda SHA-256 y revoca solo el token presentado |
| `tests/Unit/ExampleTest.php` | 1 | `assertTrue(true)` — scaffold |
| `tests/Feature/ExampleTest.php` | 1 | `GET /` responde 200 — scaffold |

**Total: 20 pruebas.** No se ejecutaron para no tocar las bases de datos.

**No cubierto:** pago exitoso, reserva inexistente, reserva no activa, SOAP caído, checkout con paz y salvo, doble pago y concurrencia.

**Acoplamiento:** `SoapTokenTest` hace `require_once` de `C:\laragon\www\hotel\auth.php`, fuera de este proyecto. Si se mueve la carpeta hermana, la prueba falla.

---

## 10. Archivos que hoy no hacen nada

### A. Muertos o residuales del proyecto

| Archivo / elemento | Situación |
|---|---|
| `database/seeders/UsuarioSoapSeeder.php` | No lo llama `DatabaseSeeder`. Solo funciona con `db:seed --class=...`. Está dormido. |
| `database/seeders/DatabaseSeeder.php` | Vacío: `php artisan db:seed` no hace nada. |
| `tests/Unit/ExampleTest.php` | Solo `assertTrue(true)`. No aporta cobertura. |
| `tests/Feature/ExampleTest.php` | Solo verifica la portada de Laravel; no prueba el dominio. |
| `database/migrations/..._create_password_reset_tokens_table.php` | No existe recuperación de contraseña. |
| `database/migrations/..._create_failed_jobs_table.php` | `QUEUE_CONNECTION=sync`; no hay jobs. |
| `app/Http/Middleware/TrustHosts.php` | Su registro está comentado en `Kernel.php`. |
| `app/Http/Middleware/RedirectIfAuthenticated.php` | Alias `guest`; ninguna ruta lo usa. |
| `app/Http/Middleware/ValidateSignature.php` | Alias `signed`; ninguna ruta lo usa. |
| `README.md` | Es el README genérico de Laravel; la documentación real está en `postman/README.md`. |
| `.env`: `SOAP_URL` y `SOAP_SECRET_KEY` | Restos del esquema HMAC retirado; no los lee ningún PHP. |
| `routes/console.php` (`inspire`) | Comando de ejemplo del scaffold. |
| `app/Models/Consumo.php`: `pagos()` | Relación nunca invocada. |
| `app/Models/PagoConsumo.php`: `consumo()` | Relación nunca invocada. |
| `Consumo` / `PagoConsumo`: trait `HasFactory` | No existen `ConsumoFactory` ni `PagoConsumoFactory`; `factory()` fallaría. |
| `User`: trait `Notifiable` | No se envían notificaciones. |
| `UserFactory::unverified()` | Nunca se usa. |
| `$fillable`: `fecha_registro`, `fecha_pago` | Nunca se asignan; los pone la BD por defecto. |
| `GET /api/user` en `routes/api.php` | Duplicado de `/api/me`. |
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

### Prioridad alta

1. **SOAP caído se reporta como reserva inexistente.** `SoapService::obtenerReserva()` atrapa cualquier `Throwable` y devuelve `null`; el controlador responde `404 "La reserva no existe"`. Un fallo de red o de credenciales se confunde con datos inexistentes. Conviene distinguir "SOAP no disponible" (`503`) de "reserva no encontrada" (`404`).
2. **`pagar()` no es transaccional.** Primero guarda `estado = Pagado` y luego crea el pago. Si falla la creación del pago, el consumo queda pagado sin registro. Además, dos peticiones concurrentes pueden pagar el mismo consumo dos veces. Debería ir dentro de `DB::transaction` con bloqueo (`lockForUpdate`).
3. **Sincronización distribuida sin transacción.** La escritura en `hotel_db` ocurre fuera de la transacción de Laravel. Si el commit local falla después de escribir el token remoto, ambas bases quedan desalineadas. Ya está documentado en `postman/README.md`, pero conviene tenerlo presente.
4. **Falta `hotel_db.usuarios` en `hotel_db.sql`.** Un entorno limpio no puede autenticarse hasta crear la tabla manualmente.
5. **Credenciales en texto plano.** `.env` y el entorno Postman contienen contraseñas reales. El tipo `secret` de Postman solo las oculta en la interfaz. `SOAP_SECRET_KEY` es un secreto muerto que debería eliminarse.

### Prioridad media

6. **`.env.example` apunta a `DB_DATABASE=laravel`** y conserva `APP_NAME=Laravel`; no refleja el proyecto.
7. **`APP_URL` no coincide con `base_url` de Postman** (`localhost` vs `hotel-consumos-api.test`).
8. **`monto` se devuelve como string** en las respuestas de `store`, `porReserva` y `pagar` (MySQL devuelve `decimal` como string), mientras que `checkout-status` lo convierte a `float`. Inconsistencia de tipos para el cliente.
9. **`porReserva` responde `200` con `N/A`/`Desconocido`** si SOAP falla, ocultando el problema.
10. **`id_reserva` solo tiene `required`.** No valida tipo ni longitud; un array o un valor enorme puede llegar a la consulta.
11. **Semántica HTTP inconsistente:** "ya pagado" devuelve `400` en vez de `409`; las respuestas usan a veces `mensaje` y a veces `error`.
12. **Sin paginación** en `GET /api/consumos/reserva/{id}`; con muchas reservas la respuesta crece sin límite.
13. **`SoapService` depende de `request()`.** No se puede reutilizar desde un job o comando, y las pruebas requieren contexto HTTP.
14. **`obtenerReserva` descarga todas las reservas y filtra en PHP**, en lugar de pedir una por ID. Es un problema de rendimiento si la tabla crece.
15. **Cobertura incompleta del pago** y de los caminos de error de SOAP.
16. **Tokens sin expiración** (`sanctum.expiration = null`) y solo el throttle global de 60 req/min; no hay límite específico de intentos de login.
17. **Zona horaria `UTC`** en `config/app.php`; si el hotel opera en hora local, las fechas de `fecha_registro` y `fecha_pago` no coinciden con la operación.
18. **Las pruebas dependen de MySQL real** (`hotel_consumos_api_test`) y de la carpeta hermana `C:\laragon\www\hotel`.

### Prioridad baja

19. `composer.json` conserva nombre y descripción de Laravel y **no declara `ext-soap` ni `ext-pdo_mysql`** como requisitos.
20. El log histórico muestra `Undefined constant "App\Services\WSDL_CACHE_NONE"` (29-oct): en ese momento la extensión `soap` no estaba cargada. Hoy sí lo está, pero conviene referenciar `\WSDL_CACHE_NONE` y declarar `ext-soap`.
21. `laravel/sail` está instalado pero no hay configuración Docker en el proyecto.
22. El log también registra `Command "server" is not defined` (05-nov): alguien ejecutó `php artisan server` en vez de `php artisan serve`. Es ruido, no un bug de código.

---

## 12. Recomendación de limpieza

**Seguro de eliminar o corregir:**

- `tests/Unit/ExampleTest.php` y `tests/Feature/ExampleTest.php`.
- `UsuarioSoapSeeder` (o conectarlo desde `DatabaseSeeder`).
- Migraciones de `password_reset_tokens` y `failed_jobs` si no se van a usar esas funciones.
- `TrustHosts`, `RedirectIfAuthenticated` y `ValidateSignature` si no se van a usar.
- `SOAP_URL` y `SOAP_SECRET_KEY` del `.env`.
- Relaciones `Consumo::pagos()` y `PagoConsumo::consumo()` si no se van a exponer.
- `HasFactory` en `Consumo` y `PagoConsumo` hasta que existan sus factories.
- Ruta duplicada `/api/user` (o mantenerla conscientemente por compatibilidad).
- README de Laravel, reemplazándolo por uno del proyecto.

**Conservar:** middleware y providers estándar, configuración del framework, `users` y `personal_access_tokens`, `postman/`, las pruebas reales y `CrearUsuarioSoap`.

---

## 13. Alcance de esta revisión

- **No se modificó ningún archivo.**
- Solo se ejecutaron lecturas y consultas: `route:list`, `migrate:status`, `php -m`, `SHOW TABLES`, `DESCRIBE usuarios` y `COUNT(*)`.
- No se ejecutó la suite de pruebas para no alterar la base `hotel_consumos_api_test`.

---

## 14. Pendiente

Queda pendiente el análisis de los nuevos requerimientos enviados por la profesora (perfiles `administrador`, `servicio_habitacion`, `cajero`, `recepcionista`, middlewares `IsAdministrator`, `CanRegisterConsumptions`, `CanProcessPayments`, `CanCheckOut`, y el control de acceso por rol con respuestas `401`/`403`). Ese análisis se agregará a este documento cuando se autorice.
