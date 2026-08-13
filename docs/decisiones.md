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

## Fase 6 — Motor de aplicación

### El contenido se entrega parcelado y el cronómetro es del servidor

Las dos reglas que gobiernan `MotorAplicacion`. `iniciar()` devuelve la
estructura —cuántos bloques, cuántos reactivos, cuánto tiempo— y ningún
enunciado; los reactivos se piden bloque por bloque con tope de 50. Un endpoint
que devolviera el instrumento completo sería la forma más cómoda de descargarse
una prueba con copyright.

El cronómetro se calcula desde `aplicacion_bloques.iniciado_en` y el cliente
sólo lo muestra. Si el cliente llevara la cuenta, cambiar la hora del sistema
bastaría para tener el doble de tiempo — y en una prueba de velocidad el tiempo
**es** el constructo.

El reloj del bloque arranca cuando se piden sus reactivos, no al iniciar la
aplicación: si no, una batería de cuatro instrumentos consumiría el tiempo de
los cuatro a la vez.

### Los saltos se resuelven en el servidor

`ResolutorSaltos` filtra qué reactivos son visibles antes de entregar el tramo.
Mandarle el árbol de reglas al cliente le entregaría el mapa del instrumento.

### Los centinelas se evalúan síncronos, dentro de la petición del lote

Es la diferencia entre enterarse de una ideación suicida ahora, con la persona
todavía en la pantalla, o mañana cuando la cola termine de calificar. Todo lo
demás del pipeline sí es asíncrono.

### El token vuelve a entrar mientras la aplicación sigue en curso

La regla de "un solo uso" que se escribió con el token protege del reenvío por
WhatsApp y castiga el caso mucho más común: quien cierra la pestaña a la mitad y
vuelve a picar la liga del correo. Con la regla estricta, cada cierre accidental
abandonaba un instrumento a medias y nadie sabía por qué.

Ahora `tokenVigente()` deja pasar un token ya canjeado **mientras el
destinatario está `en_curso`**. Lo que el token no puede hacer nunca es abrir un
intento nuevo: al completarse la aplicación el estado pasa a `completada` y el
token muere. `token_usado_en` conserva la fecha del primer canje, que es la que
sirve en la bitácora.

Lo destapó construir la pantalla: `canjear()` quemaba el token **antes** de que
`iniciar()` corriera, así que un fallo al arrancar dejaba a la persona fuera de
su propia evaluación sin recurso.

### La liga lleva el token en el fragmento, no en la ruta

`/contestar#<token>` y no `/contestar/<token>`. Lo que va después de `#` no se
manda al servidor: no aparece en el log de accesos del servidor web, ni en el
proxy corporativo, ni en el `Referer` de ninguna liga que salga de la página.
Con el token en la ruta, la credencial de quien contesta un tamizaje clínico
queda escrita en texto plano en cada capa por la que pasa la petición.

La página lo lee del navegador, lo canjea por POST y lo borra de la barra de
direcciones con `replaceState`. La captura a mano del código sigue existiendo
porque un reescritor de ligas corporativo puede comerse el fragmento.

### Cambiar de respuesta corrige la fila; no agrega otra

La migración de `respuestas` ya encargaba esta comprobación al servicio y el
servicio no la hacía. El único de la base es `(aplicacion, reactivo, opcion)`,
así que quien marcaba "Nunca" y se corregía a "Siempre" dejaba **las dos filas**
y el pipeline habría sumado el mismo reactivo dos veces: un puntaje inflado sin
que nada se viera roto. La gente cambia de respuesta todo el tiempo; la pantalla
de contestar convierte esto en el caso normal.

En ranking e ipsativos no se corrige fila por fila: la respuesta es el
**conjunto**. Cambiar cuál opción es la que "más" describe dejaría la anterior
marcada y el cuadro tendría dos «más», que no puntúa; y reordenar un ranking
chocaría contra el único de la base a medio camino. Se borra el conjunto
anterior una vez por lote y se escribe completo.

En ambos casos gana la más reciente **por `respondida_en`**, no por orden de
llegada: en el modo offline de la V3 los lotes se sincronizan desordenados, y
sin esa regla un paquete viejo que llega tarde deshace una corrección posterior.

### Dos límites de tasa distintos en el motor

