# MENTIA — Convenciones del proyecto

Versión 1.0 — Fase 0

Este documento lo produce la Fase 0 y lo consultan todas las demás. Cuando una
convención de aquí choque con lo que pide un prompt de fase, **manda el
diccionario de datos del Doc 03**; lo de aquí es cómo se escribe, no qué se
construye.

---

## 1. Idioma

**Español mexicano en todo lo que nombra al dominio**, con acentuación
completa. Eso incluye tablas, columnas, modelos, servicios, rutas visibles,
props de Inertia, mensajes de validación y textos de interfaz.

**Se queda en inglés** lo que pertenece al framework y no al dominio:

| En inglés (framework) | En español (dominio) |
|---|---|
| `app/Http/`, `app/Providers/`, `app/Console/` | `app/Domain/Organizaciones/` |
| `boot()`, `register()`, `handle()`, `index()`, `store()` | `autorizar()`, `calificar()`, `resolverBaremo()` |
| `users`, `jobs`, `cache`, `migrations` (tablas de Laravel) | `personas`, `aplicaciones`, `respuestas` |
| Sufijos `Controller`, `Request`, `Resource`, `Job` | La raíz del nombre: `PersonaController` |

Mezclar dentro de una misma palabra (`getPersona`, `personaService`) es lo
único que no se hace. O `obtenerPersona()`, o el nombre de framework completo.

**Tuteo, no "usted"**, en toda la interfaz. El sistema lo usan orientadores,
psicólogas, alumnos de secundaria y padres de familia; el "usted" institucional
lee distante justo en las pantallas del titular, que son las más leídas.

**El sistema sugiere, nunca diagnostica** (principio P6). Ningún texto de
interfaz, interpretación o reporte dice "tiene", "padece" ni nombra un
diagnóstico. Se dice "perfil compatible con", "se sugiere valoración por" o "se
recomienda canalización a". Esto no es estilo: es el límite legal del producto.

## 2. Nombres

### Base de datos

- Tablas: `snake_case` **plural** en español — `personas`, `versiones_instrumento`,
  `resultados_escala`.
- Columnas: `snake_case` singular — `fecha_nacimiento`, `organizacion_id`.
- Pivotes: los dos nombres en singular y alfabético — `agrupacion_persona`.
  Cuando el pivote tiene vida propia (atributos, vigencia), deja de ser pivote y
  se nombra por lo que es: `organizacion_personas`, `membresias`.
- Llaves foráneas: `<tabla_singular>_id`.
- Booleanas: prefijo `es_` o `requiere_` — `es_centinela`, `es_anonima`,
  `requiere_supervision`. Nunca un nombre que se lea igual en positivo y en
  negativo (`activo` sí; `estatus_bandera` no).
- Fechas y sellos de tiempo: `_en` para instantes (`creado_en`, `respondida_en`)
  y `fecha_` para fechas sin hora (`fecha_nacimiento`).
- **El nombre de una tabla se pregunta, no se adivina.** Consultar con Eloquent
  en vez de escribir el nombre a mano evita el problema entero.

### PHP

- Clases: `PascalCase`. Modelos en singular (`Persona`, `VersionInstrumento`).
- Servicios de dominio: sustantivo de oficio, no `XManager` ni `XHelper` —
  `AccesoService`, `CalificadorAplicacion`, `ResolutorBaremo`.
- Métodos y variables: `camelCase` en español.
- `declare(strict_types=1)` en todos los archivos. Lo pone Pint.
- Los tipos se declaran: parámetros, retornos y genéricos de arreglo. Es lo que
  PHPStan nivel 6 exige y lo que hace que `array $respuestas` tenga que decir
  array **de qué**.

### Frontend

- Componentes Vue: `PascalCase.vue` en español — `TarjetaPersona.vue`,
  `PerfilLongitudinal.vue`.
- Páginas Inertia en `resources/js/Pages/`, con el mismo nombre que se pasa a
  `Inertia::render()`.
- Props que vienen del servidor: `camelCase` en español, igual que en PHP.

## 3. Arquitectura

### La regla que no se negocia

**Los controllers no contienen lógica de negocio** (Doc 02 §2). Un controller
recibe la petición, la valida con un FormRequest, llama a un servicio de
`app/Domain/` y devuelve una página Inertia o un API Resource.

