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

**No hay que importar nada en Postman.** Cada prueba se escribe a mano en una
peticion nueva. Para cada una la guia indica:

1. el metodo (`GET` o `POST`);
2. la URL exacta que se pega en Postman;
3. el cuerpo (`Body`) cuando lleva;
4. el codigo y el cuerpo que debe devolver.

Orden recomendado para la presentacion:

1. **Postman**: login de los cuatro roles, `/me`, matriz de permisos y flujo de consumos.
2. **SoapUI**: el token del login se usa contra SOAP y el rol decide si ejecuta.
3. **PHPUnit**: evidencia de regresion (59 pruebas).
4. **PowerShell**: opcional, solo si se quiere ver el codigo HTTP sin interfaz.

No se necesita instalar nada nuevo si Laragon ya publica los dos proyectos.

---

## 1. Preparacion del entorno

### 1.1 Levantar el servidor

1. Abra Laragon y pulse **Start All** (arranca Apache y MySQL).
2. Abra el navegador en:

```text
http://hotel-consumos-api.test
```

Si aparece la pantalla de bienvenida de Laravel, la API esta arriba.

3. Compruebe el servidor SOAP abriendo:

```text
http://127.0.0.1/hotel/server.php?wsdl
```

Debe mostrarse un XML con definiciones (`definitions`, `service`, `port`).
Si el navegador intenta descargar el archivo, igual esta bien: el WSDL responde.

| Servicio | URL |
|---|---|
| API Laravel (REST) | `http://hotel-consumos-api.test` |
| WSDL SOAP | `http://127.0.0.1/hotel/server.php?wsdl` |

Si la primera no responde, revise que Laragon publique
`C:\laragon\www\hotel-consumos-api`. Si la segunda no responde, revise que
Apache publique `C:\laragon\www\hotel`.

### 1.2 Preparar la base de datos

Desde una terminal en `C:\laragon\www\hotel-consumos-api`:

```bash
php artisan migrate --seed
php artisan cache:clear
php artisan config:clear
```

`migrate` aplica la migracion de `users.username`. `--seed` crea o actualiza los
cuatro usuarios de demostracion.

> Si ya tenia datos y no quiere volver a sembrar todo, ejecute
> `php artisan db:seed --class=UsuarioRolSeeder`.

### 1.3 Verificar los usuarios

Abra Tinker:

```bash
php artisan tinker
```

Pegue esta linea en el prompt `>>>` y pulse Enter:

```php
App\Models\User::all(['id', 'username', 'email', 'role']);
```

Para salir escriba `exit`. Tambien puede revisar la tabla con HeidiSQL o
phpMyAdmin:

```sql
SELECT id, username, email, role FROM hotel_consumos_api.users;
```

Debe ver estos cuatro usuarios (los `id` pueden variar):

| id | Rol | Usuario para el login | Correo | Contrasena |
|---|---|---|---|---|
| 3 | administrador | `administrador` | `administrador@hotel.test` | `123456` |
| 4 | servicio_habitacion | `servicio_habitacion` | `servicio@hotel.test` | `123456` |
| 5 | cajero | `cajero` | `cajero@hotel.test` | `123456` |
| 6 | recepcionista | `recepcionista` | `recepcionista@hotel.test` | `123456` |

Los cuatro comparten `123456` porque son cuentas de demostracion.

### 1.4 Verificar las rutas

```bash
php artisan route:list --path=api
```

Debe ver `login`, `me`, `logout`, `registrarConsumo`, `consultarEstadoCuenta`,
`pagarConsumo` y `verificarCheckout`.

---

## 2. Postman paso a paso

### 2.1 Conocer la pantalla de Postman

Solo se usan cinco partes de la ventana:

| Parte | Para que sirve |
|---|---|
| Boton **New** o el `+` de una pestana | Crear una peticion nueva |
| Selector de metodo (a la izquierda de la URL) | Elegir `GET` o `POST` |
| Campo de URL | Pegar la direccion completa |
| Boton **Send** | Enviar la peticion |
| Pestanas **Body** y **Authorization** | Enviar el JSON y el token |

Dos aclaraciones utiles:

- **GET** solo consulta: no lleva cuerpo.
- **POST** envia datos: el cuerpo se escribe en `Body`.

Para escribir un cuerpo JSON:

1. Pulse la pestana **Body**.
2. Marque **raw**.
3. En el desplegable de la derecha elija **JSON**.

