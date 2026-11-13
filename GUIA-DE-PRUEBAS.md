# Guia de pruebas — Hotel Consumos API

Esta guia permite demostrar, paso a paso, que la implementacion cumple el
enunciado final de la profesora:

- cuatro perfiles con su rol;
- login con los nombres `administrador`, `servicio_habitacion`, `cajero` y `recepcionista`;
- `401` cuando falta el token o no es valido;
- `403` con el cuerpo exacto cuando el rol no tiene permiso;
- matriz REST 4x4;
- SOAP limitado a `consultarReservas` para administrador, servicio de habitacion
  y recepcionista, y bloqueado para el cajero;
- pagos, checkout, validaciones y errores controlados.

El orden recomendado para la presentacion es:

1. **Postman** para login, `/me`, matriz REST y flujo de consumos.
2. **SoapUI** para probar el token y el rol directamente contra SOAP.
3. **PowerShell** solo si se quiere mostrar el codigo HTTP exacto sin interfaz.
4. **PHPUnit** como evidencia de regresion.

No se necesita instalar nada nuevo si Laragon ya publica los dos proyectos.

---

## 1. Preparacion del entorno

### 1.1 Arrancar los servicios

1. Abra Laragon y pulse **Start All** (Apache y MySQL).
2. Compruebe que responden estas dos direcciones:

| Servicio | URL |
|---|---|
| API Laravel | `http://hotel-consumos-api.test` |
| WSDL SOAP | `http://127.0.0.1/hotel/server.php?wsdl` |

Si la primera no responde, revise que la carpeta
`C:\laragon\www\hotel-consumos-api` este publicada por Laragon.
Si la segunda no responde, revise que Apache publique `C:\laragon\www\hotel`.

### 1.2 Preparar la base de datos

Desde una terminal en `C:\laragon\www\hotel-consumos-api`:

```bash
php artisan migrate --seed
php artisan cache:clear
php artisan config:clear
```

`migrate` aplica la migracion nueva de `users.username`. `--seed` crea o
actualiza los cuatro usuarios de demostracion.

> Si ya tenia datos y no quiere volver a sembrar todo, ejecute
> `php artisan db:seed --class=UsuarioRolSeeder`.

### 1.3 Verificar los usuarios

Abra Tinker:

```bash
php artisan tinker
```

Pegue esta linea en el prompt `>>>` y pulse Enter:

```php
App\Models\User::all(['username', 'email', 'role']);
```

Para salir escriba `exit`. Tambien puede revisar la tabla con HeidiSQL o
phpMyAdmin:

```sql
SELECT username, email, role FROM hotel_consumos_api.users;
```

Debe ver:

| Rol | Usuario | Correo | Contrasena |
|---|---|---|---|
| administrador | `administrador` | `administrador@hotel.test` | `123456` |
| servicio_habitacion | `servicio_habitacion` | `servicio@hotel.test` | `123456` |
| cajero | `cajero` | `cajero@hotel.test` | `123456` |
| recepcionista | `recepcionista` | `recepcionista@hotel.test` | `123456` |

Los cuatro comparten `123456` porque son cuentas de demostracion.

### 1.4 Verificar las rutas

```bash
php artisan route:list --path=api
```

Debe ver `login`, `me`, `logout`, `registrarConsumo`, `consultarEstadoCuenta`,
`pagarConsumo` y `verificarCheckout`.

---

## 2. Demostracion con Postman

Esta es la parte principal de la presentacion. Postman demuestra el login, el
token, el rol y la matriz REST.

### 2.1 Importar los archivos

1. Abra Postman.
2. Importe los tres archivos de `postman/`:
   - `Hotel API - Token para SoapUI.postman_collection.json`;
   - `Hotel API - Permisos por rol.postman_collection.json`;
   - `Hotel Local.postman_environment.json`.
3. Seleccione el entorno `Hotel Local`.
4. Verifique que `base_url` sea `http://hotel-consumos-api.test`.

### 2.2 Login por rol

1. Abra la coleccion **Hotel API - Permisos por rol**.
2. Abra la carpeta **0. Login por rol**.
3. Ejecute las cuatro peticiones o use **Run folder**.

Cada peticion envia:

```json
{
  "usuario": "administrador",
  "password": "123456",
  "dispositivo": "postman-admin"
}
```

Valores de `usuario`:

| Peticion | Usuario | Token que guarda |
|---|---|---|
| Login administrador | `administrador` | `admin_token` |
| Login servicio_habitacion | `servicio_habitacion` | `servicio_token` |
| Login cajero | `cajero` | `cajero_token` |
| Login recepcionista | `recepcionista` | `recepcion_token` |

