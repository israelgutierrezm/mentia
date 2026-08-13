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

**Fases 0, 1, 2, 3, 5, 6, 7, 8 y 9 completas.** 304 pruebas verdes, Pint limpio,
PHPStan nivel 6 sin errores, `npm run build` OK.

**El panel ya existe** y hay pantalla de entrar: los dos pendientes que
arrastraban todas las fases quedaron cerrados. `CatalogoSecciones` declara cada
sección con su permiso y el middleware comparte la prop `menu` filtrada.

**Fase 2 (M4) — expediente y consentimientos:**

- Expediente **config-driven** con secciones que declaran su sensibilidad;
  agregar un campo es una fila, no una migración.
- **Versionado**: corregir no pisa, versiona. El vigente es la mayor versión
  **validada** — una corrección sin validar no desplaza al dato que la
  organización ya dio por bueno.
- `VistaExpediente` filtra **sección por sección** pasando cada una por
  AccesoService: lo que el rol no alcanza no sale del servidor.
- Notas profesionales cifradas (probado contra la columna cruda) y nunca
  visibles para el titular.
- Textos de consentimiento **versionados con hash SHA-256 e inmutables**; el
  hash detecta un UPDATE hecho por fuera de la aplicación y entonces no se
  firma.
- **El stub de consentimiento ya no existe**: `VerificadorConsentimiento` es la
  implementación real. Cambió una línea del ServiceProvider.
- Jobs de mayoría de edad y de vencimientos, con `Schedule` en
  `routes/console.php`.
- Portal `/mi-expediente` del titular y endpoints `/api/v1` del Doc 07 §2.

**Fase 3 (M5) — catálogo de instrumentos:**

- 21 tablas y 21 modelos. Todo global salvo `tenant_instrumentos`.
- `PublicadorVersion::exigirEditable()` es la comprobación que TODO servicio de
  contenido debe hacer antes de escribir. Publicar valida que haya reactivos,
  que ninguna escala quede sin claves y que las fórmulas sólo citen escalas
  existentes; las fórmulas se validan con **lista blanca**, nunca con `eval()`.
- Corregir una versión publicada es **clonarla** a un borrador.
- `Reactivo::scopeDeContenidoVisiblePara()` es el punto único del contenido
  privado: lo que un tenant capturó bajo su licencia nunca se sirve a otro.
- Importador de la plantilla Excel del Doc 04 con **reporte hoja/fila/columna**
  y rollback total ante un solo error.
- Paneles de catálogo y de habilitación, más los endpoints del Doc 07 §3.

**Fase 5 (M6) — asignaciones y baterías:**

- CHECK en la BASE de instrumento XOR batería y de ventana coherente, no sólo
  en el servicio. Probadas las dos capas.
- Tokens de 32 bytes guardados **hasheados**, de un solo uso, con canje bajo
  `lockForUpdate`. Expiran con la **ventana** de su asignación; cerrarla los
  invalida al instante.
- Dinámica vs snapshot: `incluir_nuevos_miembros` + listener del evento
  `PersonaInscritaEnAgrupacion`. La expansión respeta membresías vigentes.
- Discretas: sólo las ve quien las creó y los roles de nivel 4. Por API
  responden **404, no 403** — un 403 confirmaría que el folio existe.
- Anónimas: el avance da **sólo conteos**; el detalle por persona lanza
  excepción y la API responde 409 con el agregado.
- Canal de notificación abstraído (`CanalNotificacion`), correo en la V1. El
  Mailable **no se encola**: lleva el token en claro.
- **Editor de baterías arrastrable** (`vuedraggable`). Sólo admite
  instrumentos habilitados para la organización, y una batería con
  asignaciones activas no se reordena.
- **Recordatorios programados** con cadencia mínima, tope y excepción del
  último día. El job corre con `sinRestriccion()` porque los global scopes
  fallan cerrado.

**Fase 6 (M7) — motor de aplicación:**

