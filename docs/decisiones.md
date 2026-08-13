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

### Un rol con alcances vivos NO se borra

`persona_rol_alcances.rol_id` tiene FK en cascada: borrar el rol se llevaría en
silencio el registro de quién tenía acceso a qué. `GestorRoles::eliminar()` lo
bloquea y pide retirar los alcances primero, que es una acción visible y
auditable.

### Salvaguarda de auto-encierro al editar un rol

No puedes quitarle `roles.gestionar` al rol con el que estás trabajando. Si se
permitiera, quien edita se queda sin forma de volver a esa pantalla — y si era
el único rol con el permiso, la organización pierde la administración de roles
y sólo se repara desde la consola.

### Nadie valida su propia tutoría

Una tutoría vigente abre el expediente psicológico de un menor a otra persona.
El registro nace `pendiente_validacion` y **no da acceso**; la acreditación es
un acto separado de alguien con `tutorias.validar`, que queda con nombre en
`validada_por`.

Sin la regla de que el validador no puede ser el propio tutor, el autorregistro
sería una puerta abierta: cualquiera declara ser la madre de un menor, se valida
solo y se lleva su expediente.

Re-registrar una tutoría revocada **reescribe la misma fila** (vuelve a
pendiente) en vez de crear otra: el único de (tutor, menor) lo impide, y
borrarla para re-acreditar a la misma madre destruiría historia.

### Los permisos se validan contra el catálogo del CÓDIGO, no contra la tabla

`GuardaRolRequest` usa `Rule::in(CatalogoPermisos::claves())`. Si alguien sembró
de más en `permissions`, ese permiso no lo consulta nadie: concederlo no protege
nada y sólo confunde a quien configura el rol.

### Una prueba de aislamiento pasaba por la razón equivocada

`test_el_encabezado_x_organizacion_no_sirve...` pasaba **igual** con la
comprobación de vinculación del middleware quitada: el 403 lo daba el `can:`,
porque el actor tampoco tenía el permiso en el otro tenant. Se descubrió mutando
el middleware a propósito.

Reescrita: ahora el actor tiene rol y permiso en B pero **sin vínculo activo**
—el caso real de quien fue dado de baja y a quien nadie le limpió los roles—, y
la mutación la tumba. Es el recordatorio de por qué la regla de mutar existe.

---

## Fase 2 — Expediente y consentimientos

### El catálogo de opciones de los campos no estaba definido

**Ambigüedad del Doc 03 §M4.** El diccionario referencia
`expediente_campos.catalogo_opciones_id` y `expediente_valores.valor_opcion_id`,
pero nunca dice dónde viven las opciones.

Resuelto con dos tablas: `catalogos_opciones` (el catálogo: escolaridad, tipo de
sangre) y `opciones_catalogo` (sus filas). Se parten en dos porque el mismo
catálogo lo usan varios campos, y duplicar sus opciones por campo es como
terminan divergiendo. Una opción se **apaga**, no se borra: sigue estando en los
valores históricos que la eligieron.

### El titular y el tutor no pasan por la compuerta de consentimiento

Salió al conectar la verificación real: diez pruebas de la Fase 1 se pusieron en
rojo y una de ellas señalaba un error de diseño, no de la prueba.

El consentimiento protege a la persona de **terceros**. Exigírselo a ella misma
es pedirle que se autorice ante sí misma, y al tutor es circular: es **quien
otorga** el consentimiento en nombre del menor, así que no podría leer aquello
sobre lo que tiene que decidir. Sin esta salida, el primer efecto de la Fase 2
habría sido dejar a todo el mundo fuera de su propio expediente.

### Una compartición vigente mete a la persona en el alcance del tenant destino

Segundo hallazgo del mismo tipo. La compartición cross-tenant no servía de nada:
la persona no está vinculada al destino, así que fallaba la **dimensión 2**
(alcance) y la compuerta de consentimiento ni llegaba a preguntarse.

Ahora `enLaOrganizacion()` acepta vínculo activo **o** compartición vigente.
Sólo aplica al alcance de organización completa: un alcance por unidad o
agrupación sigue exigiendo membresía, y una persona compartida no está en ningún
grupo del destino.

### El estado `Pendiente` del consentimiento se conserva en el enum

Ya nadie lo devuelve —`ConsentimientoPendiente` fue sustituido— pero la bitácora
de la Fase 1 tiene registros que lo citan, y borrar el caso volvería ilegibles
esas filas.

### Los jobs nocturnos NO son lo que protege el acceso

`Consentimiento::estaVigente()` comprueba las fechas en vivo, así que un
consentimiento vencido a medianoche deja de amparar en la siguiente petición
aunque el job no haya corrido. Los jobs existen para que el **estado** que ve la
persona coincida con la realidad y para poder consultar "qué venció" sin
recalcularlo.

Confiar sólo en el job sería el error: una corrida fallida abriría accesos.

### La transición de mayoría de edad CREA el expediente si no existe

El expediente nace la primera vez que alguien captura algo, así que una persona
tamizada pero sin captura no tiene fila. Un `update` no habría afectado a nadie
y el bloqueo habría sido un no-op silencioso — justo en el caso que la ley
obliga a bloquear. Lo cazó una prueba.