Cada una debe responder `200`. En el cuerpo verifique:

```json
{
  "mensaje": "Autenticacion exitosa.",
  "usuario": {
    "id": 1,
    "nombre": "Administrador",
    "usuario": "administrador",
    "email": "administrador@hotel.test",
    "rol": "administrador"
  },
  "token": "...",
  "token_type": "Bearer",
  "soap_username": "administrador",
  "soap_token": "...",
  "expira": "2026-11-13T22:34:46-05:00"
}
```

Los dos datos que se usan despues en SoapUI son:

- `token` (el Bearer de la sesion);
- `soap_username` (el nombre de usuario, no el correo).

El login tambien acepta `email` en lugar de `usuario`:

```json
{
  "email": "administrador@hotel.test",
  "password": "123456"
}
```

### 2.3 Verificar el perfil con `/me`

1. Abra la coleccion **Hotel API - Token para SoapUI**.
2. Ejecute primero el login de esa coleccion.
3. Ejecute `GET /api/me`.

Debe responder `200` con:

```json
{
  "id": 1,
  "nombre": "Administrador",
  "usuario": "administrador",
  "email": "administrador@hotel.test",
  "rol": "administrador",
  "soap_username": "administrador",
  "expira": "2026-11-13T22:34:46-05:00"
}
```

Si quiere probarlo con otro rol, cambie el `usuario` del login, vuelva a
ejecutarlo y repita `/me`.

### 2.4 Prueba 401: sin token o token invalido

1. Abra `GET /api/me`.
2. En la pestana **Authorization**, seleccione `No Auth` o borre la cabecera.
3. Envie la peticion.

Esperado:

```json
{"mensaje":"No autenticado. Se requiere un token Bearer valido."}
```

Codigo: `401`. No debe ser `403`: primero se valida el token y despues el rol.

Repita con un token inventado en `Authorization: Bearer token-invalido`.
Tambien debe responder `401`.

### 2.5 Matriz de permisos

1. Ejecute la carpeta **1. Matriz de permisos**.
2. Son 16 peticiones, una por combinacion de rol y accion.
3. Todas deben quedar en verde.

La tabla que debe cumplirse es:

| Accion | administrador | servicio_habitacion | cajero | recepcionista |
|---|---|---|---|---|
| Registrar consumo (`POST /api/consumos`) | si | si | no | no |
| Consultar estado de cuenta (`GET /api/consumos/reserva/{id}`) | si | si | no | si |
| Pagar consumo (`POST /api/consumos/{id}/pagar`) | si | no | si | no |
| Verificar checkout (`GET /api/checkout-status/{id}`) | si | no | si | si |

Cada peticion comprueba:

- si el rol esta permitido, la respuesta **no** es `403`;
- si el rol no esta permitido, la respuesta es `403` con el cuerpo exacto.

### 2.6 Verificar el cuerpo exacto del 403

Abra una peticion no permitida, por ejemplo **Registrar consumo - cajero**, y
enviela. Debe responder `403` con:

```json
{ "error": "Permisos insuficientes para ejecutar este método" }
```

La tilde de `método` forma parte del contrato y debe conservarse.

### 2.7 El rol no se puede falsificar

En la misma peticion del cajero, cambie el cuerpo a:

```json
{
  "id_reserva": "1",
  "concepto": "Cena",
  "monto": 10,
  "role": "administrador"
}
```

Debe seguir respondiendo `403`. El middleware lee
`$request->user()->role` desde la base de datos e ignora cualquier `role` del
cuerpo.

### 2.8 Flujo completo de consumos y pagos

Use los tokens guardados por la carpeta **0. Login por rol**.

#### Registrar un consumo

En `POST /api/consumos`, con `admin_token` o `servicio_token`:

```json
{
  "id_reserva": "1",
  "concepto": "Cena de prueba",
  "monto": 15000
}
```

Esperado: `201` con el consumo creado. El identificador esta en
`consumo.id` de la respuesta, no en la raiz.

#### Consultar el estado de cuenta

En `GET /api/consumos/reserva/1`, con `admin_token`:

Esperado: `200` con los consumos y los datos de la reserva traidos de SOAP.

#### Pagar el consumo

En `POST /api/consumos/{id}/pagar`, con `admin_token` o `cajero_token`:

```json
{
  "metodo_pago": "Efectivo"
}
```

Esperado: `200`.