El canje es adivinable —por ahí entra quien prueba tokens al azar— y va
estrangulado a 30 por minuto. El resto de los endpoints exige ya el uuid de una
aplicación existente, y estrangularlos igual sacaba a quien contesta rápido de
su propia evaluación a la mitad. Eso no protege nada: sólo abandona
instrumentos. Van a 180.

Del lado del cliente, las respuestas se juntan segundo y medio antes de mandar.
El endpoint recibe lotes justamente para eso; una petición por clic convierte un
tamizaje de treinta reactivos en treinta viajes de red.

### La pantalla de contestar tiene bandeja de salida

Una respuesta emitida entra a una cola local y no sale de ahí hasta que el
servidor la acuse; se reintenta cada diez segundos y avisa que no hay conexión.
El `uuid_cliente` se genera en el navegador antes de mandar, que es lo que hace
que reintentar sea gratis. Un celular con mala señal a media prueba no puede
costar los ítems ya contestados.

Un 422 no se reintenta: es del contenido del lote, no de la red, y reintentarlo
sería un ciclo infinito que además bloquea todo lo que viene detrás.

### La pantalla de cierre no dice qué salió

Ni un puntaje, ni una categoría, ni un color. El resultado en crudo no se le
comunica a quien contestó: es una interpretación sin nadie que la sostenga, y en
un tamizaje clínico eso hace daño. El sistema sugiere, el profesional
diagnostica.

### El modo se declara en datos, no con ramas

`resources/js/Aplicacion/modos.js` describe los seis modos —tamaño de letra,
tamaño de botón, si hay progreso, si hay cronómetro, si hay refuerzos, si hay
audio— y los componentes lo leen. Agregar el modo kiosco de la V4 es una entrada
más, no una cadena de `if` repartida por doce archivos.

El infantil usa `speechSynthesis` del navegador y no un servicio de voz externo:
mandar el enunciado de un instrumento con copyright a una API de terceros para
que lo lea sería distribuir su contenido.

Los tipos de reactivo sin componente todavía —los de multimedia y los de
aplicación presencial— se dibujan con un aviso visible, no con una pantalla en
blanco. Un hueco silencioso en mitad de una prueba se lee como error de quien
contesta, y el instrumento se abandona sin que nadie sepa por qué.

### Las páginas Inertia se cargan por trozos

El glob de `app.js` dejó de ser `eager`. Con un solo paquete, quien entraba a
`/contestar` desde un celular se descargaba el panel de administración entero
—baterías con su librería de arrastre, catálogo, roles: 540 kB— para ver cinco
reactivos. La pantalla pública es justamente la que peor red tiene. Ahora son
19 kB.

## Fase 7 — Pipeline de calificación e interpretación

### El pipeline se DESCRIBE, no se programa

`instrumento_pipeline` dice qué etapas corre cada versión de instrumento y con
qué estrategia, y `instrumento_pipeline_parametros` —tabla hija, no columna
JSON— guarda sus umbrales. Un PHQ-9 suma; un Cleaver hace conteo ipsativo y
luego fórmulas derivadas; un M-CHAT pasa por un algoritmo de dos etapas. Los
tres recorren el MISMO pipeline y sólo cambian sus filas.

Sin esto, cada instrumento nuevo sería una rama en el motor, y el motor acabaría
siendo un `switch` de doscientos casos que nadie se atreve a tocar.

`RegistroEstrategias` falla RUIDOSO ante una clave desconocida y ante una
estrategia configurada en la etapa equivocada. Ignorarla y seguir produciría una
calificación incompleta que se ve terminada: escalas en cero que parecen
puntajes bajos, y nadie mirando.

### Cada etapa es un job aparte que reconstruye su contexto desde la base

No se pasan datos entre jobs. Dos razones y las dos pesan: un job encolado con
las respuestas dentro las dejaría escritas en la tabla `jobs` y en los paneles
de Horizon —material de expediente clínico fuera de su lugar—, y como cada etapa
persiste su salida (Doc 05 §1.2) la base ya es la fuente. Es lo que permite
recalificar desde la etapa cuatro sin volver a sumar.

### La validez va primero y una aplicación inválida no se califica

Un protocolo contestado al azar produce puntajes perfectamente calculables. Si
se califica antes de mirar la validez, esos números entran al expediente con la
misma apariencia que los buenos. `dudosa` sigue con advertencia; `invalida`
detiene, y eso es configurable porque el Doc 05 lo pide así.