Para enviar el token:

1. Pulse la pestana **Authorization**.
2. En **Type** elija **Bearer Token**.
3. Pegue el token en el campo **Token**.

La respuesta aparece en la parte inferior: arriba el codigo (`200`, `401`,
`403`...) y abajo el cuerpo.

### 2.2 Direcciones que se van a usar

Todas parten de la misma base: `http://hotel-consumos-api.test`.

| Para que | Metodo | URL completa | Lleva cuerpo |
|---|---|---|---|
| Iniciar sesion y obtener token | `POST` | `http://hotel-consumos-api.test/api/login` | si |
| Ver el perfil del usuario del token | `GET` | `http://hotel-consumos-api.test/api/me` | no |
| Registrar un consumo | `POST` | `http://hotel-consumos-api.test/api/consumos` | si |
| Consultar el estado de cuenta | `GET` | `http://hotel-consumos-api.test/api/consumos/reserva/1` | no |
| Pagar un consumo | `POST` | `http://hotel-consumos-api.test/api/consumos/{id}/pagar` | si |
| Verificar checkout | `GET` | `http://hotel-consumos-api.test/api/checkout-status/1` | no |
| Cerrar sesion | `POST` | `http://hotel-consumos-api.test/api/logout` | no |

En `{id}` va el id de un **consumo**, no el de la reserva. En las rutas de
consultar y checkout, `1` es el id de la reserva. Mas adelante se explica como
obtener un id real.

### 2.3 Login: de aqui sale el token para todo

Este es el primer paso obligatorio. Se repite una vez por cada rol.

1. En Postman pulse **New** y elija **HTTP** (o el `+`).
2. En el selector de metodo elija **POST**.
3. Pegue la URL:

```text
http://hotel-consumos-api.test/api/login
```

4. Pulse **Body** → **raw** → **JSON**.
5. Pegue este cuerpo:

```json
{
  "usuario": "administrador",
  "password": "123456",
  "dispositivo": "postman-admin"
}
```

6. Pulse **Send**.

Debe responder `200` con algo asi:

```json
{
  "mensaje": "Autenticacion exitosa.",
  "usuario": {
    "id": 3,
    "nombre": "Administrador",
    "usuario": "administrador",
    "email": "administrador@hotel.test",
    "rol": "administrador"
  },
  "token": "3|wCpvohrn8kFcSl4GdjbIGMBWkettBHenlc4fF37V0cf10d5c",
  "token_type": "Bearer",
  "soap_username": "administrador",
  "soap_token": "3|wCpvohrn8kFcSl4GdjbIGMBWkettBHenlc4fF37V0cf10d5c",
  "expira": "2026-11-19T17:52:54-05:00"
}
```

7. Copie el valor de `token` en un bloc de notas y etiquetelo con el rol.
   Ese valor es el que se pega en **Authorization → Bearer Token**.
8. Copie tambien el valor de `soap_username`. Ese es el **Username** de SoapUI.
   (El `Password` de SoapUI es el mismo `token`.)

Los cuatro logins son iguales, solo cambia `usuario` y el nombre del
dispositivo:

| Rol | Valor de `usuario` | Guarde el `token` como | `soap_username` |
|---|---|---|---|
| administrador | `administrador` | `admin_token` | `administrador` |
| servicio_habitacion | `servicio_habitacion` | `servicio_token` | `servicio_habitacion` |
| cajero | `cajero` | `cajero_token` | `cajero` |
| recepcionista | `recepcionista` | `recepcion_token` | `recepcionista` |

Ejemplo del segundo login:

```json
{
  "usuario": "cajero",
  "password": "123456",
  "dispositivo": "postman-cajero"
}
```

Los cuatro deben responder `200`.

> **Importante:** cada login nuevo de un usuario revoca el token anterior de
> ese mismo usuario. Haga los cuatro logins al principio y use esos tokens
> durante toda la presentacion.

El login tambien acepta `email` en lugar de `usuario`:

```json
{
  "email": "administrador@hotel.test",
  "password": "123456"
}
```

### 2.4 Ver el perfil con el token (`/me`)

1. Cree una peticion nueva.
2. Metodo **GET**.
3. URL:

```text
http://hotel-consumos-api.test/api/me
```

4. Pestana **Authorization** → **Bearer Token** → pegue `admin_token`.
5. Pulse **Send**.

Esperado: `200` con:

```json
{
  "id": 3,
  "nombre": "Administrador",
  "usuario": "administrador",
  "email": "administrador@hotel.test",
  "rol": "administrador",
  "soap_username": "administrador",
  "expira": "2026-11-19T17:52:54-05:00"
}
```

Si repite con `cajero_token`, el campo `rol` debe decir `cajero`.

### 2.5 Probar el 401 (sin token o con token invalido)

**Caso 1: sin token**

1. En la misma peticion `/api/me`, abra **Authorization**.
2. Elija **No Auth**.
3. Pulse **Send**.

Esperado: `401` con:

```json
{"mensaje":"No autenticado. Se requiere un token Bearer valido."}
```

**Caso 2: token inventado**

1. Vuelva a **Authorization** → **Bearer Token**.
2. Pegue `token-invalido`.
3. Pulse **Send**.

Esperado: `401`, el mismo cuerpo.

No debe ser `403`: primero se valida el token y despues el rol.

### 2.6 Matriz de permisos (4 acciones x 4 roles)

La regla es simple:

| Accion | administrador | servicio_habitacion | cajero | recepcionista |
|---|---|---|---|---|
| Registrar consumo (`POST /api/consumos`) | si | si | no | no |
| Consultar estado de cuenta (`GET /api/consumos/reserva/1`) | si | si | no | si |
| Pagar consumo (`POST /api/consumos/{id}/pagar`) | si | no | si | no |
| Verificar checkout (`GET /api/checkout-status/1`) | si | no | si | si |

Cada accion se prueba cuatro veces: una con cada token. Para las permitidas el
codigo **no** debe ser `403`; para las no permitidas debe ser `403`.

#### Accion 1: registrar consumo

- Metodo: **POST**
- URL: `http://hotel-consumos-api.test/api/consumos`
- Authorization: el token del rol que se esta probando
- Body → raw → JSON:

```json
{
  "id_reserva": "1",
  "concepto": "Cena",
  "monto": 15000
}
```

Esperado:

| Token usado | Codigo |
|---|---|
| `admin_token` | `201` |
| `servicio_token` | `201` |
| `cajero_token` | `403` |
| `recepcion_token` | `403` |

La reserva `1` existe y esta `ACTIVA`, por eso el alta es `201`.

#### Accion 2: consultar estado de cuenta

- Metodo: **GET**
- URL: `http://hotel-consumos-api.test/api/consumos/reserva/1`
- Authorization: el token del rol
- Sin cuerpo

Esperado:

| Token usado | Codigo |
|---|---|
| `admin_token` | `200` |
| `servicio_token` | `200` |
| `cajero_token` | `403` |
| `recepcion_token` | `200` |

El `200` trae la reserva y la lista de consumos de esa reserva:

```json
{
  "id_reserva": "1",
  "numero_habitacion": "101",
  "nombre_huesped": "Carlos Andres Mendoza",
  "estado_reserva": "ACTIVA",
  "consumos": [
    {
      "id": 13,
      "id_reserva": "1",
      "concepto": "Cena de prueba",
      "monto": 15000,
      "estado": "Pendiente"
    }
  ]
}
```

Si la reserva no tiene consumos, `consumos` sera `[]`.

#### Accion 3: pagar consumo

- Metodo: **POST**
- URL: `http://hotel-consumos-api.test/api/consumos/8/pagar`
- Authorization: el token del rol
- Body → raw → JSON:

```json
{
  "metodo_pago": "Efectivo"
}
```

Esperado:

| Token usado | Codigo |
|---|---|
| `admin_token` | `200` (o `409` si ya estaba pagado) |
| `servicio_token` | `403` |
| `cajero_token` | `200` (o `409` si ya estaba pagado) |
| `recepcion_token` | `403` |

El `8` es el id de un **consumo**, no de la reserva. Si ese id ya no existe,
use cualquiera que aparezca en `consumos[].id` al consultar el estado de
cuenta. Si ya estaba pagado, el rol permitido recibe `409`; para ver el `200`
registre primero un consumo (seccion 2.9) y use ese id. Lo que importa en esta
prueba es que los roles sin permiso reciben `403` antes de llegar al
controlador.

#### Accion 4: verificar checkout

- Metodo: **GET**
- URL: `http://hotel-consumos-api.test/api/checkout-status/1`
- Authorization: el token del rol
- Sin cuerpo

Esperado:

| Token usado | Codigo |
|---|---|
| `admin_token` | `200` |
| `servicio_token` | `403` |
| `cajero_token` | `200` |
| `recepcion_token` | `200` |