#### Pagar dos veces

Repita la misma peticion de pago.

Esperado: `409 Conflict` con:

```json
{"mensaje":"Este consumo ya se encuentra pagado"}
```

#### Verificar checkout

En `GET /api/checkout-status/1`, con `admin_token`, `cajero_token` o
`recepcion_token`.

Esperado: `200` con `paz_y_salvo = true` si ya no hay consumos pendientes.

### 2.9 Otros errores controlados

| Prueba | Como hacerla en Postman | Esperado |
|---|---|---|
| Validacion | Enviar `POST /api/consumos` con `{"concepto":"Sin datos"}` | `422` con `mensaje` y `errors` |
| Ruta inexistente | `GET /api/ruta-inexistente` | `404` con `{"mensaje":"Recurso no encontrado."}` |
| Metodo incorrecto | `GET /api/login` | `405` con `{"mensaje":"Metodo no permitido para esta ruta."}` |
| Demasiados intentos | Enviar 6 veces el login con `usuario` `prueba-throttle` y clave `mala` | Los primeros `401` y el sexto `429` |
| SOAP caido | Detener Apache o MySQL y registrar un consumo | `503` |

Para reiniciar el contador de intentos:

```bash
php artisan cache:clear
```

---

## 3. Demostracion con SoapUI

SoapUI demuestra que el mismo token de Postman sirve para SOAP y que el rol
decide si la operacion se ejecuta o se bloquea.

### 3.1 Obtener el token en Postman

1. Ejecute el login del rol que va a probar.
2. Copie el valor de `token`.
3. Copie tambien el valor de `soap_username`.

Ejemplo para administrador:

| Dato | Valor |
|---|---|
| Username | `administrador` |
| Password | el `token` devuelto por el login |
| Password type | `PasswordText` |

### 3.2 Crear el proyecto SOAP

1. Abra SoapUI.
2. Cree un proyecto SOAP con el WSDL:

```text
http://127.0.0.1/hotel/server.php?wsdl
```

3. Abra la operacion `consultarReservas`.

### 3.3 Configurar WS-Security

1. En `WS-Security Configurations`, agregue una configuracion.
2. Tipo: `Username`.
3. Username: el `soap_username` del login.
4. Password: el `token` del login.
5. Password type: `PasswordText`.
6. Asocie la configuracion a la peticion.

### 3.4 Ejecutar `consultarReservas`

Envie la peticion y compare con esta tabla:

| Username | Token | `consultarReservas` | Otra operacion |
|---|---|---|---|
| `administrador` | vigente | `200` | `403` |
| `servicio_habitacion` | vigente | `200` | `403` |
| `recepcionista` | vigente | `200` | `403` |
| `cajero` | vigente | `403` | `403` |
| cualquiera | invalido o vencido | `401` | `401` |
| usuario sin rol | vigente | `403` | `403` |

Resultado esperado:

- administrador, servicio de habitacion y recepcionista reciben la lista de
  reservas;
- el cajero recibe `403`, porque no tiene ninguna operacion SOAP permitida.

> En SoapUI, el `403` llega como **SOAP Fault** con codigo HTTP `403`, no como
> el JSON de REST. El cuerpo JSON exacto se exige en los middlewares REST; en
> SOAP el servidor responde con un fault.

### 3.5 Token invalido o vencido

Cambie el Password de WS-Security por `token-invalido` y envie la peticion.

Esperado: `401`.

### 3.6 Operacion no permitida

Aunque el rol sea administrador, el enunciado solo autoriza
`consultarReservas`. Abra otra operacion, por ejemplo `listarHabitaciones`, y
enviela con el mismo token.

Esperado: `403`.

### 3.7 Forzar el vencimiento del token

El token dura 480 minutos. Para demostrar el `401` sin esperar 8 horas, fuerce
el vencimiento en `hotel_db`:

```bash
mysql -u root -p hotel_db -e "UPDATE usuarios SET token_expira_en = '2020-01-01 00:00:00' WHERE username = 'administrador';"
```

Si `mysql` no esta en el PATH, use HeidiSQL o phpMyAdmin y ejecute el mismo
`UPDATE` sobre `hotel_db.usuarios`.

Vuelva a enviar `consultarReservas` con el token anterior.

Esperado: `401`. Un login nuevo restaura el acceso.

### 3.8 Recordatorio importante

Cada login nuevo invalida el token anterior. Si vuelve a iniciar sesion en
Postman, debe actualizar el Password en SoapUI o recibira `401`.

---