El detalle de cada verificación se guarda con su porqué: "18 de 21 sin responder
(86%)" se puede discutir con la persona; "inválida" a secas, no. Y ese detalle
sólo llega a la audiencia profesional: decirle a quien contestó que su protocolo
salió dudoso "por patrón repetido" es acusarlo de no haber leído, con un
algoritmo por toda evidencia.

### Lo que clasificó un algoritmo especial no se re-normaliza

Los cortes de la NOM-035 los publicó el DOF y los del PHQ-9 su manual. Volver a
pasarlos por una tabla de percentiles produciría un semáforo que se ve como el
oficial y no lo es, en un documento que la empresa entrega a la autoridad.

La etapa 4 se salta toda escala que ya trae `tipo_norma`. Por eso la etapa 2
LIMPIA la normalización al reescribir un bruto: sin ese borrado, una
recalificación dejaría el bruto nuevo con la norma vieja pegada al lado, que es
la peor de las dos mentiras posibles. Lo cazó la prueba de recalificación.

### `conteo_correctas` contaba una vez por clave y no por reactivo

Un reactivo de opción múltiple tiene una clave por opción y todas apuntan a la
misma escala. Contando por clave, cada acierto se sumaba tantas veces como
opciones tuviera el reactivo: un puntaje multiplicado sin que nada se viera
roto. Lo cazó el caso dorado de la corrección por adivinanza.

### "Escala en cero" y "escala sin contestar" no son lo mismo

La etapa de brutos deja en cero toda escala que ninguna respuesta tocó, para que
no desaparezca de los resultados —un reporte que omite una escala se lee como
que el instrumento no la mide—. Pero eso hace que un cero de verdad y un bloque
sin contestar se vean igual.

En el M-CHAT esa diferencia decide si una familia va a evaluación especializada:
la entrevista de seguimiento con puntaje 0 baja el riesgo, y la entrevista sin
contestar no baja nada porque no ocurrió. Por eso existe
`ContextoCalificacion::tieneRespuestasPara()`, que pregunta por las respuestas y
no por el puntaje.

### La prioridad de baremos es tenant → nacional → global

Una empresa con diez mil aplicaciones propias tiene mejor norma para su gente
que la tabla publicada en un manual de 1998 con universitarios de otro país; y
una norma mexicana describe mejor a un adolescente de Oaxaca que una
estadounidense.

**Desviación:** el Doc 05 §2 pide cuatro capas —agrupación → tenant → nacional →
global— y el Doc 03 no da columna para la de agrupación: `baremos` sólo tiene
`organizacion_id`. Se implementaron las tres que el esquema permite. La capa de
agrupación necesita una columna nueva y se puede sumar sin tocar el resolutor.

La capa "nacional" sale de `config/mentia.php` y no de la organización porque
`organizaciones` no guarda país. Mientras Mentia sea de despliegue nacional, el
país es del sistema.

Sin baremo aplicable el resultado se queda en bruto con `sin_norma`, y NO entra
a la serie longitudinal: un bruto dibujado junto a percentiles produce una
gráfica que sube por cambiar de prueba, no por cambiar la persona.

### Las fórmulas se evalúan con un parser propio, nunca con `eval()`

Una expresión que llegó de una hoja de Excel subida por un tenant, ejecutándose
como PHP, es ejecución remota de código con los pasos intermedios ya hechos.
`EvaluadorFormulas` parsea a mano: números, claves de escala, los cuatro
operadores y paréntesis. Nada más existe en esa gramática.

Los valores se rearman en cada vuelta del bucle de fórmulas, no una vez al
principio: una fórmula puede citar la escala que produjo la anterior, y con un
mapa congelado leería el valor de antes.

### El código de perfil desempata por el orden de la escala, no por el id

El RIASEC de tres letras y los tipos DISC salen de las escalas más altas. Un
desempate que dependa del orden en que la base devolvió las filas produce
resultados que cambian al recalificar, y dos personas con el mismo perfil tienen
que recibir el mismo código las dos veces.

### La audiencia se deriva del rol; jamás llega por parámetro

Si el evaluado pudiera pedir la audiencia `profesional`, el texto cuidado que se
escribió para él dejaría de servir para nada: bastaría cambiar un parámetro en
la URL para leer la versión técnica de su propio tamizaje de depresión.

Y sin `resultados.ver_detalle` salen las interpretaciones pero NO los puntajes.
Un percentil sin nadie que lo explique es una cifra que la persona va a
interpretar sola, y casi siempre mal.