Si un método de controller necesita saber qué es un baremo, cuándo vence un
consentimiento o cómo se resuelve un salto condicional, esa decisión está en el
lugar equivocado.

### Estructura de un dominio

```
app/Domain/<Dominio>/
├── <Dominio>ServiceProvider.php   Declara los bindings contrato => implementación
├── Modelos/                       Eloquent
├── Servicios/                     El oficio del dominio
├── Contratos/                     Interfaces que otros dominios consumen
├── Datos/                         DTOs de entrada y salida de los servicios
├── Eventos/                       Lo que el dominio anuncia
└── Excepciones/                   Fallas propias del dominio
```

`Modelos/` y `Servicios/` existen desde la Fase 0; los demás se crean cuando el
dominio los necesita. Un directorio vacío no documenta nada.

### Quién puede llamar a quién

- Un dominio consume a otro **por su contrato**, nunca instanciando su servicio
  ni tocando sus modelos directamente. Por eso `Consentimientos` vive aparte de
  `Expedientes`: quien pregunta por un consentimiento es `Accesos`, y
  fusionarlos crearía una dependencia circular.
- Los controllers web y los de la API llaman a **los mismos servicios**. Si un
  endpoint de API necesita lógica que el web no tiene, es señal de que la
  lógica quedó en el controller.
- **AccesoService es el único punto de autorización** para recursos de
  personas. Ningún controller replica por su cuenta las verificaciones de
  permiso, alcance, sensibilidad y consentimiento.

### API versionada

- La versión va en el **archivo**, no sólo en el prefijo: `routes/api/v1.php` con
  controllers en `App\Http\Controllers\Api\V1`. Cuando exista una v2, la v1
  sigue respondiendo intacta — la app Flutter instalada en un teléfono no se
  actualiza porque nosotros publiquemos.
- Nombres de ruta `api.v1.*`.
- Cada fase entrega sus endpoints de API **junto con** los del módulo web, no
  después.
- Errores en RFC 7807 (`type, title, status, detail, errors{}`) con
  `application/problem+json`. Los emite `App\Http\Api\Problema` desde el
  manejador de excepciones; un controller nunca arma un error a mano.
- Fechas ISO 8601 **con zona**. Paginación por cursor (`?cursor=&limit=`), con
  tope de servidor: el cliente puede pedir menos, nunca más.

## 4. Multi-tenancy

- Base de datos única con discriminador `organizacion_id` en todas las tablas de
  tenant, más global scopes de Eloquent y middleware de resolución de tenant.
- **Spatie en modo teams con `team_foreign_key = organizacion_id`.** No se
  apaga. Una prueba lo vigila (`tests/Feature/Fundacion/ConfiguracionTest`),
  porque republicar la config del paquete lo desactivaría y el sistema seguiría
  arrancando: los roles simplemente dejarían de estar acotados por tenant, que
  es una fuga entre organizaciones y no un error visible.
- **Entidades globales (sin tenant):** `personas`, todo el catálogo de
  instrumentos y su contenido, tipos de reactivo, niveles de sensibilidad,
  permisos del sistema y plantillas de rol.
- **Entidades de tenant:** unidades, agrupaciones, asignaciones, aplicaciones,
  resultados, roles instanciados, instrumentos y baremos propios.
- Todo módulo nuevo entrega **pruebas de aislamiento cross-tenant** que intenten
  fugar datos por sus propios endpoints. Una fuga que nadie intentó provocar no
  está descartada.

## 5. Datos

- **Cero JSON en datos de dominio** (principio P2). Una fila por reactivo, una
  por respuesta, una por regla. Cuando algo enumerable se quiera guardar como
  JSON, hay que poder explicar por qué no es tabla.
- MySQL 8, InnoDB, utf8mb4. El motor va declarado en `config/database.php`, no
  se deja al default del servidor.
- **Versionado inmutable** (principio P4): instrumentos, baremos, textos de
  consentimiento e interpretaciones se versionan, y las aplicaciones históricas
  apuntan a la versión exacta con la que ocurrieron. Publicada una versión, su
  contenido no se edita — se bloquea a nivel de servicio y con prueba.