### El desbloqueo es consecuencia de consentir, no un botón

Si fuera un paso manual, habría expedientes bloqueados para siempre porque a
nadie se le ocurrió apretarlo después de que la persona ya re-consintió.

### PHPStan retiene el tipo entre llamadas idénticas dentro de un método

`assertNull($servicio->valorVigenteDe(...))` seguido de otra llamada idéntica
hace que PHPStan infiera `null` en la segunda. La solución no fue forzar el
tipo: se partió la prueba en tres —una conducta cada una— y aparecieron dos
casos que la original no cubría (que el vigente sea la mayor versión
**validada**, y que una corrección sin validar no desplace al dato bueno).

---

## Fase 3 — Catálogo de instrumentos

### Categorías y subcategorías en una sola tabla

El Doc 03 §M5 las nombra por separado pero les da el mismo esquema con
`padre_id nullable`. Dos tablas idénticas obligarían a duplicar cada consulta y
a decidir en cada punto cuál mirar.

### Lo que se congela al publicar es el CONTENIDO, no el estado

`PublicadorVersion::exigirEditable()` es la comprobación que todo servicio de
contenido debe hacer. Impedir que cambie el enum `estado` no protegería nada:
lo que rompe la reproducibilidad de una aplicación de hace dos años es que
cambien sus reactivos, sus claves o sus baremos.

Publicar valida además que la versión sirva para algo: sin reactivos, con una
escala que nunca puntúa o con una fórmula que cita una escala inexistente, cada
caso produce una aplicación que falla delante de la persona evaluada en vez de
fallar al publicar.

### Las fórmulas derivadas se validan con lista blanca, nunca con eval()

Van sobre CLAVES de escala y se comprueban al publicar con una expresión
regular que sólo admite identificadores, números, `+ - * /` y paréntesis, más
la verificación de que cada identificador sea una escala de esa versión.

Las expresiones llegan de una hoja de Excel que sube un tenant. Evaluarlas con
`eval()` sería ejecución remota de código servida desde un archivo adjunto. Hay
prueba con `system("rm -rf /")`.

### Corregir una versión publicada es clonarla

`ClonadorContenidoVersion` copia escalas, bloques, reactivos, opciones, claves,
fórmulas, baremos e interpretaciones a un borrador nuevo. Sin esto, una errata
obligaría a recapturar el instrumento entero y la presión por editar la
publicada sería irresistible. El clon **conserva el ámbito del contenido
privado**.

### La ficha del catálogo nunca incluye reactivos

Sólo su CONTEO. Un endpoint de catálogo que devolviera los enunciados completos
sería la forma más cómoda de descargarse una prueba con copyright; el contenido
sale parcelado durante la aplicación (Doc 06 §3).

### Declarar licencia deja `pendiente_contenido`, no `habilitado`

Declarar no pone los reactivos. Habilitarlo ahí dejaría asignable una prueba
vacía y alguien se sentaría a contestar una pantalla en blanco.

Se guarda el TEXTO firmado, quién y cuándo — no un booleano. Ante una
reclamación editorial, "el tenant marcó una casilla" no es defensa.

### El importador valida todo antes de escribir nada

Transacción con rollback ante un solo error. Una importación a medias deja un
instrumento con reactivos y sin claves: publica, se asigna y puntúa cero sin que
nadie lo note. Es peor que no haber importado.

Las referencias de la plantilla van por CLAVE, no por id: la llena una persona
en Excel, y pedirle ids obligaría a importar por pasos copiando números entre
hojas.

**Bug que mordió:** el importador reventaba con "Undefined array key" cuando la
plantilla no traía una columna opcional —lo normal, quien captura borra las que
no usa—. Eso convertía el reporte fila a fila en un error fatal. Todas las
columnas opcionales se leen ahora con un lector tolerante.

### Una prueba del envoltorio de error se rompió dos veces por apuntar a rutas reales

`test_el_detalle_de_un_404_no_confirma_si_el_recurso_existe` apuntaba primero a
`/api/v1/personas/{uuid}` y luego a `/api/v1/catalogo/...`. Cada vez que esa
ruta se implementó, la prueba empezó a medir un 401 de autenticación en vez de
un 404. Ahora apunta a una ruta que ninguna fase va a definir.

---

## Fase 5 — Asignaciones y baterías

### El CHECK de instrumento XOR batería vive en la BASE

No sólo en el servicio. Una asignación con los dos —o con ninguno— es algo que
el motor de aplicación de la Fase 6 no sabría presentar, y un INSERT que se
salte el dominio tampoco puede crearla. Hay prueba de las dos capas: el servicio
explica qué pasó, la base impide que ocurra.

Se agregó un segundo CHECK, `ventana_fin > ventana_inicio`: una ventana
invertida deja a todo el mundo fuera sin que nada lo explique.

### Los tokens se guardan hasheados y expiran con la VENTANA

Tres decisiones que se sostienen entre sí:

- **Hash SHA-256, no el token.** Quien lea la base —un respaldo, un volcado,
  una consulta de soporte— no debe poder contestar en nombre de nadie. El token
  en claro sólo existe el instante en que se manda. Se usa SHA-256 y no bcrypt
  porque el token ya son 32 bytes aleatorios: no hay nada que reforzar, y bcrypt
  obligaría a recorrer la tabla comparando fila por fila en vez de buscar por
  índice.
- **Un solo uso, con `lockForUpdate` al canjear.** Sin el bloqueo, dos
  peticiones con la misma liga reenviada pasarían las dos la comprobación de
  "todavía no se ha usado" antes de que ninguna lo marcara.
- **Expiran con la ventana de su asignación**, no con un plazo propio. Cerrar la
  asignación los invalida al instante; sin eso, una liga enviada hace tres días
  seguiría abriendo la evaluación después del cierre.

`resolver()` devuelve null en todos los casos de fallo sin distinguirlos. Un
mensaje que dijera "ese token ya se usó" le confirmaría a quien prueba tokens al
azar que acertó uno.

### Una discreta ajena responde 404, no 403

Un 403 confirmaría que ese folio existe, y la existencia de la asignación es
justo lo que la discreción protege: en el caso clínico, que el área sepa que hay
una evaluación asignada a alguien ya es una filtración, aunque nadie vea el
resultado.

### El avance de una anónima da sólo conteos

`destinatariosDetallados()` lanza excepción y la API responde 409 con el
agregado. No es una decisión de interfaz: saber quién ya contestó y quién no
permite deducir de quién es cada respuesta en un centro de trabajo chico, y eso
destruye el anonimato que hace que la gente conteste con la verdad.

### La expansión dinámica va por EVENTO, no por llamada directa

`GestorAgrupaciones` anuncia `PersonaInscritaEnAgrupacion` y Evaluaciones lo
escucha. Organizaciones no tiene por qué saber que existen las asignaciones.

El listener agrega **sólo a quien entró**, no rebarre el grupo: inscribir a
treinta alumnos no puede costar treinta barridos. `expandirDinamica()` existe
aparte como reconciliación manual, y es idempotente.

### El Mailable de invitación no se encola

Lleva el token en claro. Encolarlo lo dejaría escrito en la tabla `jobs` y en
los paneles de Horizon. Quien quiera mandar trescientas en segundo plano encola
el envío completo —que genera los tokens dentro del job— no este objeto.

El correo tampoco dice qué instrumento es: un asunto que diga "Contesta tu
PHQ-9" revela una evaluación de salud mental a quien mire la bandeja de entrada
por encima del hombro.

### Notificar regenera el token

Es consecuencia de que el claro sólo exista al generarse. Un recordatorio deja
inservible el correo original, lo cual es correcto —una liga vieja circulando es
una liga que alguien más puede usar— y por eso el texto del correo lo advierte.

### El propósito sólo rellena instrumento o batería cuando no se pidió ninguno

Rellenar cada uno por separado producía un choque contra el CHECK XOR: pedir
explícitamente una batería, contra un propósito que trae instrumento por
omisión, dejaba los dos puestos. Quien especifica uno está diciendo justamente
que no quiere el default del otro. Lo cazó la prueba de reordenamiento con
asignaciones activas.

### Una batería sólo admite lo que la organización puede aplicar

`GestorBaterias` rechaza instrumentos no habilitados y los de `solo_captura`.
Una batería con un instrumento apagado se arma sin protestar y revienta al
asignarla, delante de la persona que iba a contestarla.

Y una batería con asignaciones activas NO se reordena: cambiar el orden a media
campaña haría que dos personas contestaran la misma batería en secuencias
distintas, y el orden afecta al resultado por fatiga y aprendizaje entre
instrumentos. Para cambiarla se archiva y se crea otra.

El reordenamiento recibe el orden COMPLETO y exige que sea una permutación del
actual: una lista parcial dejaría posiciones viejas mezcladas con las nuevas.

### Los recordatorios tienen cadencia, tope y una excepción

Tres reglas porque el recordatorio molesta:

- **Cadencia mínima** de dos días. Un sistema que insiste todos los días se gana
  que lo marquen como spam, y entonces tampoco llega la invitación de la
  siguiente campaña.
- **Tope de tres.** Quien no contestó tras tres avisos no va a contestar por un
  cuarto; lo que hace falta ahí es que alguien lo llame.
- **El último día se salta la cadencia.** Es la única insistencia que sirve de
  verdad, porque después ya no hay nada que hacer.

El job corre dentro de `ContextoOrganizacion::sinRestriccion()`: los global
scopes fallan cerrado, así que sin eso no vería ninguna asignación y no mandaría
nada **en silencio**.

### Una prueba dependía de la hora a la que se corriera la suite

`test_el_ultimo_dia_se_salta_la_cadencia` usaba `startOfDay()->addHours(10)`
como "último día". Si la suite corría antes de las diez de la mañana, ese
instante quedaba después de `ventana_fin` y la ventana ya estaba cerrada: la
prueba fallaba por la hora, no por el código. Ahora usa
`ventana_fin->subMinutes(5)`.
