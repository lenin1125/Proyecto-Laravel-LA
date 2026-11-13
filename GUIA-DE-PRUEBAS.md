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

## 2. Prueba rapida con PowerShell

Los ejemplos usan Windows PowerShell. Pegue esta funcion al inicio de la
sesion: devuelve el codigo HTTP y el cuerpo aunque la respuesta sea un error.

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
        Uri            = "http://hotel-consumos-api.test$Path"
        Method         = $Method
        Headers        = $headers
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

### 2.1 Login por nombre de usuario

```powershell
$login = Invoke-Api -Method POST -Path '/api/login' `
  -Json '{"usuario":"administrador","password":"123456"}'

$login.Status
$login.Body | ConvertFrom-Json | Format-List
$admin = ($login.Body | ConvertFrom-Json).token
```

Esperado: `200` y un cuerpo como este:

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

El login tambien acepta el correo:

```powershell
Invoke-Api -Method POST -Path '/api/login' `
  -Json '{"email":"administrador@hotel.test","password":"123456"}'
```

### 2.2 Perfil autenticado

```powershell
$me = Invoke-Api -Path '/api/me' -Token $admin
$me.Status
$me.Body
```

Esperado: `200` con `usuario`, `rol` y `soap_username = administrador`.

---

## 3. Prueba 401: sin token o token invalido

```powershell
$sinToken = Invoke-Api -Path '/api/me'
"Sin token -> HTTP $($sinToken.Status) $($sinToken.Body)"

$tokenMalo = Invoke-Api -Path '/api/me' -Token 'token-inventado'
"Token invalido -> HTTP $($tokenMalo.Status) $($tokenMalo.Body)"
```

Esperado en ambos casos:

```json
{"mensaje":"No autenticado. Se requiere un token Bearer valido."}
```

Codigo: `401`. No debe ser `403`: primero se valida el token y despues el rol.

Repita la idea con una ruta de negocio:

```powershell
$sinToken = Invoke-Api -Method POST -Path '/api/consumos' `
  -Json '{"id_reserva":"1","concepto":"Cena","monto":10}'
"Consumos sin token -> HTTP $($sinToken.Status)"
```

---

## 4. Prueba de la matriz REST 4x4

### 4.1 Obtener los cuatro tokens

Cada login guarda el token en una variable. Ejecute los cuatro:

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

### 4.2 Ejecutar las cuatro acciones por rol

La tabla que debe cumplirse es:

| Accion | administrador | servicio_habitacion | cajero | recepcionista |
|---|---|---|---|---|
| Registrar consumo (`POST /api/consumos`) | si | si | no | no |
| Consultar estado de cuenta (`GET /api/consumos/reserva/{id}`) | si | si | no | si |
| Pagar consumo (`POST /api/consumos/{id}/pagar`) | si | no | si | no |
| Verificar checkout (`GET /api/checkout-status/{id}`) | si | no | si | si |

Script completo:

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

- Si el rol esta permitido, el codigo no debe ser `403`. Puede ser `200`,
  `201`, `404` o `409` segun los datos de prueba; lo importante es que el
  middleware lo dejo pasar.
- Si el rol no esta permitido, el codigo debe ser `403` y el cuerpo debe ser
  exactamente:

```json
{ "error": "Permisos insuficientes para ejecutar este método" }
```

### 4.3 Verificar el cuerpo exacto del 403

```powershell
$r = Invoke-Api -Method POST -Path '/api/consumos' -Token $cajero `
  -Json '{"id_reserva":"1","concepto":"Cena","monto":10}'

"HTTP $($r.Status)"
$r.Body
```

Esperado: `403` y el JSON anterior. La tilde de `método` forma parte del
contrato y debe conservarse.

### 4.4 El rol no se puede falsificar

```powershell
$r = Invoke-Api -Method POST -Path '/api/consumos' -Token $cajero `
  -Json '{"id_reserva":"1","concepto":"Cena","monto":10,"role":"administrador"}'

"HTTP $($r.Status) $($r.Body)"
```

Esperado: `403`. El middleware lee `$request->user()->role` desde la base de
datos e ignora cualquier `role` del cuerpo.

---

## 5. Flujo completo de consumos y pagos

Use el token del administrador. Si prefiere, use el de servicio de habitacion
para registrar.

### 5.1 Registrar un consumo

```powershell
$alta = Invoke-Api -Method POST -Path '/api/consumos' -Token $admin `
  -Json '{"id_reserva":"1","concepto":"Cena de prueba","monto":15000}'

"HTTP $($alta.Status)"
$alta.Body
```

