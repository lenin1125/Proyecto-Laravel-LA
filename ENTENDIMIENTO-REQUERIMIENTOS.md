# Entendimiento de los nuevos requerimientos

## Sistema de roles y permisos — Explicado en lenguaje sencillo

> Este documento resume, sin tecnicismos, lo que pidió la profesora, qué significa cada cosa y qué debemos decidir antes de programar.
> No se ha modificado el código del proyecto.

---

## 1. Resumen en una frase

La profesora quiere que el sistema tenga **cuatro tipos de empleados** (administrador, servicio de habitación, cajero y recepcionista), cada uno con su propio nivel de acceso, y que el sistema **bloquee automáticamente** lo que no le corresponde a cada uno.

---

## 2. La idea central: empleados, llaves y guardias

Imagina que la aplicación es un hotel con varias puertas:

- **La recepción** permite consultar la cuenta de una reserva.
- **La caja** permite cobrar un consumo.
- **La salida** permite verificar si la reserva puede hacer check-out.uhn
- **El servicio de habitación** permite registrar un consumo nuevo.

Cada empleado tiene una **llave** (el token) y un **cargo** (el rol). En cada puerta hay un **guardia** (el middleware) que revisa dos cosas:

1. ¿Trae una llave válida?
2. ¿Su cargo tiene permiso para entrar por esta puerta?

Si no trae llave, el guardia responde **401**.
Si trae llave pero su cargo no tiene permiso, responde **403**.

Eso es todo lo que pide la profesora: **poner guardias en las puertas y decirle a cada guardia qué cargos pueden pasar**.

---

## 3. Los cuatro perfiles

### 3.1 Administrador

Es el gerente. Puede hacer todo:

- Iniciar sesión.
- Registrar consumos.
- Consultar el estado de cuenta.
- Cobrar consumos.
- Verificar el check-out.

Además, puede consultar reservas en el sistema SOAP cuando registra un consumo o consulta una cuenta.

**En una frase:** tiene llave maestra para todas las puertas.

---

### 3.2 Servicio de habitación

Es el empleado que carga los consumos de la habitación (por ejemplo, el minibar).

Puede:

- Iniciar sesión.
- Registrar consumos.
- Consultar el estado de cuenta.

No puede:

- Cobrar.
- Verificar el check-out.

Puede consultar reservas en SOAP, porque lo necesita para registrar consumos y consultar cuentas.

**En una frase:** puede entrar a las habitaciones y anotar consumos, pero no puede cobrar ni hacer check-out.

---

### 3.3 Cajero

Es el empleado que cobra.

Puede:

- Iniciar sesión.
- Cobrar consumos.
- Verificar el check-out.

No puede:

- Registrar consumos.
- Consultar el estado de cuenta.

No debe tener acceso a SOAP. Como no puede registrar ni consultar, nunca llega a la parte que consulta reservas.

**En una frase:** puede cobrar y revisar la salida, pero no puede entrar a las habitaciones ni consultar la cuenta.

---

### 3.4 Recepcionista

Es el empleado que atiende la recepción.

Puede:

- Iniciar sesión.
- Consultar el estado de cuenta.
- Verificar el check-out.

No puede:

- Registrar consumos.
- Cobrar.

Puede consultar reservas en SOAP **solo cuando consulta el estado de cuenta**, porque necesita ver la habitación y el huésped.

**En una frase:** puede consultar la cuenta y revisar la salida, pero no puede cobrar ni cargar consumos.

---

## 4. Tabla rápida: quién puede hacer qué

| Acción | Administrador | Servicio de habitación | Cajero | Recepcionista |
|---|---|---|---|---|
| Iniciar sesión | Sí | Sí | Sí | Sí |
| Registrar consumo | Sí | Sí | No | No |
| Consultar estado de cuenta | Sí | Sí | No | Sí |
| Cobrar consumo | Sí | No | Sí | No |
| Verificar check-out | Sí | No | Sí | Sí |
| Consultar reservas en SOAP | Sí | Sí | No | Solo al consultar la cuenta |

> “Iniciar sesión” es la única acción que no requiere estar autenticado, porque es la que entrega la llave.

---

## 5. ¿Qué significan 401 y 403?