## 4. Pruebas automatizadas

```bash
php artisan test
```

Resultado esperado: **59 pruebas, 239 aserciones, en verde**.

Para una demostracion rapida por bloque:

```bash
php artisan test --filter=AuthApiTest
php artisan test --filter=RoleAccessTest
php artisan test --filter=SoapTokenTest
php artisan test --filter=FormatoErroresTest
```

La suite usa la base `hotel_consumos_api_test` definida en `phpunit.xml`. No
modifica la base real.

---

## 5. Prueba de `IsAdministrator`

El middleware existe, esta registrado en `app/Http/Kernel.php` y se prueba con
una ruta en memoria. No hay una ruta de negocio exclusiva del administrador
porque el resto de middlewares ya lo incluyen (variante A1).

Ejecute solo esa prueba:

```bash
php artisan test --filter=test_is_administrator_solo_deja_pasar_al_administrador
```

Debe pasar. La prueba demuestra que:

- el administrador recibe `200`;
- servicio de habitacion, cajero y recepcionista reciben `403` con el cuerpo
  exacto.

Si la profesora pregunta por que no hay una ruta exclusiva, la explicacion es:

> El administrador tiene permitidas las cuatro acciones, por eso aparece en
> `CanRegisterConsumptions`, `CanConsultAccount`, `CanProcessPayments` y
> `CanCheckOut`. `IsAdministrator` se conserva como middleware independiente y
> se prueba aislado; usarlo ademas en una ruta no aportaria ningun permiso
> nuevo.

---

## 6. Opcional: pruebas con PowerShell

Esta seccion es solo una alternativa para ver el codigo HTTP exacto sin abrir
Postman. No es necesaria para la presentacion si ya se ejecutaron las secciones
2 y 3.

### 6.1 Funcion auxiliar

Pegue esta funcion al inicio de la sesion: devuelve el codigo HTTP y el cuerpo
aunque la respuesta sea un error.

```powershell
function Invoke-Api {
    param(
        [string]$Method = 'GET',
        [string]$Path,
        [string]$Token,
        [string]$Json
    )

    $headers = @{ Accept = 'application/json' }
    if ($Token) { $headers.Authorization = "Bearer $Token" }

    $parametros = @{
        Uri             = "http://hotel-consumos-api.test$Path"
        Method          = $Method
        Headers         = $headers
        UseBasicParsing = $true
    }

    if ($Json) {
        $parametros.ContentType = 'application/json'
        $parametros.Body = $Json
    }

    try {
        $r = Invoke-WebRequest @parametros
        return [pscustomobject]@{ Status = [int]$r.StatusCode; Body = $r.Content }
    } catch {
        $resp = $_.Exception.Response
        if (-not $resp) { throw }
        $reader = New-Object IO.StreamReader($resp.GetResponseStream())
        return [pscustomobject]@{ Status = [int]$resp.StatusCode; Body = $reader.ReadToEnd() }
    }
}
```

### 6.2 Login y perfil

```powershell
$login = Invoke-Api -Method POST -Path '/api/login' `
  -Json '{"usuario":"administrador","password":"123456"}'

$login.Status
$login.Body | ConvertFrom-Json | Format-List
$admin = ($login.Body | ConvertFrom-Json).token

$me = Invoke-Api -Path '/api/me' -Token $admin
$me.Status
$me.Body
```

Esperado: `200` en login y `200` en `/me`.

### 6.3 Prueba 401

```powershell
$sinToken = Invoke-Api -Path '/api/me'
"Sin token -> HTTP $($sinToken.Status) $($sinToken.Body)"

$tokenMalo = Invoke-Api -Path '/api/me' -Token 'token-invalido'
"Token invalido -> HTTP $($tokenMalo.Status) $($tokenMalo.Body)"
```

Esperado: `401` en ambos casos.

### 6.4 Matriz REST 4x4

Primero obtenga los cuatro tokens:

```powershell
$admin = ((Invoke-Api -Method POST -Path '/api/login' `
  -Json '{"usuario":"administrador","password":"123456"}').Body | ConvertFrom-Json).token

$servicio = ((Invoke-Api -Method POST -Path '/api/login' `
  -Json '{"usuario":"servicio_habitacion","password":"123456"}').Body | ConvertFrom-Json).token

$cajero = ((Invoke-Api -Method POST -Path '/api/login' `
  -Json '{"usuario":"cajero","password":"123456"}').Body | ConvertFrom-Json).token

$recepcion = ((Invoke-Api -Method POST -Path '/api/login' `
  -Json '{"usuario":"recepcionista","password":"123456"}').Body | ConvertFrom-Json).token