Esperado: `201` con el consumo creado. Guarde el `id`:

```powershell
$consumoId = ($alta.Body | ConvertFrom-Json).id
```

Si SOAP no responde, el resultado es `503`; si la reserva no existe, `404`; si
la reserva no esta activa, `400`.

### 5.2 Consultar el estado de cuenta

```powershell
$cuenta = Invoke-Api -Path '/api/consumos/reserva/1' -Token $admin
"HTTP $($cuenta.Status)"
$cuenta.Body
```

### 5.3 Pagar el consumo

Use el token del administrador o del cajero:

```powershell
$pago = Invoke-Api -Method POST -Path "/api/consumos/$consumoId/pagar" `
  -Token $cajero -Json '{"metodo_pago":"Efectivo"}'

"HTTP $($pago.Status)"
$pago.Body
```

Esperado: `200`.

### 5.4 Pagar dos veces: 409

```powershell
$repetido = Invoke-Api -Method POST -Path "/api/consumos/$consumoId/pagar" `
  -Token $cajero -Json '{"metodo_pago":"Efectivo"}'

"HTTP $($repetido.Status) $($repetido.Body)"
```

Esperado: `409 Conflict` con `{"mensaje":"Este consumo ya se encuentra pagado"}`.

### 5.5 Checkout

```powershell
$checkout = Invoke-Api -Path '/api/checkout-status/1' -Token $recepcion
"HTTP $($checkout.Status)"
$checkout.Body
```

Esperado: `200` con `paz_y_salvo = true` si ya no hay consumos pendientes.

---

## 6. Validaciones y errores controlados

### 6.1 422 por datos incompletos

```powershell
$r = Invoke-Api -Method POST -Path '/api/consumos' -Token $admin `
  -Json '{"concepto":"Sin reserva ni monto"}'

"HTTP $($r.Status)"
$r.Body
```

Esperado: `422` con `mensaje` y `errors` por campo.

### 6.2 404 de ruta y 405 de metodo

```powershell
$r = Invoke-Api -Path '/api/ruta-inexistente'
"404 -> HTTP $($r.Status) $($r.Body)"