Son códigos que usa cualquier sistema web para decir “no puedes pasar”.

| Código | Cuándo ocurre | Qué significa para el usuario |
|---|---|---|
| **401 Unauthorized** | No enviaste token, o el token es inválido o venció | “No sé quién eres” |
| **403 Forbidden** | Enviaste un token válido, pero tu cargo no tiene permiso | “Sé quién eres, pero no puedes entrar aquí” |

La profesora pide que el 403 tenga **exactamente** este mensaje:

```json
{ "error": "Permisos insuficientes para ejecutar este método" }
```

Detalles importantes:

- La palabra clave debe ser **`error`**, no `message`.
- El mensaje debe llevar tilde en “método”.
- Debe devolverse **antes** de que la petición llegue al controlador o al servicio SOAP.

---

## 6. ¿Qué es un middleware, en palabras simples?

Es un **filtro o guardia** que se ejecuta antes de la acción principal.

Ejemplo:

1. Llega una petición para cobrar un consumo.
2. Primero pasa por el guardia de autenticación: ¿trae token válido?
3. Luego pasa por el guardia de rol: ¿es cajero o administrador?
4. Solo si pasa ambos, llega al controlador que hace el cobro.

Si el guardia de rol dice que no, la petición se detiene ahí y nunca se ejecuta el cobro ni se llama a SOAP.

La profesora pide **cuatro guardias**, uno por perfil:

| Guardia | Perfil |
|---|---|
| `IsAdministrator` | Administrador |
| `CanRegisterConsumptions` | Servicio de habitación |
| `CanProcessPayments` | Cajero |
| `CanCheckOut` | Recepcionista |

---

## 7. ¿Qué hay que cambiar en el sistema?

En palabras sencillas, hay que hacer cinco cosas:

### 7.1 Agregar el cargo al usuario

Hoy la tabla de usuarios no tiene un campo que diga “este usuario es cajero” o “este usuario es recepcionista”. Hay que agregar un campo **rol** con uno de estos cuatro valores:

- `administrador`
- `servicio_habitacion`
- `cajero`
- `recepcionista`

### 7.2 Crear los cuatro usuarios

Hay que crear o actualizar cuatro usuarios, uno por perfil, y asignarles su rol. Hoy el sistema tiene un usuario de prueba (`lenin@hotel.test`) que no tiene rol.

La “gestión manual de roles” significa que el rol se asigna **a mano**, desde la base de datos, un seeder o un comando. No pidieron una pantalla para administrar roles.

### 7.3 Crear los cuatro guardias

Son los middlewares. Cada uno revisa el rol del usuario y deja pasar solo a los cargos permitidos.

### 7.4 Poner cada guardia en su puerta

Hay que indicar qué guardia protege cada endpoint:

| Endpoint | Quién puede pasar |
|---|---|
| Registrar consumo | Administrador y servicio de habitación |
| Consultar estado de cuenta | Administrador, servicio de habitación y recepcionista |
| Cobrar consumo | Administrador y cajero |
| Verificar check-out | Administrador, cajero y recepcionista |

### 7.5 Actualizar las pruebas y Postman

- Las pruebas actuales crean usuarios sin rol. Después del cambio, esos usuarios recibirían 403. Hay que asignarles rol en las pruebas.
- Postman hoy usa un solo usuario. Habría que agregar los cuatro usuarios y probar cada combinación.

---

## 8. Los tres puntos que la profesora no definió

Estos son los puntos que conviene aclarar antes de programar. No son errores del código, son decisiones que faltan.

### 8.1 ¿“Usuario: administrador” es un correo o un nombre?

Hoy el login pide un **correo electrónico**. Si la profesora espera que el usuario se llame literalmente `administrador`, el login actual lo rechazaría porque no tiene formato de correo.

Hay dos caminos:

- **Camino fácil:** usar correos como `administrador@hotel.test`, `cajero@hotel.test`, etc., y explicar que “administrador” es el nombre del perfil.
- **Camino más largo:** agregar un campo de nombre de usuario y permitir iniciar sesión con `administrador` en lugar de un correo.

Esto también afecta a SoapUI, porque hoy el usuario de SOAP es el correo.