El titular menor de doce años recibe la versión infantil; de ahí en adelante, la
de adulto. El infantil a los quince se lee como que lo tratan de niño, que es la
forma más rápida de perder a un adolescente.

### Recalificar archiva primero

**Desviación documentada:** el Doc 03 no trae tablas de histórico y el Doc 08
exige recalificar "conservando histórico". Se agregaron `resultados_archivados`
más dos hijas. Sin ellas, recalificar PISA el resultado que se le entregó a
alguien —posiblemente el que sustentó una contratación o una canalización— y una
impugnación de seis meses después deja de poder reconstruirse.

El archivo guarda la CLAVE de la escala además del id: una recalificación puede
venir justamente de haber publicado una versión nueva del instrumento, y
entonces la escala vieja puede no existir.

El comando exige criterio explícito. Una recalificación masiva sin querer sobre
cien mil aplicaciones es una tarde de cola y un montón de expedientes tocados
sin razón. Y la opción es `--instrumento`, no `--version`: Artisan ya define
`--version` para sí mismo y declararla revienta el comando entero.

### Los comparadores no castigan lo que nadie preguntó

En el ajuste al puesto, un criterio SIN dato se reporta aparte y no cuenta como
fallo: contarlo como cero hundiría al candidato por una prueba que la
organización no le aplicó. El porcentaje se calcula sobre los criterios que sí
se pudieron evaluar, y ponderado —en un puesto de cajero la escrupulosidad pesa
más que la extroversión, y un promedio plano diría que dos candidatos muy
distintos son equivalentes—.

En el cambio significativo sólo se comparan mediciones del MISMO tipo de norma.
Restar un percentil de una puntuación T daría "quince puntos de mejora" que no
describen nada de la persona. Y el umbral por omisión es una desviación estándar
de la escala en que se mide: no es un número redondo elegido al azar, es donde
la diferencia empieza a ser mayor que el error de medición típico.

## Fase 8 — Alertas, protocolos y vistas de resultados

### Sin protocolo de actuación no se asigna un instrumento con centinelas

Es la única comprobación del sistema que existe para proteger a quien contesta,
no a los datos. Un instrumento con reactivos centinela detecta ideación
suicida; habilitarlo sin haber definido quién responde, en cuánto tiempo y a
dónde se canaliza produce exactamente esto: una alerta crítica a las once de la
noche en un buzón que nadie mira hasta el lunes.

La compuerta va en la CREACIÓN de la asignación, no al habilitar el instrumento:
habilitar es un acto administrativo que puede pasar meses antes y el protocolo
puede haberse borrado en medio. Lo que importa es que en el momento en que
alguien va a contestar haya quién responda.

El mensaje dice qué falta —registrar el protocolo, designar destinatarios, o las
dos— porque un "no se puede asignar" a secas manda a quien administra a
adivinar. Y se exigen 80 caracteres mínimos: el mínimo no garantiza calidad,
pero impide despachar el requisito con un punto.

### Los destinatarios se configuran por ROL, no por persona

Quien atiende las alertas críticas es la psicóloga de guardia, y las personas
entran y salen de ese rol sin que nadie tenga que actualizar una lista. Una
lista de correos se queda apuntando a quien renunció hace dos años, y esa es
exactamente la alerta que nadie atiende.

### El correo de alerta no lleva el contenido de la respuesta

Dice que hay una alerta y dónde verla, y nada más: ni el reactivo, ni el nombre
de la persona evaluada, ni el del instrumento. Un correo viaja por servidores
que no son de nadie, se queda en la bandeja de entrada de quien lo reciba para
siempre y se reenvía sin pensarlo. El detalle se ve dentro del sistema, donde el
acceso pasa por AccesoService y queda en bitácora.

Tampoco se encola: una alerta crítica que espera su turno detrás de doscientos
correos de invitación llega cuando ya no sirve, y el tiempo de respuesta es lo
que el protocolo de actuación se compromete a cumplir.

Un canal que falla no tumba a los demás ni al registro. Si el correo revienta,
la campana in-app queda igual — es la que la psicóloga sí va a ver cuando entre.

### Cerrar una alerta exige decir qué se hizo

Una alerta que se puede cerrar con un clic se cierra con un clic, y entonces el
registro no dice si alguien habló con la persona o si sólo quitaron el punto
rojo de la pantalla. Lo que la organización tiene que poder demostrar es lo
segundo, no lo primero.

