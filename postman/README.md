# Postman: token, roles y permisos

Este directorio contiene tres archivos listos para importar:

- `Hotel API - Token para SoapUI.postman_collection.json`: flujo base de login, consumos y SOAP.
- `Hotel API - Permisos por rol.postman_collection.json`: matriz de permisos de los cuatro roles.
- `Hotel Local.postman_environment.json`: entorno compartido por las dos colecciones.

## 1. Usuarios de demostracion

| Rol | Correo | Contrasena |
|---|---|---|
| administrador | `administrador@hotel.test` | `123456` |
| servicio_habitacion | `servicio@hotel.test` | `123456` |
| cajero | `cajero@hotel.test` | `123456` |
| recepcionista | `recepcionista@hotel.test` | `123456` |

Se crean con:

```bash
php artisan db:seed
```

O uno por uno:

```bash
php artisan usuario:crear cajero@hotel.test 123456 --name=Cajero --role=cajero
```

`123456` es una credencial de prueba. En un entorno real cada rol debe tener su
propia contrasena.

`lenin@hotel.test` queda sin rol a proposito: puede iniciar sesion, pero recibe
`403` en las cuatro acciones de negocio.

## 2. Matriz de permisos

| Accion | administrador | servicio_habitacion | cajero | recepcionista |
|---|---|---|---|---|
| Registrar consumo | si | si | no | no |
| Consultar estado de cuenta | si | si | no | si |
| Pagar consumo | si | no | si | no |
| Verificar checkout | si | no | si | si |

- Sin token: `401` con `{"mensaje":"No autenticado. Se requiere un token Bearer valido."}`.
- Token valido sin permiso: `403` con `{"error":"Permisos insuficientes para ejecutar este método"}`.
- El rol se lee de la base de datos, nunca del cuerpo de la peticion.
- El login admite 5 intentos por minuto por correo e IP; despues responde `429`
  con `{"mensaje":"Demasiadas solicitudes. Reintente en N segundos."}`.
- El token dura 8 horas. Si se vence, REST y SOAP responden `401` y hay que
  ejecutar el login otra vez.

## 3. Importar y ejecutar

1. Abre Postman e importa los tres archivos.
2. Selecciona el entorno `Hotel Local`.
3. Si tu API no responde en `http://hotel-consumos-api.test`, cambia `base_url`.
4. Ejecuta la carpeta `0. Login por rol`: guarda un token por rol en
   `admin_token`, `servicio_token`, `cajero_token` y `recepcion_token`.
5. Ejecuta la carpeta `1. Matriz de permisos`: son 16 peticiones, una por
   combinacion de rol y accion.

Cada peticion tiene su propia prueba:

- Si el rol esta permitido, comprueba que la respuesta **no** sea `403`.
- Si el rol no esta permitido, comprueba `403` y el cuerpo exacto.

## 4. Coleccion base

La coleccion `Hotel API - Token para SoapUI` sigue funcionando. Usa
`soap_username` y `soap_password` del entorno, que por defecto apuntan al
administrador, y guarda el token en `soap_token`.

Para probar otro rol en esa coleccion, cambia `soap_username` por el correo del
rol y vuelve a ejecutar el login.

## 5. SoapUI

1. Crea un proyecto SOAP con el WSDL:
   `http://127.0.0.1/hotel/server.php?wsdl`
2. Abre `consultarReservas`.
3. En `WS-Security Configurations` agrega una configuracion:
   - Tipo: `Username`
   - Username: el correo del usuario
   - Password: el token generado por el login
   - Password type: `PasswordText`
4. Asocia la configuracion a la peticion y ejecutala.

SoapUI solo necesita el usuario y el token. No envia la contrasena.

## 6. Como funciona

1. `POST /api/login` valida el correo y la contrasena en Laravel.
2. Laravel elimina los tokens anteriores del usuario y emite uno nuevo.
3. El usuario, su rol, el hash SHA-256 del token y su vencimiento en UTC se copian a `hotel_db.usuarios`.
4. `server.php` valida usuario y token, revisa que el token no este vencido y ademas
   comprueba que el rol pueda ejecutar la operacion solicitada.
5. Si el par usuario/token no coincide o el token vencio, responde `401`. Si el rol no
   tiene permiso para esa operacion, responde `403`.

## 7. Notas

- Cada login revoca el token anterior de ese usuario. Con cuatro usuarios puedes
  mantener cuatro sesiones activas al mismo tiempo.
- `POST /api/logout` revoca el token en Laravel y en `hotel_db`.
- Laravel reenvia el mismo token de la peticion REST a SOAP en WS-Security.
- Si falla la sincronizacion durante el login, la transaccion local conserva el
  token anterior.
- No existe una transaccion distribuida entre ambas bases: un fallo de commit
  local posterior a una escritura remota requiere reintentar el login.
- Las pruebas automatizadas usan `hotel_consumos_api_test` y no tocan los datos
  reales de `hotel_db` salvo en las pruebas de integracion SOAP.