### 8.2 ¿Cómo se debe usar `IsAdministrator`?

El administrador puede hacer todo, pero **no hay ninguna acción que solo pueda hacer él**. Todas las acciones también las puede al menos otro perfil.

Eso significa que si ponemos el guardia `IsAdministrator` en una puerta compartida, bloquearíamos a los demás. Por eso hay tres opciones:

1. Usar `IsAdministrator` como base o guardia común de los otros middlewares, sin ponerlo en una puerta específica.
2. Agregar una acción exclusiva de administrador (por ejemplo, administrar roles) para que ahí sí se use.
3. Preguntarle a la profesora cómo espera que se demuestre.

Es importante aclararlo porque, tal como está escrito el requerimiento, no encaja de forma directa con las puertas compartidas.

### 8.3 ¿La restricción de SOAP debe ser real?

Aquí hay un detalle importante:

- Laravel puede bloquear al cajero para que no registre ni consulte.
- Pero el servidor SOAP es **otro sistema**, con su propia puerta.
- Hoy ese servidor solo revisa que el usuario y el token sean válidos; **no revisa el rol**.
- Además, el token del cajero se guarda en la base de datos de SOAP cuando inicia sesión.

Consecuencia: aunque Laravel bloquee al cajero, el cajero podría tomar su token e ir **directamente** al servidor SOAP con Postman o SoapUI, y consultar reservas. Eso contradice “método SOAP permitido: ninguno”.

Opciones:

- **Opción A:** aceptar que la restricción aplica solo a lo que se hace a través de la API Laravel. Es lo más sencillo, pero queda el hueco.
- **Opción B:** no guardar el token del cajero en la base de datos de SOAP. Así el cajero no tiene llave para ese sistema.
- **Opción C:** agregar el rol a la base de datos de SOAP y validarlo en el servidor SOAP. Es lo más correcto, pero toca el proyecto externo `C:\laragon\www\hotel`.

Para la materia, probablemente baste la Opción A, pero conviene preguntar.

---

## 9. Errores fáciles de cometer

Estos son los tropiezos más comunes al implementar esto:

### 9.1 Poner el guardia de rol antes del guardia de token

Si el guardia de rol se ejecuta primero y no hay token, el usuario recibiría **403** en vez de **401**. La profesora pide 401 cuando falta el token.

**Regla:** primero se valida el token, después el rol.

### 9.2 Usar `abort(403)`

Laravel, con `abort(403)`, devuelve:

```json
{ "message": "..." }
```

Pero la profesora pide:

```json
{ "error": "Permisos insuficientes para ejecutar este método" }
```

Hay que devolver la respuesta manualmente con la clave `error`.

### 9.3 Intentar poner dos guardias de rol en la misma puerta

Laravel exige que **todos** los guardias de una misma puerta digan que sí. No funciona como “si uno dice que sí, pasa”.

Si se ponen dos guardias de rol en la misma ruta, un empleado que tenga permiso por uno pero no por el otro será bloqueado. Por eso conviene que cada puerta tenga **un solo guardia** que conozca la lista completa de roles permitidos.

### 9.4 Crear la misma ruta dos veces para dos roles

Si se define la misma dirección dos veces, Laravel se queda con la última y la primera deja de funcionar. No es una forma segura de dar permisos a varios roles.

### 9.5 Dejar usuarios sin rol

Si se agrega el campo rol pero no se asigna a los usuarios existentes, esos usuarios recibirán 403 en todo. Hay que asignarles rol antes de probar.

### 9.6 Olvidar actualizar las pruebas y Postman

Las pruebas actuales y la colección de Postman usan un solo usuario sin rol. Después del cambio, van a fallar hasta actualizarlas.

---

## 10. Lo que recomiendo

Mi recomendación, en lenguaje sencillo:

1. **Hacer una tabla central de permisos.** Una sola lista que diga qué roles pueden entrar a cada puerta. Así no se repite la lógica en cuatro lugares.
2. **Un guardia por puerta, no uno por rol.** El guardia consulta la tabla y deja pasar si el rol está en la lista. Esto resuelve el problema de las puertas compartidas.
3. **Mantener las direcciones actuales** (`/api/consumos`, etc.) para no romper Postman ni SoapUI.
4. **Crear los cuatro usuarios con roles claros** y actualizar el comando de creación de usuarios para que reciba el rol.
5. **No tocar el servidor SOAP** hasta que la profesora confirme si quiere la restricción real.
6. **Hacer primero las pruebas de la matriz** (los 4 roles contra las 4 acciones) para demostrar que funciona.