En el centro de alertas las críticas van arriba y, dentro de cada nivel, las MÁS
VIEJAS primero: una alerta crítica de hace tres días es peor noticia que una de
hace diez minutos, y una bandeja ordenada por fecha descendente esconde
justamente la que se está pudriendo.

### El escalonamiento automático no pasa en silencio

Toda acción de `protocolo_reglas` —asignar la segunda etapa de un M-CHAT,
notificar a un rol, marcar seguimiento— genera alerta y deja bitácora. Un
sistema que asigna evaluaciones al expediente de alguien sin que nadie se entere
es un sistema que nadie puede auditar, y lo que está haciendo es una decisión
clínica.

`RegistroBitacora` no sabía registrar acciones sin actor humano, así que se le
agregó `registrarAccion()`. El `actor_persona_id` va en NULL, que es lo honesto:
atribuirlo a quien configuró la regla haría parecer que estuvo ahí.

### Una regla de protocolo corre UNA VEZ por aplicación

`protocolo_ejecuciones` lo garantiza. Sin ella, recalificar volvería a mandarle
a la familia la liga de la entrevista de seguimiento y al psicólogo la misma
alarma; a la tercera deja de mirarlas.

### El escalonamiento hereda propósito y autor de la asignación original

Dos defectos que se corrigieron antes de que llegaran a producción:

- **No se busca un propósito con clave "seguimiento".** Depender de una clave
  mágica haría que el escalonamiento fallara en silencio en cualquier tenant que
  no la hubiera creado, que son todos hasta que alguien se acuerde. Se reusa el
  propósito que trajo a la persona hasta aquí, que además lleva el tipo de
  consentimiento y la vigencia correctos.
- **La autora no es la persona evaluada.** Un M-CHAT de seguimiento lo pide
  quien pidió el tamizaje. Poner de autora a la madre que contestó diría en el
  expediente que ella se lo asignó a su hijo, y eso es falso.

### `permiteAsignar()` era código muerto y la mutación lo destapó

Al mutar `permiteAsignar()` para que devolviera siempre `true`, ninguna prueba
falló: `CreadorAsignaciones` llama a `motivoDeBloqueo()`. Dos métodos con la
misma lógica duplicada empiezan iguales y divergen al primer cambio — la
pantalla diría que sí se puede asignar y la creación lo rechazaría, o mucho
peor, al revés. Ahora `permiteAsignar()` se define en términos de
`motivoDeBloqueo()` y no pueden separarse.

### El mensaje de cierre lleva recursos, no resultados

Al terminar un instrumento de sensibilidad 3–4 se muestran los recursos de apoyo
que el tenant configuró. No dice qué salió —eso es interpretación sin nadie que
la sostenga— pero sí a dónde acudir si algo de lo que se preguntó dejó a la
persona mal.

Los escribe el tenant porque un número de emergencia depende del municipio, y
uno equivocado es peor que ninguno. Y sólo aparece en instrumentos sensibles: un
test vocacional que cerrara con una línea de crisis convertiría el mensaje en
decorado que nadie lee.

### El perfil longitudinal agrupa por dominio, no por instrumento

A nadie le importa con qué prueba se midió la ansiedad en 2023, le importa la
ansiedad. La gráfica es un SVG a mano y no una librería: son cuatro puntos y una
línea, y meter doscientos kilobytes de dependencia para eso los pagaría cada
visita.

Sólo se listan los cambios SIGNIFICATIVOS. Marcar todos los movimientos tendría
el mismo efecto que no marcar ninguno, porque nadie mira una lista en la que
todo está marcado.

En el resultado individual, una escala `sin_norma` no se dibuja como barra: un
bruto pintado junto a percentiles se lee como si significara lo mismo, y no
significa nada fuera de su propia aplicación. Y la advertencia de validez va
ARRIBA, antes de cualquier número — un puntaje leído sin ella es un puntaje mal
leído.

## Pendientes cerrados — panel, menú y autenticación

### El panel se arma por tarjetas declaradas, no por ramas de rol

`CatalogoSecciones` declara cada sección con su permiso, igual que
`CatalogoPermisos` declara cada permiso: aquel dice qué se puede hacer, éste
dónde se hace. El middleware de Inertia filtra la lista por lo que el rol activo
alcanza y comparte la prop `menu` que `LayoutAdmin` esperaba desde la Fase 0.