El `200` devuelve:

```json
{
  "id_reserva": "1",
  "paz_y_salvo": false,
  "saldo_pendiente": 30000
}
```

`paz_y_salvo` es `true` cuando `saldo_pendiente` es `0`.

### 2.7 El rol no se puede falsificar

1. Use la peticion de registrar consumo con `cajero_token`.
2. Cambie el cuerpo por:

```json
{
  "id_reserva": "1",
  "concepto": "Cena",
  "monto": 10,
  "role": "administrador"
}
```

3. Pulse **Send**.

Esperado: `403` igual que antes. El middleware lee el rol desde la base de
datos (`$request->user()->role`) e ignora el `role` del cuerpo.

### 2.8 Cuerpo exacto del 403

En cualquier peticion no permitida (por ejemplo registrar consumo con
`cajero_token`), la respuesta `403` debe ser exactamente:

```json
{ "error": "Permisos insuficientes para ejecutar este método" }
```

La tilde de `método` forma parte del contrato y debe conservarse.

### 2.9 Flujo completo de consumos y pagos

Se hace con los tokens ya guardados, en este orden.

**Paso 1: registrar un consumo (rol administrador)**

- **POST** `http://hotel-consumos-api.test/api/consumos`
- Authorization: `admin_token`
- Body:

```json
{
  "id_reserva": "1",
  "concepto": "Cena de prueba",
  "monto": 15000
}
```

Esperado: `201`. En la respuesta busque `consumo.id`:

```json
{
  "mensaje": "Consumo registrado exitosamente",
  "consumo": {
    "id": 13,
    "id_reserva": "1",
    "concepto": "Cena de prueba",
    "monto": 15000,
    "estado": "Pendiente"
  }
}
```

Anote ese `consumo.id` (el numero depende de su base; en el ejemplo, `13`).

**Paso 2: consultar el estado de cuenta**

- **GET** `http://hotel-consumos-api.test/api/consumos/reserva/1`
- Authorization: `admin_token`

Esperado: `200`. En `consumos` debe aparecer el consumo recien creado con
estado `Pendiente`.

**Paso 3: pagar ese consumo (rol cajero)**

- **POST** `http://hotel-consumos-api.test/api/consumos/13/pagar`
  (cambie `13` por el id que anoto)
- Authorization: `cajero_token`
- Body:

```json
{
  "metodo_pago": "Efectivo"
}
```

Esperado: `200`. El consumo pasa a estado `Pagado` y se crea el registro del
pago.

**Paso 4: pagar dos veces**

- Pulse **Send** otra vez con la misma peticion.

Esperado: `409 Conflict` con:

```json
{"mensaje":"Este consumo ya se encuentra pagado"}
```

**Paso 5: verificar checkout**

- **GET** `http://hotel-consumos-api.test/api/checkout-status/1`
- Authorization: `admin_token` (tambien sirve `cajero_token` o `recepcion_token`)

Esperado: `200`. Si no quedan consumos pendientes en la reserva 1,
`paz_y_salvo` sera `true` y `saldo_pendiente` sera `0`.

### 2.10 Errores controlados

| Prueba | Como hacerla en Postman | Esperado |
|---|---|---|
| Validacion | **POST** `/api/consumos` con `admin_token` y cuerpo `{"concepto":"Sin datos"}` | `422` con `mensaje` y `errors` |
| Reserva inexistente | **POST** `/api/consumos` con `admin_token` y `{"id_reserva":"999","concepto":"Cena","monto":10}` | `404` |
| Ruta inexistente | **GET** `http://hotel-consumos-api.test/api/ruta-inexistente` | `404` con `{"mensaje":"Recurso no encontrado."}` |
| Metodo incorrecto | **GET** `http://hotel-consumos-api.test/api/login` | `405` con `{"mensaje":"Metodo no permitido para esta ruta."}` |
| Demasiados intentos | Enviar 6 veces el login con `usuario` `prueba-throttle` y clave `mala` | Los primeros `401` y el sexto `429` |
| SOAP caido | Detener Apache o MySQL y registrar un consumo | `503` |

Para reiniciar el contador de intentos:

```bash
php artisan cache:clear
```

### 2.11 Opcional: importar las colecciones

Si prefiere ejecutar todo de una vez en lugar de escribir cada peticion, los
archivos de `postman/` siguen disponibles:

1. **Import** → seleccione los tres archivos de `postman/`.
2. Elija el entorno **Hotel Local**.
3. Ejecute la carpeta **0. Login por rol** y luego **1. Matriz de permisos**.

Esto es solo un atajo. La presentacion se puede hacer completa con las
peticiones manuales de las secciones 2.3 a 2.10.

---

## 3. SoapUI paso a paso

SoapUI demuestra dos cosas a la vez:

- el token que entrega el login de Postman tambien sirve para SOAP;
- el rol decide si la operacion se ejecuta o se bloquea.

### 3.1 La idea: el rol no se escribe, lo busca el servidor

En SoapUI **no se crea una peticion por rol**. Se crea una sola peticion
(`consultarReservas`) y se cambian solo dos datos: **Username** y **Password**.
El XML de la operacion es el mismo para los cuatro roles.

El servidor hace esto, en este orden:

1. Lee la cabecera WS-Security (`Username` y `Password`).
2. Busca ese `Username` en `hotel_db.usuarios`.
3. Compara el `Password` con el token guardado (`api_token`) y revisa que no
   este vencido (`token_expira_en`). Si algo falla: **401**.
4. Lee el rol de ese usuario (`hotel_db.usuarios.role`).
5. Compara la operacion pedida con las operaciones permitidas para ese rol.
   Si no esta permitida: **403**.
6. Si todo coincide, ejecuta la operacion: **200**.

Por eso el rol nunca viaja en el XML: se deduce del usuario autenticado. Para
comprobarlo en la base:

```sql
SELECT username, role FROM hotel_db.usuarios
WHERE username IN ('administrador', 'servicio_habitacion', 'cajero', 'recepcionista');
```

### 3.2 Que se escribe en la cabecera

Solo dos datos, dentro de `<wsse:UsernameToken>`:

| Campo | Que se escribe | Ejemplo |
|---|---|---|
| `<wsse:Username>` | el `soap_username` del login | `administrador` |
| `<wsse:Password>` | el `token` completo del login | `3\|wCpvohrn...` |

No se escribe: el correo, la contrasena `123456`, el rol, el JSON del login ni
la palabra `Bearer`.

Plantilla de la cabecera:

```xml
<soapenv:Header>
  <wsse:Security xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">
    <wsse:UsernameToken>
      <wsse:Username>administrador</wsse:Username>
      <wsse:Password Type="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-username-token-profile-1.0#PasswordText">PEGA_AQUI_EL_TOKEN</wsse:Password>
    </wsse:UsernameToken>
  </wsse:Security>
</soapenv:Header>
```

Esta cabecera es la misma para las cuatro pruebas. Solo cambia el valor de
`Username` y el token.

### 3.3 De donde salen el usuario y el token

Se obtienen en Postman con el login del rol (seccion 2.3). Cada rol tiene su
propio par:

| Prueba | `usuario` en el login | Username en SoapUI | Password en SoapUI |
|---|---|---|---|
| administrador | `administrador` | `administrador` | `admin_token` |
| servicio_habitacion | `servicio_habitacion` | `servicio_habitacion` | `servicio_token` |
| recepcionista | `recepcionista` | `recepcionista` | `recepcion_token` |
| cajero | `cajero` | `cajero` | `cajero_token` |

Dos reglas importantes:

- el token debe ser del **mismo login** que el Username. Si pone `Username`
  `administrador` con el token del cajero, el servidor responde `401`, porque
  ese token no pertenece a ese usuario;
- cada login nuevo revoca el token anterior de ese usuario. Copie el token
  justo despues del login.

Use el `soap_username`, no el correo. Con el correo el servidor responde `401`.

### 3.4 Crear el proyecto SOAP

1. Abra SoapUI.
2. **File** → **New SOAP Project**.
3. En **Initial WSDL** pegue:

```text
http://127.0.0.1/hotel/server.php?wsdl
```

4. Deje el nombre que propone y pulse **OK**.
5. En el arbol de la izquierda despliegue el proyecto hasta
   `consultarReservas`.
6. Doble clic en `Request 1`. Se abre el editor de la peticion.

La peticion que SoapUI genera trae el header vacio:

```xml
<soapenv:Header/>
```

Ese header vacio es normal. No se llena con el JSON del login: se reemplaza por
el bloque `wsse:Security` de la seccion 3.2.

### 3.5 Configurar la cabecera

Hay dos caminos. Use el que le muestre su version de SoapUI.