---

## 11. Preguntas que conviene hacerle a la profesora

1. ¿“Usuario: administrador” es el correo `administrador@hotel.test` o el nombre literal `administrador`?
2. ¿Qué contraseña debe tener cada usuario?
3. ¿El usuario actual `lenin@hotel.test` se conserva? ¿Con qué rol?
4. ¿Las rutas `/api/me` y `/api/logout` quedan disponibles para todos los roles autenticados?
5. ¿Cómo espera que se use `IsAdministrator`, si el administrador no tiene una acción exclusiva?
6. ¿La restricción de SOAP debe ser real en el servidor SOAP, o basta con bloquear los endpoints de Laravel?
7. ¿Qué rol debe tener un usuario nuevo o uno que no tenga rol asignado?
8. ¿El login debe devolver el rol y el token de SOAP para todos, incluido el cajero?
9. ¿La “gestión manual de roles” es solo base de datos, seeder o comando, o también quieren una pantalla o endpoint para cambiar roles?
10. ¿Se mantienen las direcciones actuales o se renombran a `/api/registrarConsumo`, `/api/consultarEstadoCuenta`, etc.?

---

## 12. Plan de trabajo sugerido

| Paso | Qué se hace |
|---|---|
| 1 | Aclarar las preguntas del punto 11 |
| 2 | Agregar el campo rol a los usuarios |
| 3 | Crear los cuatro usuarios con su rol |
| 4 | Actualizar el comando de creación de usuarios para aceptar el rol |
| 5 | Crear los guardias (middlewares) |
| 6 | Poner cada guardia en su endpoint |
| 7 | Probar los 4 roles contra las 4 acciones |
| 8 | Probar que sin token da 401 y con rol incorrecto da 403 |
| 9 | Actualizar Postman y la documentación |
| 10 | Si se autoriza, endurecer la seguridad de SOAP |

---

## 13. Glosario sencillo

| Palabra | Qué significa |
|---|---|
| **Rol** | El cargo del usuario: administrador, servicio de habitación, cajero o recepcionista. |
| **Token** | La llave que entrega el login y que el usuario envía en cada petición. |
| **Bearer** | La forma de enviar la llave: `Authorization: Bearer <token>`. |
| **Endpoint** | Una dirección de la API, por ejemplo `/api/consumos`. |
| **Middleware** | Un guardia que revisa algo antes de dejar pasar la petición. |
| **401** | No hay llave o la llave no es válida. |
| **403** | La llave es válida, pero el cargo no tiene permiso. |
| **SOAP** | El sistema externo donde están las reservas, habitaciones y huéspedes. |
| **Postman** | Herramienta para probar la API manualmente. |
| **Seeder** | Un archivo que crea datos iniciales, como los cuatro usuarios. |
| **Migración** | Un archivo que cambia la estructura de la base de datos, como agregar la columna rol. |

---

## 14. Chuleta final

- **Administrador:** todo.
- **Servicio de habitación:** registrar y consultar.
- **Cajero:** cobrar y verificar check-out.
- **Recepcionista:** consultar y verificar check-out.
- **Sin token:** 401.
- **Con token pero sin permiso:** 403 con `{"error": "Permisos insuficientes para ejecutar este método"}`.
- **El rol se lee del usuario autenticado**, no de lo que venga en la petición.
- **Primero se valida el token, después el rol.**
- **Un guardia por puerta debe conocer todos los roles permitidos.**
- **Falta aclarar:** nombre de usuario, uso de `IsAdministrator` y alcance real de la restricción SOAP.

---

## 15. Lo que todavía no se ha hecho

- No se ha modificado ningún archivo del proyecto.
- No se ha creado ningún usuario nuevo.
- No se ha tocado la base de datos.
- No se ha modificado el servidor SOAP.

Este documento es solo para entender y acordar el alcance antes de programar.