Mandar la lista completa y esconder con `v-if` en el cliente sería decirle al
navegador qué existe: cualquiera abriría las herramientas de desarrollo para ver
el mapa de un sistema al que no tiene acceso.

Una prueba comprueba que ninguna sección declare un permiso inexistente. Un
permiso mal escrito escondería la sección para siempre y nadie se enteraría: la
pantalla existiría, la ruta funcionaría y el menú no la mostraría nunca.

Lo pendiente va arriba del panel y con número. Un panel que abre con un saludo y
deja las tres alertas críticas escondidas en el menú lateral no sirve para
trabajar. Y quien no atiende alertas no ve su conteo: saber cuántas hay abiertas
ya es información clínica sobre el grupo.

### No existía la pantalla de entrar

`admin-base` la iba a traer y admin-base nunca existió (ver la decisión de la
Fase 0). El middleware `auth` redirigía a `route('login')`, esa ruta no estaba
declarada, y nadie podía usar el sistema. Se descubrió al poner el panel detrás
de `auth`.

Se construyó con tres decisiones:

- **No hay registro público.** Las personas las da de alta la organización.
  Alguien que se registra solo no está vinculado a ningún tenant y no puede ver
  nada, pero sí habría creado una cuenta con un correo que quizá no es suyo, en
  un sistema que guarda expedientes clínicos.
- **Un solo mensaje de error** para correo inexistente y contraseña equivocada.
  Distinguirlos convierte el formulario en un verificador de qué correos tienen
  cuenta aquí, y tener cuenta aquí ya dice algo de una persona.
- **Estrangulamiento por correo Y por IP.** Sólo por correo, cualquiera
  bloquearía la cuenta ajena a propósito; sólo por IP, una oficina entera
  comparte castigo.

Al entrar se fija la primera organización vigente: sin tenant activo los global
scopes fallan cerrado y todas las pantallas salen vacías, y quien ve un sistema
en blanco concluye que está roto, no que le falta elegir organización.

## Fase 9 — Reportes, IA, ARCO y endurecimiento

### El PDF se guarda, no se regenera

Un reporte es un DOCUMENTO ENTREGADO: alguien lo imprimió y lo puso en un
expediente o se lo dio a una familia. Si el catálogo cambia mañana —se corrige
un baremo, se reescribe una interpretación— el papel que esa persona tiene en la
mano tiene que seguir explicándose. Regenerarlo al vuelo produciría un documento
distinto con el mismo folio.

### Las plantillas se sustituyen, no se compilan

`RenderizadorPlantilla` conoce dos cosas: `{{ clave }}` y `{{#lista}}…{{/lista}}`.
Todo lo que sustituye va escapado y no hay marcador "sin escapar" —en cuanto
exista, alguien lo va a usar para meter estilos y con ellos scripts—.

Una plantilla que un tenant puede editar y que el servidor COMPILARA —Blade,
Twig, lo que sea— es ejecución de código arbitrario con los pasos intermedios ya
hechos: quien editara la plantilla del reporte podría leer el `.env`.

dompdf va con `isRemoteEnabled` y `isPhpEnabled` en falso: una plantilla podría
apuntar a `http://localhost/…` y convertir el generador de PDF en un lector de
la red interna.

### El insumo de la IA va pseudonimizado, y lo garantiza el armador

Entra lo que el Doc 05 §6 permite: escalas, normas, interpretaciones ya
resueltas, banderas y validez. No entra nombre, CURP, fecha de nacimiento ni las
respuestas crudas —una respuesta abierta puede contener cualquier cosa que la
persona haya querido escribir—.

La edad sí entra, en AÑOS y no en fecha: un reporte que no sabe si habla de
alguien de siete o de cuarenta años no sirve, y el año no identifica a nadie.

Que la pseudonimización viva en `ArmadorInsumoIA` y no en quien llama es el
punto: si cada llamador armara su propio insumo, tarde o temprano uno metería el
nombre "para que el reporte quede mejor". Una prueba lo comprueba mirando lo que
recibió el redactor, no lo que salió.

### El borrador nace como borrador y no hay otro camino

- Firmar un reporte con borrador de IA sin validar **está prohibido en el
  código**, no sólo en la documentación. La firma dice "yo respondo por esto".
- Validar exige el permiso `ia.validar_reportes`.
- Rechazar exige decir por qué: un borrador rechazado sin explicación deja el
  expediente con un texto muerto, y quien lo lea en seis meses no puede saber si
  se rechazó porque el texto estaba mal o porque los datos lo estaban.