$r = Invoke-Api -Path '/api/login'
"405 -> HTTP $($r.Status) $($r.Body)"
```

### 6.3 429 por demasiados intentos de login

Use un usuario que no exista para no bloquear las cuentas demo:

```powershell
for ($i = 1; $i -le 6; $i++) {
    $r = Invoke-Api -Method POST -Path '/api/login' `
      -Json '{"usuario":"prueba-throttle","password":"mala"}'
    "Intento $i -> HTTP $($r.Status) $($r.Body)"
}
```

Esperado: los primeros `401` y el sexto `429` con
`{"mensaje":"Demasiadas solicitudes. Reintente en N segundos."}`.

Para reiniciar el contador:

```bash
php artisan cache:clear
```

---

## 7. Prueba SOAP por rol

El servidor SOAP valida el mismo par usuario + token que REST. El `Username`
debe ser el nombre de usuario (`administrador`, `servicio_habitacion`, `cajero`
o `recepcionista`) y el `Password` es el token del login.

### 7.1 Preparar los tokens

Repita la seccion 4.1. Cada login invalida el token anterior, asi que no
reutilice un token viejo.

### 7.2 Funcion PowerShell para invocar SOAP

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

### 7.3 Ejecutar por rol

```powershell
$pruebas = @(
    @{ Usuario = 'administrador';       Token = $admin;    Esperado = 200 },
    @{ Usuario = 'servicio_habitacion'; Token = $servicio; Esperado = 200 },
    @{ Usuario = 'recepcionista';       Token = $recepcion; Esperado = 200 },
    @{ Usuario = 'cajero';              Token = $cajero;   Esperado = 403 }
)

foreach ($p in $pruebas) {
    $r = Invoke-Soap -Usuario $p.Usuario -Token $p.Token
    "{0,-22} HTTP {1}  (esperado: {2})" -f $p.Usuario, $r.Status, $p.Esperado
}
```

Resultado esperado:

- administrador, servicio_habitacion y recepcionista: `200` con la lista de
  reservas;
- cajero: `403`, porque no tiene ninguna operacion SOAP permitida.

### 7.4 Token invalido o vencido: 401

```powershell
$r = Invoke-Soap -Usuario 'administrador' -Token 'token-invalido'
"HTTP $($r.Status)"
```

Esperado: `401`.

### 7.5 Operacion no permitida: 403

Aunque el rol sea administrador, el enunciado solo autoriza
`consultarReservas`. Pruebe otra operacion:

```powershell
$r = Invoke-Soap -Usuario 'administrador' -Token $admin -Operacion 'listarHabitaciones'
"HTTP $($r.Status)"
```

Esperado: `403`.

### 7.6 Con SoapUI

1. Cree un proyecto SOAP con el WSDL
   `http://127.0.0.1/hotel/server.php?wsdl`.
2. Abra la operacion `consultarReservas`.
3. En `WS-Security Configurations`, agregue una configuracion:
   - Tipo: `Username`;
   - Username: `administrador`, `servicio_habitacion`, `recepcionista` o `cajero`;
   - Password: el token del login;
   - Password type: `PasswordText`.
4. Asocie la configuracion a la peticion y ejecutela.

Recuerde: cada login nuevo invalida el token anterior. Si SoapUI empieza a
responder `401`, vuelva a hacer login y actualice el Password.

---

## 8. Prueba opcional: vencimiento del token

El token dura 480 minutos. Para demostrar el `401` sin esperar 8 horas, fuerce
el vencimiento en `hotel_db`:

```bash
mysql -u root -p hotel_db -e "UPDATE usuarios SET token_expira_en = '2020-01-01 00:00:00' WHERE username = 'administrador';"
```

Si `mysql` no esta en el PATH, use HeidiSQL o phpMyAdmin y ejecute el mismo
`UPDATE` sobre `hotel_db.usuarios`.

Despues:

```powershell
$r = Invoke-Soap -Usuario 'administrador' -Token $admin
"SOAP con token vencido -> HTTP $($r.Status)"
```

Esperado: `401`. Un login nuevo restaura el acceso.

---

## 9. Prueba de `IsAdministrator`

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

## 10. Pruebas automatizadas

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

## 11. Prueba con Postman

### 11.1 Importar

1. Abra Postman.
2. Importe los tres archivos de `postman/`:
   - `Hotel API - Token para SoapUI.postman_collection.json`;
   - `Hotel API - Permisos por rol.postman_collection.json`;
   - `Hotel Local.postman_environment.json`.
3. Seleccione el entorno `Hotel Local`.
4. Verifique que `base_url` sea `http://hotel-consumos-api.test`.

### 11.2 Ejecutar la matriz

1. Ejecute la carpeta **0. Login por rol**. Son cuatro peticiones y guardan
   `admin_token`, `servicio_token`, `cajero_token` y `recepcion_token`.
2. Ejecute la carpeta **1. Matriz de permisos**. Son 16 peticiones, una por
   combinacion de rol y accion.
3. Todas deben quedar en verde. Cada peticion comprueba que el rol permitido no
   reciba `403` y que el rol no permitido reciba `403` con el cuerpo exacto.

### 11.3 Ejecutar la coleccion base

La coleccion **Hotel API - Token para SoapUI** muestra el flujo completo:
login, perfil, logout, consumos y una llamada SOAP. Guarda el token en
`soap_token` y el usuario en `soap_username`.

Para probar otro rol, cambie `soap_username` por `servicio_habitacion`,
`cajero` o `recepcionista`, ajuste la clave si hace falta y vuelva a ejecutar
el login.

---

## 12. Solucion de problemas

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

## 13. Lista de verificacion para la presentacion

- [ ] `php artisan migrate --seed` ejecutado sin errores.
- [ ] Los cuatro usuarios existen con su `username` y su rol.
- [ ] Login con `administrador` devuelve `200` y `soap_username = administrador`.
- [ ] `GET /api/me` sin token devuelve `401`.
- [ ] `GET /api/me` con token devuelve el rol correcto.
- [ ] Matriz REST: 16 combinaciones, las permitidas no dan `403` y las demas si.
- [ ] El cuerpo del `403` es exacto y conserva `método`.
- [ ] Un `role` falso en el body no cambia el resultado.
- [ ] Registrar, consultar, pagar y checkout funcionan con los roles correctos.
- [ ] El segundo pago devuelve `409`.
- [ ] SOAP: administrador, servicio y recepcion devuelven `200` con `consultarReservas`.
- [ ] SOAP: cajero devuelve `403`.
- [ ] SOAP: token invalido devuelve `401`.
- [ ] `php artisan test` termina con 59 pruebas y 239 aserciones en verde.
- [ ] La coleccion de Postman queda completamente en verde.