- **Contenido parcelado y cronómetro server-side**, las dos reglas que gobiernan
  `MotorAplicacion`. `iniciar()` entrega la estructura sin ningún enunciado; los
  reactivos salen bloque por bloque con tope de 50. El reloj del bloque arranca
  cuando se piden sus reactivos, no al iniciar la aplicación.
- `iniciar()` es **idempotente**: recargar reanuda, no empieza de cero.
- **Saltos resueltos en el servidor** (`ResolutorSaltos`): el árbol de reglas
  nunca sale al cliente.
- Respuestas **por lotes, idempotentes por `uuid_cliente`**. Cambiar de
  respuesta **corrige la fila**; en ranking e ipsativos se reemplaza el conjunto
  completo. Gana la más reciente por `respondida_en`, no por orden de llegada.
- **Centinelas síncronos** dentro de la petición del lote → `AlertaService`.
  Todo lo demás del pipeline es asíncrono.
- El **token vuelve a entrar mientras la aplicación sigue en curso**; muere al
  completarse. La liga lleva el token en el **fragmento** (`/contestar#…`), que
  no llega al servidor.
- Frontend: un componente por familia de `tipo_reactivo`, modos declarados en
  datos (`Aplicacion/modos.js`), layout propio sin el shell de administración,
  **bandeja de salida** con reintento, pausa y reanudación sin volver a pedir el
  token. Los tipos sin componente se dibujan con un aviso visible.
- Páginas Inertia **por trozos** (`import.meta.glob` sin `eager`): la pantalla
  pública pasó de 540 kB a 19 kB.
- Captura de protocolo para instrumentos que la editorial no permite en línea,
  en `/captura-protocolo` y en la API §5.

**Fase 7 (M8) — pipeline de calificación:**

- **El pipeline se describe, no se programa.** `instrumento_pipeline` +
  parámetros en tabla hija dicen qué etapas corre cada versión y con qué
  estrategia. `RegistroEstrategias` falla ruidoso ante una clave desconocida o
  una estrategia puesta en la etapa equivocada.
- **Seis jobs encadenados** en la cola `calificacion`; cada uno reconstruye su
  contexto desde la base. Nada de datos de expediente en la tabla `jobs`.
- Estrategias: `omisiones_max`, `patron_repetido`, `tiempo_atipico`;
  `suma_simple`, `suma_ponderada`, `conteo_correctas` (con corrección por
  adivinanza), `conteo_ipsativo`, `ranking_ponderado`, `conteo_criterio`;
  `phq_gravedad`, `audit_zonas`, `nom035_cortes`, `mchat_dos_etapas`.
- **Una aplicación inválida NO se califica** (configurable). `dudosa` sigue con
  advertencia.
- **Lo que clasificó un algoritmo oficial no se re-normaliza.** La etapa de
  brutos limpia la normalización al reescribir un bruto, o una recalificación
  dejaría el bruto nuevo con la norma vieja al lado.
- Baremos **tenant → nacional → global** con edad congelada; sin baremo aplicable
  se marca `sin_norma` y no entra a la serie longitudinal.
- **Fórmulas con parser propio**, jamás `eval()`.
- Interpretación por audiencia con variables resueltas; perfiles tipo por
  condiciones o por las N escalas más altas (RIASEC, DISC), con desempate
  estable por orden de escala.
- `resultados_normalizados` es la serie del expediente de vida; las banderas se
  copian ahí y ante dos gana la más grave.
- **La audiencia se deriva del rol, nunca por parámetro**; sin
  `resultados.ver_detalle` salen interpretaciones y no puntajes.
- Comparadores: ajuste a puesto ponderado (los criterios sin dato no cuentan
  como fallo) y cambio significativo por constructo con umbral configurable.
- **Recalificación que archiva primero** (`mentia:recalificar --aplicacion=UUID`
  o `--instrumento=ID`) y endpoints del Doc 07 §6.