**Camino A: con la interfaz (recomendado)**

1. En la parte inferior del editor busque la pestana
   **WS-Security Configurations** (en algunas versiones se llama **Auth**).
2. Pulse el boton verde `+` para agregar una configuracion.
3. Escriba en los campos:
   - **Username**: el `soap_username` del rol (por ejemplo `administrador`).
   - **Password**: el `token` copiado de Postman.
   - **Password Type**: `PasswordText`.
4. Marque la configuracion como **Outgoing** (o pulse **Apply**) para que se
   aplique a la peticion.
5. Verifique en la pestana **Raw** que el sobre ya incluye `wsse:Security` con
   `Username` y `Password`.

**Camino B: escribiendo el XML**

Si no encuentra esa pestana, use la pestana **XML** del editor y pegue el sobre
completo. Cambie `PEGA_AQUI_EL_TOKEN` por su token:

```xml
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
                  xmlns:urn="urn:HotelService">
  <soapenv:Header>
    <wsse:Security xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">
      <wsse:UsernameToken>
        <wsse:Username>administrador</wsse:Username>
        <wsse:Password Type="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-username-token-profile-1.0#PasswordText">PEGA_AQUI_EL_TOKEN</wsse:Password>
      </wsse:UsernameToken>
    </wsse:Security>
  </soapenv:Header>
  <soapenv:Body>
    <urn:consultarReservas/>
  </soapenv:Body>
</soapenv:Envelope>
```

### 3.6 Primera prueba: administrador

1. Con `Username` `administrador` y su token, pulse el boton verde de enviar.
2. Debe responder `200`. El servidor devuelve la lista de reservas como un texto
   JSON dentro de `<return>`:

```xml
<SOAP-ENV:Body>
  <ns1:consultarReservasResponse xmlns:ns1="urn:HotelService">
    <return xsi:type="xsd:string">[
      {"id":1,"fecha_entrada":"2026-10-15","fecha_salida":"2026-10-20",
       "estado":"ACTIVA","numero_habitacion":"101","tipo_habitacion":"Sencilla",
       "nombre_huesped":"Carlos Andres Mendoza","documento":"1010101"},
      {"id":2,...},
      {"id":3,...}
    ]</return>
  </ns1:consultarReservasResponse>
</SOAP-ENV:Body>
```

Deben aparecer las reservas 1, 2 y 3. En SoapUI las comillas se ven escapadas
como `&quot;`; es normal, porque la lista viaja como cadena de texto.

### 3.7 Las cuatro pruebas por rol

No cambie el XML de la operacion ni cree peticiones nuevas. Solo cambie
**Username** y **Password** en la cabecera y envie otra vez:

| # | Username | Password | Resultado esperado |
|---|---|---|---|
| 1 | `administrador` | `admin_token` | `200` con la lista |
| 2 | `servicio_habitacion` | `servicio_token` | `200` con la lista |
| 3 | `recepcionista` | `recepcion_token` | `200` con la lista |
| 4 | `cajero` | `cajero_token` | `403` con SOAP Fault |

En la prueba 4 el usuario y el token son correctos: lo que falla es el permiso.
El cajero no tiene ninguna operacion SOAP permitida, por eso recibe `403`.

### 3.8 Como leer el resultado

| HTTP | Cuando ocurre | Que muestra SoapUI |
|---|---|---|
| `200` | el rol tiene permiso para esa operacion | la lista de reservas en `<return>` |
| `401` | token invalido, vencido, o Username y token de logins distintos | `SOAP Fault`: `401 Unauthorized: usuario o token invalido.` |
| `403` | el rol no tiene permiso, o la operacion no esta autorizada | `SOAP Fault`: `403 Forbidden: permisos insuficientes para ejecutar este método.` |

En SoapUI el codigo HTTP se ve en la respuesta (pestana **Headers** o **Raw**) y
en la barra de estado de la parte inferior. El detalle del error viene dentro
del SOAP Fault, no como el JSON de REST.

> El `403` de SoapUI no es un fallo de configuracion: es la prueba de que el
> control de permisos funciona. El mismo token del cajero si puede usarse en
> REST para pagar consumos, pero no para SOAP.

### 3.9 Token invalido

Cambie el **Password** de WS-Security por `token-invalido` y envie.

Esperado: `401` con:

```xml
<soap:Fault>
  <faultcode>soap:Client</faultcode>
  <faultstring>401 Unauthorized: usuario o token invalido.</faultstring>
</soap:Fault>
```

