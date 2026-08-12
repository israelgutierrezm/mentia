# Mentia — Plataforma de evaluación psicométrica longitudinal

Contexto permanente del proyecto. Léelo al inicio de cada sesión.

## Qué es

SaaS multi-tenant para aplicar, calificar, interpretar y dar seguimiento a
pruebas psicométricas, laborales, vocacionales, clínicas breves y de
desarrollo. La idea rectora es el **expediente psicométrico de vida**: la
persona es la entidad raíz y permanente, y las evaluaciones son eventos que se
acumulan en su línea de tiempo, del tamizaje de preescolar a la selección
laboral en la adultez.

Analogía del cliente: un expediente clínico electrónico que acumula estudios y
deja ver la evolución de cada "órgano" en el tiempo. Aquí los órganos son
dominios —cognitivo, emocional, personalidad, intereses, adaptativo— en
puntuaciones normalizadas comparables entre edades.

## Fuente de verdad

La suite de ocho documentos en **`/docs`** es el diseño canónico. No
re-diseñar el dominio: implementarlo.

| Doc | Contenido |
|---|---|
| 01 | Visión y alcance — los 8 principios de diseño |
| 02 | Arquitectura general — capas, multi-tenancy, colas |
| 03 | Modelo de datos — DER y diccionario |
| 04 | Catálogo de instrumentos y plan de seed |
| 05 | Motor de calificación e interpretación |
| 06 | Seguridad, permisos y marco legal |
| 07 | Especificación API v1 |
| 08 | Plan de fases y prompts |
| `convenciones.md` | Cómo se escribe el código (producido en la Fase 0) |
| `decisiones.md` | Ambigüedades resueltas y desviaciones, con su porqué |

Cuando la spec tenga una ambigüedad, **preguntar en vez de inventar** y anotar
la resolución en `docs/decisiones.md`.

## Stack

- Laravel 12 + PHP 8.3, MySQL 8 (WAMP local, InnoDB obligatorio)
- Inertia 3 + Vue 3 + Vite 7 + Tailwind 4
- `spatie/laravel-permission` **en modo teams**, `team_foreign_key = organizacion_id`
- `spatie/laravel-activitylog` (bitácora), `spatie/laravel-medialibrary` (documentos)
- Sanctum (tokens de app y tokens anónimos de aplicación)
- Redis + Horizon: colas `calificacion`, `alertas`, `notificaciones`, `reportes-ia`
- Flutter en la V3; API de Anthropic para reportes integradores en la Fase 9

## Las reglas que no se negocian

1. **Los controllers no contienen lógica de negocio.** Petición → FormRequest →
   servicio de `app/Domain/` → página Inertia o API Resource.
2. **AccesoService es el único punto de autorización** de recursos de personas.
   Resuelve permiso + alcance + sensibilidad + consentimiento en cortocircuito y
   deja bitácora en toda decisión, autorice o niegue.
3. **Cero JSON en datos de dominio.** Una fila por reactivo, una por respuesta,
   una por regla. Lo enumerable es tabla.
4. **Config-driven.** Ningún instrumento se programa: se describe con catálogos.
   Agregar una prueba nueva es cargar configuración, no código.
5. **Versionado inmutable.** Publicada una versión de instrumento, baremo,
   consentimiento o interpretación, su contenido no se edita. Las aplicaciones
   históricas apuntan a la versión exacta con la que ocurrieron.
6. **El sistema sugiere, nunca diagnostica.** El diagnóstico y la firma son
   actos del profesional humano. Toda salida de IA es borrador sujeto a
   validación.
7. **API versionada desde el primer módulo.** Cada fase entrega sus endpoints
   `/api/v1` junto con su módulo web, llamando a los mismos servicios.
8. **Español mexicano** con acentuación completa en todo lo visible.

Todo el detalle está en `docs/convenciones.md`.

## Estado

**Fase 1 completa** (M1–M3: organizaciones, personas y accesos).

- **Modelo de datos M1–M3** conforme al Doc 03, con una desviación documentada
  (la relación persona↔cuenta va sólo en `users.persona_id`).
- **AccesoService** con las cuatro dimensiones en cortocircuito y bitácora en
  toda decisión. El consentimiento es un contrato con implementación
  provisional que retorna `pendiente`; **pendiente deja pasar** y queda marcado
  distinto en bitácora. La Fase 2 sustituye el binding.
- **Aislamiento de tenant**: `ContextoOrganizacion` (singleton) + global scope
  que **falla cerrado** + middleware que comprueba vinculación antes de aceptar
  `X-Organizacion`.
- **Seeds**: 25 permisos en `CatalogoPermisos` (código, no base), 4 niveles de
  sensibilidad, 4 tipos de organización, 24 plantillas de rol. Se **clonan** al
  crear el tenant.
- **CRUDs web + API v1 espejo** para unidades, agrupaciones, miembros, personas
  (alta con verificación CURP+fecha), **administración de roles** (permisos y
  tope de sensibilidad), alcances con vigencia y **tutorías con su flujo de
  validación**.