```

Despues ejecute la matriz:

```powershell
$tokens = @{
    administrador        = $admin
    servicio_habitacion  = $servicio
    cajero               = $cajero
    recepcionista        = $recepcion
}

$acciones = @(
    @{ Nombre = 'registrar'; Metodo = 'POST'; Ruta = '/api/consumos';
       Json = '{"id_reserva":"1","concepto":"Cena","monto":10}';
       Permitidos = @('administrador','servicio_habitacion') },
    @{ Nombre = 'consultar'; Metodo = 'GET'; Ruta = '/api/consumos/reserva/1';
       Json = $null;
       Permitidos = @('administrador','servicio_habitacion','recepcionista') },
    @{ Nombre = 'pagar'; Metodo = 'POST'; Ruta = '/api/consumos/1/pagar';
       Json = '{"metodo_pago":"Efectivo"}';
       Permitidos = @('administrador','cajero') },
    @{ Nombre = 'checkout'; Metodo = 'GET'; Ruta = '/api/checkout-status/1';
       Json = $null;
       Permitidos = @('administrador','cajero','recepcionista') }
)

foreach ($accion in $acciones) {
    foreach ($rol in $tokens.Keys) {
        $r = Invoke-Api -Method $accion.Metodo -Path $accion.Ruta `
            -Token $tokens[$rol] -Json $accion.Json

        $esperado = if ($accion.Permitidos -contains $rol) { 'permitido' } else { '403' }
        "{0,-22} {1,-22} HTTP {2}  (esperado: {3})" -f `
            $accion.Nombre, $rol, $r.Status, $esperado
    }
}
```

Como leer el resultado:

- si el rol esta permitido, el codigo no debe ser `403`; puede ser `200`,
  `201`, `404` o `409` segun los datos;
- si el rol no esta permitido, el codigo debe ser `403`.

Para ver el cuerpo exacto del `403`:

```powershell
$r = Invoke-Api -Method POST -Path '/api/consumos' -Token $cajero `
  -Json '{"id_reserva":"1","concepto":"Cena","monto":10}'

"HTTP $($r.Status)"
$r.Body
```

Esperado: `403` con el JSON exacto que conserva la tilde de `método`.

### 6.5 Flujo completo

```powershell
$alta = Invoke-Api -Method POST -Path '/api/consumos' -Token $admin `
  -Json '{"id_reserva":"1","concepto":"Cena de prueba","monto":15000}'

"HTTP $($alta.Status)"
$alta.Body

$consumoId = ($alta.Body | ConvertFrom-Json).consumo.id

$pago = Invoke-Api -Method POST -Path "/api/consumos/$consumoId/pagar" `
  -Token $cajero -Json '{"metodo_pago":"Efectivo"}'

"Pago -> HTTP $($pago.Status)"

$repetido = Invoke-Api -Method POST -Path "/api/consumos/$consumoId/pagar" `
  -Token $cajero -Json '{"metodo_pago":"Efectivo"}'

"Repetido -> HTTP $($repetido.Status) $($repetido.Body)"
```

Esperado: alta `201`, primer pago `200`, segundo pago `409`.

### 6.6 Errores controlados

```powershell
$r = Invoke-Api -Method POST -Path '/api/consumos' -Token $admin `
  -Json '{"concepto":"Sin reserva ni monto"}'
"422 -> HTTP $($r.Status)"

$r = Invoke-Api -Path '/api/ruta-inexistente'
"404 -> HTTP $($r.Status)"

$r = Invoke-Api -Path '/api/login'
"405 -> HTTP $($r.Status)"
```

### 6.7 SOAP por PowerShell

Funcion para invocar SOAP:

```powershell
function Invoke-Soap {
    param(
        [string]$Usuario,
        [string]$Token,
        [string]$Operacion = 'consultarReservas'
    )

    $sobre = @"
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:urn="urn:HotelService">
  <soapenv:Header>
    <wsse:Security xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">
      <wsse:UsernameToken>
        <wsse:Username>$Usuario</wsse:Username>
        <wsse:Password>$Token</wsse:Password>
      </wsse:UsernameToken>
    </wsse:Security>
  </soapenv:Header>
  <soapenv:Body>
    <urn:$Operacion/>
  </soapenv:Body>
</soapenv:Envelope>
"@

    try {
        $r = Invoke-WebRequest -Uri 'http://127.0.0.1/hotel/server.php' `
            -Method POST -ContentType 'text/xml; charset=utf-8' `
            -Headers @{ SOAPAction = "`"urn:HotelService#$Operacion`"" } `
            -Body ([Text.Encoding]::UTF8.GetBytes($sobre)) `
            -UseBasicParsing -TimeoutSec 20

        return [pscustomobject]@{ Status = [int]$r.StatusCode; Body = $r.Content }
    } catch {
        $resp = $_.Exception.Response
        if (-not $resp) { return [pscustomobject]@{ Status = 0; Body = $_.Exception.Message } }
        $reader = New-Object IO.StreamReader($resp.GetResponseStream())
        return [pscustomobject]@{ Status = [int]$resp.StatusCode; Body = $reader.ReadToEnd() }
    }
}
```

Prueba por rol:

```powershell
$pruebas = @(
    @{ Usuario = 'administrador';       Token = $admin;     Esperado = 200 },
    @{ Usuario = 'servicio_habitacion'; Token = $servicio;  Esperado = 200 },
    @{ Usuario = 'recepcionista';       Token = $recepcion; Esperado = 200 },
    @{ Usuario = 'cajero';              Token = $cajero;    Esperado = 403 }
)

foreach ($p in $pruebas) {
    $r = Invoke-Soap -Usuario $p.Usuario -Token $p.Token
    "{0,-22} HTTP {1}  (esperado: {2})" -f $p.Usuario, $r.Status, $p.Esperado
}
```

Otras comprobaciones:

```powershell
$r = Invoke-Soap -Usuario 'administrador' -Token 'token-invalido'
"Token invalido -> HTTP $($r.Status)"

$r = Invoke-Soap -Usuario 'administrador' -Token $admin -Operacion 'listarHabitaciones'
"Operacion no permitida -> HTTP $($r.Status)"
```

Esperado: `401` con token invalido y `403` con una operacion no permitida.

---

## 7. Solucion de problemas

| Sintoma | Causa probable | Solucion |
|---|---|---|
| `422` con `The usuario field is required` | Se envio el cuerpo vacio o sin `usuario`/`email` | Envie `{"usuario":"administrador","password":"123456"}` |
| `401` en REST con un token que funcionaba | Se hizo otro login y el token anterior fue revocado | Use el token mas reciente |
| `401` en SOAP | Token vencido, revocado o `Username` con el correo viejo | Haga login y use el `soap_username` devuelto |
| `403` en SOAP para el cajero | Es el comportamiento esperado | No es un error |
| `403` en REST para el rol correcto | El usuario no tiene rol o el rol no esta en la lista | Revise `users.role` y ejecute el seeder |
| `429` al hacer login | Se superaron 5 intentos por minuto | Espere 60 segundos o `php artisan cache:clear` |
| `503` al registrar o consultar | El servidor SOAP o `hotel_db` no responde | Revise Apache, la carpeta `hotel` y MySQL |
| `500` al hacer login | Falta la migracion de `users.username` | `php artisan migrate` |
| Postman no encuentra `{{base_url}}` | No se selecciono el entorno | Seleccione `Hotel Local` |
| SoapUI responde `401` | El Password no es el token vigente | Vuelva a hacer login y actualice el Password |

Revise errores detallados en:

```text
storage/logs/laravel.log
```

---

## 8. Lista de verificacion para la presentacion

- [ ] `php artisan migrate --seed` ejecutado sin errores.
- [ ] Los cuatro usuarios existen con su `username` y su rol.
- [ ] En Postman, los cuatro logins responden `200`.
- [ ] Login con `administrador` devuelve `200` y `soap_username = administrador`.
- [ ] `GET /api/me` sin token devuelve `401`.
- [ ] `GET /api/me` con token devuelve el rol correcto.
- [ ] Matriz REST en Postman: 16 combinaciones, las permitidas no dan `403` y las demas si.
- [ ] El cuerpo del `403` es exacto y conserva `método`.
- [ ] Un `role` falso en el body no cambia el resultado.
- [ ] Registrar, consultar, pagar y checkout funcionan con los roles correctos.
- [ ] El segundo pago devuelve `409`.
- [ ] En SoapUI, administrador, servicio y recepcion devuelven `200` con `consultarReservas`.
- [ ] En SoapUI, el cajero devuelve `403`.
- [ ] En SoapUI, un token invalido devuelve `401`.
- [ ] `php artisan test` termina con 59 pruebas y 239 aserciones en verde.
- [ ] La coleccion de Postman queda completamente en verde.