Pruebe tambien el caso contrario: `Username` `administrador` con el token
vigente del cajero. Esperado: `401`, porque el token no pertenece a ese
usuario.

### 3.10 Operacion no permitida

Aunque el rol sea administrador, el enunciado solo autoriza
`consultarReservas`. Abra otra operacion del proyecto, por ejemplo
`listarHabitaciones`, deje la misma cabecera y enviela.

Esperado: `403`, con el mismo `faultstring` del 403 anterior.

### 3.11 Forzar el vencimiento del token

El token dura 480 minutos. Para demostrar el `401` sin esperar 8 horas, fuerce
el vencimiento en `hotel_db`:

```bash
mysql -u root -p hotel_db -e "UPDATE usuarios SET token_expira_en = '2020-01-01 00:00:00' WHERE username = 'administrador';"
```

Si `mysql` no esta en el PATH, use HeidiSQL o phpMyAdmin y ejecute el mismo
`UPDATE` sobre `hotel_db.usuarios`.

Vuelva a enviar `consultarReservas` con el token anterior.

Esperado: `401`. Un login nuevo restaura el acceso.

### 3.12 Recordatorio importante

Cada login nuevo invalida el token anterior de ese usuario. Si vuelve a iniciar
sesion en Postman, debe actualizar el **Password** en SoapUI o recibira `401`.

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
| No se donde pegar el token | Falta la pestana Authorization | **Authorization** → **Bearer Token** → pegar el `token` del login |
| `422` con `The usuario field is required` | Se envio el cuerpo vacio o sin `usuario`/`email` | Envie `{"usuario":"administrador","password":"123456"}` |
| `401` en REST con un token que funcionaba | Se hizo otro login y el token anterior fue revocado | Use el token mas reciente |
| `401` en SOAP | Token vencido, revocado o `Username` con el correo viejo | Haga login y use el `soap_username` devuelto |
| `403` en SOAP para el cajero | Es el comportamiento esperado | No es un error |
| `403` en REST para el rol correcto | El usuario no tiene rol o el rol no esta en la lista | Revise `users.role` y ejecute el seeder |
| `429` al hacer login | Se superaron 5 intentos por minuto | Espere 60 segundos o `php artisan cache:clear` |
| `503` al registrar o consultar | El servidor SOAP o `hotel_db` no responde | Revise Apache, la carpeta `hotel` y MySQL |
| `500` al hacer login | Falta la migracion de `users.username` | `php artisan migrate` |
| Postman no encuentra `{{base_url}}` | No se selecciono el entorno (solo si importo las colecciones) | Seleccione `Hotel Local` |
| SoapUI responde `401` | El Password no es el token vigente | Vuelva a hacer login y actualice el Password |

Revise errores detallados en:

```text
storage/logs/laravel.log
```

---

## 8. Lista de verificacion para la presentacion

- [ ] Laragon arriba y `http://hotel-consumos-api.test` muestra la pantalla de Laravel.
- [ ] `php artisan migrate --seed` ejecutado sin errores.
- [ ] Los cuatro usuarios existen con su `username` y su rol.
- [ ] En Postman, los cuatro logins responden `200`.
- [ ] El login de `administrador` devuelve `soap_username = administrador`.
- [ ] `GET /api/me` sin token devuelve `401`.
- [ ] `GET /api/me` con token devuelve el rol correcto.
- [ ] Matriz REST: las 16 combinaciones cumplen (permitidas no dan `403`, no permitidas si).
- [ ] El cuerpo del `403` es exacto y conserva `método`.
- [ ] Un `role` falso en el body no cambia el resultado.
- [ ] Registrar consumo devuelve `201` con `admin_token` y `servicio_token`.
- [ ] Consultar estado de cuenta devuelve `200` con los consumos y la reserva.
- [ ] Pagar devuelve `200` y el segundo pago devuelve `409`.
- [ ] Checkout devuelve `200` con `paz_y_salvo` y `saldo_pendiente`.
- [ ] En SoapUI, administrador, servicio y recepcion devuelven `200` con `consultarReservas`.
- [ ] En SoapUI, el cajero devuelve `403`.
- [ ] En SoapUI, un token invalido devuelve `401`.
- [ ] `php artisan test` termina con 59 pruebas y 239 aserciones en verde.
- [ ] Opcional: las colecciones importadas de `postman/` quedan en verde.