- 39 pruebas nuevas, casi todas casos dorados.

**Fase 8 (M9) — alertas, protocolos y vistas:**

- **Sin protocolo de actuación registrado NO se asigna un instrumento con
  centinelas.** Es la única comprobación del sistema que protege a quien
  contesta, no a los datos. Va en la creación de la asignación, no al habilitar.
- Destinatarios **por rol, no por persona**: una lista de correos se queda
  apuntando a quien renunció hace dos años.
- El correo de alerta **no lleva el contenido de la respuesta**, ni el nombre de
  la persona, ni el del instrumento. No se encola: llegaría tarde.
- **Cerrar una alerta exige decir qué se hizo** (mínimo 20 caracteres). En el
  centro, las críticas arriba y las más VIEJAS primero.
- `protocolo_reglas` con escalonamiento automático: asignar segunda etapa,
  notificar rol, marcar seguimiento. **Nada pasa en silencio** — alerta +
  bitácora en cada acción, y `protocolo_ejecuciones` impide que recalificar
  vuelva a dispararlo. `RegistroBitacora::registrarAccion()` es el registro sin
  actor humano.
- El escalonamiento **hereda propósito y autor de la asignación original**; no
  depende de un propósito con clave mágica.
- Mensaje de cierre con **recursos de apoyo configurables**, sólo en instrumentos
  de sensibilidad 3–4.
- Vistas: centro de alertas, resultado individual por audiencia con perfil por
  escalas, y perfil longitudinal agrupado **por dominio** con SVG a mano.
- API §6 de alertas. El stub `NotificacionRegistrada` ya no existe.

**Fase 9 (M10) — reportes, IA, ARCO y endurecimiento:**

- **El PDF se guarda, no se regenera** (dompdf, sin acceso remoto ni PHP). Un
  reporte es un documento entregado: si el catálogo cambia, el papel que alguien
  tiene en la mano tiene que seguir explicándose.
- Plantillas con **sustitución, nunca compilación**, y todo escapado. Una
  plantilla editable que el servidor compilara sería ejecución de código.
- **Insumo de IA pseudonimizado** garantizado por `ArmadorInsumoIA`: sin nombre,
  CURP, fecha de nacimiento ni respuestas crudas. Prompt versionado en
  `config/ia.php`, no en la base.
- El **borrador nace como borrador**; firmar sin validar está prohibido en el
  código. Rechazar exige decir por qué; quien valida puede corregir el texto.
- **ARCO con plazos** calculados al recibir y guardados. Improcedente exige
  documentar la excepción. El cálculo de días hábiles salta fines de semana,
  **no** los asuetos de la LFT — queda corto a propósito.
- **2FA obligatoria para roles de sensibilidad ≥3**, en web y en API. Bloquea, no
  sugiere; el umbral no baja de 3. Códigos de recuperación de un solo uso.
- `docs/despliegue.md` con requisitos, variables, colas, cron, respaldos y lo que
  falta.

**Sigue la Fase 4** cuando haya contenido de instrumentos, o la **Fase 10**
(Flutter, repo aparte).

**Dos bloqueos estructurales más adelante, ya identificados:**

- **La Fase 4 no se puede hacer sin contenido.** El Doc 08 dice que los textos
  de reactivos e interpretaciones "se entregan como archivos de datos
  versionados" en `/database/seeds/instrumentos/`, y ese directorio no existe.
  Los reactivos del PHQ-9, del M-CHAT-R/F y de las Guías de Referencia de la
  NOM-035 **no se inventan**: un instrumento sembrado con ítems aproximados
  produce puntajes que parecen válidos y no lo son. Lo que sí se puede
  construir sin ellos es la maquinaria: el importador de Excel del Doc 04, los
  seeders y el arnés de casos dorados.
- **La Fase 10 es un proyecto Flutter aparte**, en Dart. No vive en este repo.

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