- Quien valida puede CORREGIR el texto. Es la diferencia entre revisar y sellar.

El prompt de sistema vive versionado en `config/ia.php` y no en la base: es
configuración que se revisa en un pull request. Un prompt editable en caliente es
un prompt que nadie sabe qué decía el día que se generó un reporte impugnado.

Sin `ANTHROPIC_API_KEY` el integrador falla RUIDOSO. Devolver texto vacío
produciría un reporte que parece terminado y que nadie redactó, y alguien lo
firmaría.

### ARCO son plazos, no un formulario

La LFPDPPP da 20 días hábiles para decir si procede y 15 más para hacerla
efectiva. El plazo se calcula AL RECIBIR y se guarda: recalcularlo después con el
calendario de hoy daría otra fecha si cambian los asuetos, y el plazo que corre
es el que corría el día que entró la solicitud.

Declarar improcedente EXIGE documentar la excepción. Hay datos que la
organización está obligada a conservar —la bitácora— y eso se sostiene; lo que
no se sostiene es no explicarlo. Una negativa sin fundamento es una queja ante
el INAI.

**Limitación declarada:** el cálculo de días hábiles salta sábados y domingos
pero no los descansos obligatorios del artículo 74 de la LFT ni los asuetos que
cada organización declare. El plazo que sale es por tanto igual o MÁS CORTO que
el real, y quedarse corto en un plazo legal es el error que no hace daño.

### 2FA obligatoria: bloquea, no sugiere

Quien puede abrir el expediente clínico de un menor entra con dos factores. Es
requisito del ROL y no de la persona, así que a quien hoy le suben el rol queda
obligado mañana sin que nadie tenga que avisarle.

El umbral es configurable hacia arriba y **no baja de 3**: el código lo impone
con un `max()`. Una organización que quisiera apagarlo estaría desactivando un
control que protege expedientes clínicos de menores.

Exigírselo a todo el mundo suena más seguro y no lo es: un docente que sólo ve
nombres de su grupo abandona el sistema antes de terminar de configurar una
aplicación de autenticación, y entonces alguien le pasa su contraseña a otro.

El middleware va en web Y en API: la API es exactamente por donde un rol de
sensibilidad alta se saltaría el segundo factor si la exigencia viviera nada más
en las pantallas.

La pantalla de activación queda fuera del bloqueo. Encerrar a la persona sin
dejarle activar lo que se le exige convertiría la medida en un candado sin
llave.

Los códigos de recuperación son de un solo uso: dejarlos reutilizables
convertiría una captura de pantalla en una llave permanente. Y existen porque,
en un sistema donde el acceso lo da la organización, perder el teléfono sin
ellos significa una llamada a soporte para que alguien desactive la 2FA por
fuera — que es exactamente el agujero que la 2FA venía a tapar.

### El modo estricto de Eloquent y las columnas nuevas

Agregar columnas nullable a `users` rompió tres pruebas con
`MissingAttributeException`: `preventAccessingMissingAttributes` está activo y un
`create()` sólo trae los atributos que insertó. Se resolvió con valores por
omisión en `$attributes` del modelo, que es lo que evita que vuelva a pasar con
cada columna que se agregue.

## Pendientes de la Fase 9 — cifrado, rotación, agregados y fugas

### Faltaban dos de las cuatro columnas cifradas

El Doc 06 §4 lista cuatro cosas que van cifradas a nivel de aplicación. Estaban
`respuestas.valor_texto` y `notas_profesionales.contenido`; faltaban los valores
del expediente y los textos de interpretación resueltos. La migración de
respuestas incluso llevaba el comentario de que iba cifrado, y ahí sí estaba el
cast — pero en expediente no.

`«Puntaje compatible con sintomatología depresiva moderada»` es material
clínico, y a diferencia del puntaje —que no dice nada sin su baremo— este texto
se entiende leyéndolo. Es la diferencia entre un número y un expediente.

Se cifra TODA la columna, no sólo la de secciones o instrumentos sensibles.
Decidirlo caso por caso exigiría consultar el catálogo en cada lectura de
atributo —una consulta por celda de una pantalla de expediente— y el día que
alguien reclasificara una sección, los datos viejos quedarían ilegibles o los
nuevos en claro sin que nada protestara. Es un superconjunto de lo que el
documento pide, a propósito.

