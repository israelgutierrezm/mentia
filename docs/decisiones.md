# MENTIA — Bitácora de decisiones

Dónde se anotan las resoluciones de ambigüedades de la spec y las desviaciones
del diseño canónico, con su porqué. **Léelo antes de cuestionar algo que
parezca raro.**

Lo que NO va aquí: decisiones que la spec ya toma (eso está en `/docs/01`–`08`)
ni convenciones de escritura (eso está en `convenciones.md`).

---

## Fase 0 — Fundación

### La plantilla `admin-base` no existía

El Doc 02 §8 la describe como "la fundación administrativa reutilizable ya
planeada". Se buscó en los siete proyectos del ecosistema (`C:\Dev`) y no hay
repo ni una sola referencia: estaba **planeada**, no construida.

El shell administrativo se construyó aquí siguiendo las convenciones de la casa
—mismo stack que `acadion`: Laravel 12 + Inertia 3 + Vue 3 + Vite 7 +
Tailwind 4—. Si la plantilla real aparece después, lo que hay que reconciliar es
el layout, no la arquitectura.

### Se almacena en UTC, no en `America/Mexico_City`

México no tiene una sola hora: Tijuana y Mérida están a dos, y Baja California
sigue moviendo el reloj mientras el resto del país ya no. Con cronómetros
server-side y sellos de tiempo por reactivo (Doc 02 §7), un instante ambiguo no
se repara después.

La zona de presentación es un atributo de la organización
(`organizaciones.zona_horaria`) y la API entrega ISO 8601 **con** zona, así que
el cliente nunca adivina.

**Ojo:** `APP_TIMEZONE` en `.env` no hace nada en Laravel 12 — el valor está en
duro en `config/app.php`. Lo descubrió una prueba.

### Errores de la API en RFC 7807 desde la Fase 0

El Doc 07 lo pide en su encabezado. Se implementó antes de que hubiera
endpoints reales (`App\Http\Api\Problema`) porque es transversal: si el
envoltorio de error cambia de forma después de que la app Flutter y los ATS ya
integraron, el costo lo pagan ellos.

### `spatie/laravel-activitylog` en 4.12, no 5.x

La 5.1 exige PHP 8.4 y aquí corre 8.3. Al subir PHP se puede actualizar.

### `config/media-library.php` referencia `TemporaryUpload` como cadena

Media Library Pro no está instalado y la clase no existe. El valor de
configuración es idéntico y el análisis estático deja de tropezar con un
símbolo que nadie puede resolver.

### Las pruebas corren en MySQL desde el primer día

Aunque en la Fase 0 todavía no hubiera migraciones que lo exigieran. Cambiar de
motor a medio proyecto es lo que obliga a reescribir migraciones, y el esquema
del Doc 03 usa CHECK constraints, llaves foráneas reales e índices compuestos
que SQLite no sabe hacer.

---

## Fase 1 — Organizaciones, personas y accesos

### La relación persona ↔ cuenta va en UNA sola dirección

**Ambigüedad del Doc 03 §M2.** El diccionario declara las dos:
`personas.usuario_id` (FK → users, nullable) y `users.persona_id` (FK). Es un
1:1 guardado dos veces: dos columnas que pueden decir cosas distintas y nada en
la base lo impide.

**Resuelto con el cliente:** se implementa **sólo `users.persona_id`**, NOT
NULL y con índice único. Eso expresa el 1:1 completo, y `Persona::usuario()` lo
lee al revés con un `hasOne`. Desde el código se ve igual; en la base no hay dos
fuentes de verdad que puedan divergir.

Es la única desviación del diccionario en esta fase.

### El global scope de tenant FALLA CERRADO

Sin organización activa, `AlcanceOrganizacion` no devuelve nada
(`whereRaw('1 = 0')`), en vez de no filtrar.

La alternativa produce la fuga clásica: un comando de consola, un job mal armado
o una ruta a la que se le olvidó el middleware devuelven los datos de **todos**
los tenants, y nada falla ni se ve raro hasta que un cliente encuentra en su
pantalla a los alumnos de otra escuela. Una lista vacía se nota el primer día;
una fuga, no.

Lo que legítimamente necesita ver todo —seeds, catálogo global, mantenimiento—
lo pide explícitamente con `ContextoOrganizacion::sinRestriccion()`.

### `ContextoOrganizacion` fija el scope y el team de Spatie a la vez

Son dos mecanismos distintos (global scopes de Eloquent y `team_id` de Spatie) y
viven en un solo lugar a propósito. Hacer sólo uno resolvería los roles contra
un tenant y los datos contra otro, y eso no produce un error visible: produce
decisiones de autorización correctas sobre los datos equivocados.

### La bitácora NO lleva llaves foráneas

`bitacora.organizacion_id`, `actor_persona_id` y `persona_afectada_id` son
`unsignedBigInteger` sin constraint.

La bitácora sobrevive a lo que registra: si se borra una organización o se
ejerce una cancelación ARCO sobre una persona, el rastro de quién accedió a qué
**no** puede irse en cascada — es justo la evidencia que la LFPDPPP obliga a
conservar. Con FK, el borrado se llevaría la prueba o quedaría bloqueado para
siempre.

`proposito_id` tampoco la lleva, pero por otra razón: `propositos` nace en M6
(Fase 5). Ahí se puede agregar.

### `bitacora` no usa `creado_en` / `actualizado_en`

Una fila de bitácora se escribe una vez y nunca cambia, así que una columna
`actualizado_en` sería una promesa falsa. El instante va en `registrado_en`
con `datetime(3)`: un lote de respuestas produce varias decisiones en el mismo
segundo y el orden importa para reconstruir qué pasó.