- **71 pruebas verdes**: 16 de AccesoService, 15 de aislamiento cross-tenant,
  15 de gestión de roles y 11 de tutorías. Todas comprobadas mutando el código
  que vigilan.

**Sigue la Fase 2** (M4): expediente config-driven y consentimientos. Prompt en
`docs/08-plan-de-fases-y-prompts-claude-code.md`.

### Fase 0 (fundación). Lo que hay:

- Laravel 12 + Inertia + Vue 3 + Vite, con `LayoutAdmin.vue` y la página
  `Panel`. El panel real se arma por tarjetas declaradas con su permiso en la
  Fase 1, no con ramas por rol.
- Spatie permission en modo teams verificado contra la base: las tablas `roles`,
  `model_has_roles` y `model_has_permissions` llevan `organizacion_id`.
- Activitylog, medialibrary y Sanctum instalados y migrados.
- Horizon con cuatro supervisores, uno por cola.
- `app/Domain/` con los nueve dominios y un ServiceProvider cada uno, ya
  registrados en `bootstrap/providers.php`.
- `Controllers/Web` y `Controllers/Api/V1`; `/api/v1` montada desde
  `routes/api/v1.php` con nombres `api.v1.*` y errores RFC 7807.
- Pint, PHPStan nivel 6 (sin baseline) y CI con MySQL 8 + Redis 7.
- 13 pruebas verdes.

## Decisiones tomadas (no re-litigar)

El detalle completo, con el porqué de cada una, está en **`docs/decisiones.md`**.
Las que más se olvidan:

- **`admin-base` no existía.** El Doc 02 §8 la describe como "ya planeada"; no
  hay repo ni referencia en el ecosistema. El shell administrativo se construyó
  aquí siguiendo las convenciones de la casa (mismo stack que `acadion`). Si
  aparece la plantilla real, lo que hay que reconciliar es el layout, no la
  arquitectura.
- **Se almacena en UTC**, no en `America/Mexico_City`. México no tiene una sola
  hora —Tijuana y Mérida están a dos— y Baja California sigue moviendo el reloj.
  Con cronómetros server-side y sellos de tiempo por reactivo, un instante
  ambiguo no se repara después. La zona de presentación es de la organización.
  Ojo: `APP_TIMEZONE` en `.env` **no hace nada** en Laravel 12; el valor está en
  duro en `config/app.php`.
- **`spatie/laravel-activitylog` quedó en 4.12**, no en 5.x: la 5.1 exige PHP
  8.4 y aquí corre 8.3. Al subir PHP se puede actualizar.
- **`config/media-library.php` referencia `TemporaryUpload` como cadena**, no
  con `::class`: Media Library Pro no está instalado y la clase no existe. El
  valor de configuración es idéntico y el análisis estático deja de tropezar.
- **Las pruebas corren en MySQL**, no en SQLite, desde la Fase 0 — aunque
  todavía no haya migraciones que lo exijan. Cambiar de motor a medio proyecto
  es lo que obliga a reescribir migraciones.
- **La relación persona↔cuenta va sólo en `users.persona_id`** (NOT NULL,
  única). El Doc 03 la declara en las dos direcciones; un 1:1 guardado dos veces
  son dos columnas que pueden divergir. Es la única desviación del diccionario.
- **El global scope de tenant falla CERRADO**: sin organización activa no
  devuelve nada. Un seeder, un job o un comando que necesite ver todo lo pide
  con `ContextoOrganizacion::sinRestriccion()`.
- **`personas` es global y no tiene global scope.** Los listados paginan sobre
  `organizacion_personas`, y todo endpoint que reciba un `persona_uuid`
  comprueba la vinculación activa. Consultar `personas` directo devuelve el
  padrón de toda la plataforma.
- **`bitacora` no lleva llaves foráneas** y no se actualiza ni se borra. Tiene
  que sobrevivir al borrado de lo que registra: es la evidencia que la LFPDPPP
  obliga a conservar.
- **El consentimiento "pendiente" DEJA PASAR** hasta la Fase 2, y cada acceso
  concedido así queda en bitácora con motivo propio.

## Entorno local

MySQL de WAMP corriendo. Bases: `mentia` y `mentia_testing` (creadas a mano).

```bash
php artisan serve
npm run dev
```

- **Horizon no corre en Windows**: exige `ext-pcntl` y `ext-posix`. Por eso
  `composer.json` declara el override en `config.platform`; sin él `composer
  install` ni siquiera resuelve. En local las colas van con
  `php artisan queue:listen`, y el `.env` local usa `database` en vez de `redis`
  porque no hay Redis instalado en esta máquina. `.env.example` sí trae la
  configuración real (Redis), que es la del despliegue y la de CI.
- **`public/hot` obsoleto secuestra el frontend.** Si quedó de un `npm run dev`
  muerto, ningún `npm run build` se ve. Si un cambio de Vue no aparece:
  `ls public/hot` y bórralo.

### Comprobar antes de dar algo por hecho

```bash
composer revisa
```

Corre Pint, PHPStan nivel 6 y las pruebas. `npm run build` aparte, porque es lo
único que compila los componentes Vue de verdad.