El precio es que esas columnas dejan de ser buscables por SQL. Nadie las busca
—se leen por campo, no por contenido— y una búsqueda de texto libre sobre
domicilios y antecedentes clínicos sería de todas formas un problema de
privacidad, no una funcionalidad.

Las pruebas comparan contra la COLUMNA CRUDA con `DB::table()`. Leer por el
modelo probaría que el cast descifra, que es justo lo que no está en duda.

### La rotación de llave y su lista

`APP_KEY` descifra cinco cosas. Cambiarla sin recifrar las deja ilegibles PARA
SIEMPRE: no es un error que se arregle volviendo atrás, porque los datos siguen
cifrados con la llave vieja que ya nadie tiene.

`mentia:rotar-llave` recifra por trozos, con `--simular` para verificar antes de
escribir. Si un solo valor no se puede descifrar con la llave actual, **no rota
nada más**: dejar la base a medias, con unas columnas en la llave nueva y otras
en una que nadie conoce, es peor que no empezar.

La constante `COLUMNAS_CIFRADAS` **es** la definición de qué está cifrado en el
sistema, y una prueba la compara contra los casts declarados en todos los
modelos del dominio. Agregar un cast `encrypted` y olvidar la lista produciría
una rotación que parece exitosa y deja esa columna con la llave vieja. La
mutación lo confirma: al quitar `expediente_valores` de la lista, la prueba lo
dice con nombre y apellido.

La llave nueva se pone en `APP_KEY` DESPUÉS de rotar, no antes: mientras el
comando corre, la aplicación tiene que poder seguir descifrando con la vieja lo
que todavía no se convirtió.

### El reporte grupal tiene tamaño mínimo

Un reporte grupal de tres personas no es un agregado: es la lista de esas tres
personas escrita de otra forma. En una NOM-035 anónima eso deshace el anonimato
—el jefe sabe quiénes son los tres— y con él la única razón por la que la gente
contestó con la verdad.

El mínimo es 5, configurable hacia arriba, y el código no lo deja bajar. Una
organización que quisiera bajarlo estaría desactivando lo único que impide que
un reporte "agregado" señale a una persona.

El semáforo agregado **cuenta etiquetas, no promedia valores**. Promediar
categorías —«medio» y «muy alto» dan «alto»— produce un número que no describe a
nadie del grupo y esconde justamente a quien está peor.

El grupal nunca lista personas, ni siquiera cuando la asignación es nominal:
existe para ver el bosque, y quien necesite el árbol abre el resultado
individual, que pasa por AccesoService uno por uno.

La NOM-035 es el mismo agregado con otra portada, y eso es a propósito: los
cortes y los semáforos oficiales los aplica el pipeline con `nom035_cortes`, no
la plantilla. Una plantilla que calculara sus propios niveles produciría un
documento con formato oficial y números que no lo son.

### La suite de fugas cubre las Fases 6 a 9

Nueve intentos contra resultados, perfil longitudinal, generación y descarga de
reportes, alertas, ARCO y validación de borradores de IA. Todos responden 404,
nunca 403: un 403 confirma que el recurso existe, y un resultado que existe
significa que esa persona fue evaluada allí.

La intrusa tiene TODOS los permisos que hagan falta, en SU propia organización.
Es el punto: quien tiene `resultados.ver_detalle` lo tiene en su tenant, no en
el de junto. Una intrusa sin permisos probaría el `can:` y no el aislamiento.

Escribiendo la prueba del listado de alertas apareció la demostración más
directa del aislamiento: el propio andamio quedó filtrado. Contar las alertas
existentes desde la prueba requirió `withoutGlobalScopes()`, porque el contexto
activo era el de la intrusa.

### Procedimiento de vulneraciones

`docs/incidentes.md` cubre la obligación del Doc 06 §4. Está escrito para leerse
un martes a las tres de la mañana: qué cuenta como vulneración notificable y qué
no, las primeras dos horas en orden, qué se le dice al titular —en español
llano, porque una persona que recibe un aviso lleno de tecnicismos no puede
tomar ninguna medida—, el caso de `APP_KEY` comprometida, y el procedimiento de
prueba de restauración.

La verificación de la restauración no es que el volcado cargue: es que los datos
cifrados se LEAN. Si lanza `DecryptException`, el respaldo está cifrado con una
llave que ya no existe y no sirve para nada.

Y una regla al final: **una prueba automatizada por cada fuga real**. Un
incidente que no deja una prueba detrás se repite.