### El tope de sensibilidad sale del rol que TRAE el permiso

No del rol más alto que tenga la persona. Una psicóloga que además es docente no
debe ver un resultado clínico ejerciendo el rol docente, pero tampoco perder su
nivel 4 por tener el otro rol encima.

Un rol sin fila en `rol_sensibilidad_max` se queda en nivel 1: falla cerrado.

### El titular y el tutor no pasan por la comprobación de permiso

La mayoría de las personas del sistema no tienen ningún rol asignado —un niño de
preescolar tamizado por M-CHAT no inicia sesión ni tiene rol—, así que exigirles
un permiso cerraría el portal de autollenado a todo el mundo. Su acceso es
implícito en `AccesoService`: el titular sobre sí mismo, el tutor **vigente**
sobre su tutelado.

"Vigente" hace todo el trabajo: una tutoría en `pendiente_validacion` no da
acceso. Quien se registra declarando ser la madre no acredita nada hasta que un
profesional lo valida.

### El consentimiento "pendiente" DEJA PASAR, y queda marcado

El Doc 08 pide la verificación de consentimiento como interfaz con
implementación provisional que retorna pendiente. `EstadoConsentimiento::Pendiente`
permite continuar — si negara, nada funcionaría en la Fase 1.

Lo que hace defendible esa compuerta abierta: cada acceso concedido así se
escribe en bitácora con **motivo propio** ("consentimiento pendiente de
verificación"). Al conectar la verificación real en la Fase 2 se puede
responder exactamente qué se consultó sin comprobarla, que es lo que
preguntaría una auditoría de la LFPDPPP.

`ConsentimientoPendiente` se sustituye entera; no se le agrega lógica.

### Las plantillas de rol se CLONAN, no se apuntan

Si los roles de un tenant apuntaran a la plantilla global, corregir una
plantilla cambiaría los permisos efectivos de todos los tenants en producción
sin que ninguno lo pidiera. En un sistema donde un permiso decide quién ve un
resultado clínico, eso no puede pasar por un despliegue.

Consecuencia asumida: los roles clonados son **borrables y editables** por la
organización. Ningún código debe buscarlos por nombre.

### Los permisos se declaran en código, no en la base

`App\Domain\Accesos\CatalogoPermisos`. Un permiso es una llave que el código
consulta, y una llave que nadie consulta no protege nada. Lo que el tenant
configura son sus roles.

`plantilla_rol_permisos.permiso` guarda el **nombre**, no una FK a
`permissions`: con FK, sembrar plantillas exigiría que los permisos existieran
en ese orden exacto, y renombrar uno dejaría filas apuntando a un id que ya
significa otra cosa. El clonador filtra contra el catálogo antes de asignar.

### El `can:` de Laravel resuelve contra la PERSONA

Los roles cuelgan de `personas`, no de `users` (Doc 03 §M3), así que el
`permission:` de Spatie —que mira el modelo autenticado— daría siempre false y
todas las pantallas responderían 403. Lo resuelve un `Gate::before` en
`AppServiceProvider`, que sólo intercepta las llaves del catálogo: devolver
null para el resto deja vivas las policies y los gates propios.

### `personas` y `tipos_agrupacion` no llevan global scope

- `personas` es **global** por diseño (principio P1): el expediente acompaña a
  la persona entre organizaciones. El acotamiento entra por
  `organizacion_personas`, que sí es de tenant. Por eso los listados paginan
  sobre las vinculaciones y nunca sobre `personas` directamente, y por eso todo
  endpoint que reciba un `persona_uuid` comprueba la vinculación activa.
- `tipos_agrupacion` con `organizacion_id` NULL son plantillas del sistema
  visibles para todos; el scope las escondería. Se acota con
  `scopeDisponiblesPara()`.

### `agrupacion_miembros` no lleva `organizacion_id`

El diccionario no se lo da. Se acota siempre por su agrupación, que sí lo lleva.
Consecuencia: cualquier consulta que llegue a esa tabla sin pasar por
`agrupaciones` sería una fuga, y por eso hay pruebas que lo intentan.

### Conocido y aceptado: 403 y 404 se distinguen en la ficha de una persona

Pedir una persona inexistente da 404 (route model binding) y pedir una que
existe pero está fuera de alcance da 403. En estricto sentido eso confirma que
el uuid corresponde a alguien.

Se acepta porque el uuid no es adivinable —hacia afuera nunca viaja el id— así
que para explotarlo hay que tener ya el uuid. El **mensaje** sí es uniforme en
los dos casos. Si en la Fase 9 (endurecimiento) se decide cerrarlo, la forma es
que la negación de `AccesoService` responda 404 en recursos de persona.

### Índices con nombre explícito cuando pasan de 64 caracteres

MySQL no admite identificadores más largos y la migración revienta **al crear el
índice**, no al declararlo. Mordió con
`persona_rol_alcances_persona_id_organizacion_id_vigencia_fin_index` (66).

### Una prueba de aislamiento pasaba por la razón equivocada

`test_el_encabezado_x_organizacion_no_sirve...` pasaba **igual** con la
comprobación de vinculación del middleware quitada: el 403 lo daba el `can:`,
porque el actor tampoco tenía el permiso en el otro tenant. Se descubrió mutando
el middleware a propósito.

Reescrita: ahora el actor tiene rol y permiso en B pero **sin vínculo activo**
—el caso real de quien fue dado de baja y a quien nadie le limpió los roles—, y
la mutación la tumba. Es el recordatorio de por qué la regla de mutar existe.