- **La bitácora no se actualiza ni se borra.** Es append-only por diseño.
- Cifrado a nivel aplicación (casts encriptados) para respuestas y resultados de
  sensibilidad 3–4, y para notas profesionales.
- **NULL no es cero.** Un reactivo sin responder no puntúa como 0: deja la
  aplicación incompleta. Un cero es una respuesta; un NULL es que la persona no
  llegó ahí.
- Se almacena en **UTC** y se presenta en la zona de la organización. México no
  tiene una sola hora, y los cronómetros son server-side.
- Índices: no se indexan las foráneas "por si acaso". Un índice de más se paga
  en cada escritura, para siempre.

## 6. Colas

Cuatro colas con supervisor propio en Horizon (Doc 02 §7):

| Cola | Para qué | Regla |
|---|---|---|
| `alertas` | Reactivo centinela positivo | Prioridad máxima; la espera se mide en segundos |
| `calificacion` | Pipeline de 6 etapas encadenadas | El grueso del trabajo; escala primero |
| `notificaciones` | Correo, WhatsApp, push | Tolera reintentos |
| `reportes-ia` | Llamadas a la API de Anthropic | Lentas y caras: un solo intento |

Un job declara su cola siempre. La única lógica síncrona del pipeline es la
evaluación de reactivos centinela al recibir respuestas: una alerta que espera
en cola no es una alerta.

## 7. Pruebas

- **Corren contra MySQL 8, no contra SQLite.** El esquema usa CHECK constraints,
  llaves foráneas reales e índices compuestos que SQLite no sabe hacer; probar
  ahí sería probar un esquema distinto del que corre en producción. La base
  `mentia_testing` se crea una vez a mano.
- Nombres de prueba en español, describiendo la conducta:
  `test_una_membresia_vencida_no_da_acceso()`.
- Cada fase entrega, como mínimo: pruebas de feature de sus endpoints web y de
  API, pruebas de aislamiento de tenant, y —donde haya cálculo— casos dorados
  con resultado esperado.
- **Una prueba que no falla al romper lo que dice probar, no prueba nada.**
  Antes de dar una por buena: mutar a propósito el código que vigila y
  comprobar que cae.
- Las pruebas de PHP no dependen de `public/build`; quien comprueba el frontend
  es `npm run build`, en su propio trabajo de CI.

## 8. Calidad

```bash
composer revisa
```

Corre las tres compuertas que CI exige, en orden:

1. `vendor/bin/pint --test` — formato. La configuración vive en `pint.json`.
2. `vendor/bin/phpstan analyse` — **nivel 6**, sin baseline. Un error de PHPStan
   se corrige en el código; no se silencia con `@phpstan-ignore`, ni con un
   `@var` inline, ni con un cast puesto para callarlo.
3. `php artisan test`.

CI (`.github/workflows/ci.yml`) corre además `npm run build` en un trabajo
aparte, con MySQL 8 y Redis 7 como servicios.

## 9. Git

- Mensajes en español, Conventional Commits: `feat:`, `fix:`, `chore:`,
  `docs:`, `refactor:`, `test:`.
- Un commit por unidad lógica; commits incrementales, no una entrega por fase.
- No se hace `push` sin pedirlo.

## 10. Trampas del entorno local

- **Horizon no corre en Windows**: exige `ext-pcntl` y `ext-posix`, que no
  existen ahí. Por eso `composer.json` declara un override en
  `config.platform` — sin él, `composer install` ni siquiera resuelve. En local
  las colas se atienden con `php artisan queue:listen`; Horizon es del
  despliegue en Linux, y CI sí lo resuelve de verdad porque instala las dos
  extensiones.
- **`public/hot` obsoleto secuestra el frontend.** Si quedó de un `npm run dev`
  que ya murió, Laravel sigue apuntando al dev server y **ningún `npm run build`
  se ve**: se editan componentes, compila sin errores y la pantalla no cambia.
  Si un cambio de Vue no aparece: `ls public/hot` y bórralo.
- **Lo que se dibuja hay que mirarlo.** Un componente puede compilar y salir
  ilegible; ni Pint, ni PHPStan, ni las pruebas de feature abren la pantalla.
