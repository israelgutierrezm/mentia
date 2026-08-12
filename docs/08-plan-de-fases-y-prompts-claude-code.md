# MENTIA — Documento 08: Plan de Fases y Prompts para Claude Code

Versión 1.0 — Agosto 2026

Metodología: cada fase entrega migraciones + modelos + servicios de dominio + controllers Web (Inertia) y Api/V1 + tests de feature (incluyendo pruebas de aislamiento de tenant) + seeders. Regla permanente: **controllers delgados, lógica en `app/Domain/`, cero JSON en dominio, todo endpoint sensible pasa por AccesoService.**

Antes de la Fase 1, coloca los ocho documentos de esta suite en `/docs` del repositorio; cada prompt los referencia.

---

## FASE 0 — Fundación del proyecto

**Alcance:** Laravel 12 + Inertia + Vue 3 + Vite sobre `admin-base`; MySQL 8; Redis + Horizon; Sanctum; spatie/permission (teams), activitylog, medialibrary; estructura `app/Domain/`; pipeline de CI con Pint/PHPStan/tests; convenciones en `/docs/convenciones.md`.

**Prompt Claude Code:**
> Lee /docs/01, /docs/02 y /docs/convenciones.md. Inicializa un proyecto Laravel 12 con Inertia+Vue3+Vite partiendo de la plantilla admin-base. Instala y configura: sanctum, spatie/laravel-permission EN MODO TEAMS con team_foreign_key='organizacion_id', spatie/laravel-activitylog, spatie/laravel-medialibrary, Horizon con colas calificacion, alertas, notificaciones, reportes-ia. Crea la estructura app/Domain/{Organizaciones,Personas,Accesos,Expedientes,Catalogo,Evaluaciones,Interpretacion,Alertas,Consentimientos} con un ServiceProvider por dominio. Configura Controllers/Web y Controllers/Api/V1 con rutas versionadas /api/v1. Agrega PHPStan nivel 6, Pint y un workflow de CI que corra tests. No implementes lógica de negocio todavía. Todo en español mexicano en nombres de dominio visibles.

## FASE 1 — Organizaciones, personas y accesos (M1–M3)

**Alcance:** tenants, tipos, unidades jerárquicas, tipos_agrupacion, agrupaciones y membresías con vigencia; personas globales (CURP), users, organizacion_personas, tutorías; permisos seed, plantillas de rol, persona_rol_alcances, niveles de sensibilidad, rol_sensibilidad_max; **AccesoService** (4 dimensiones, consentimiento aún como stub que retorna pendiente) + bitácora append-only; middleware de tenant y global scopes; pantallas de administración.

**Prompt:**
> Lee /docs/03 (M1, M2, M3) y /docs/06. Implementa las migraciones y modelos exactamente como el diccionario de datos. Implementa AccesoService::autorizar(actor, accion, sujeto, recurso, proposito) con las 4 verificaciones en cortocircuito (la de consentimiento como interfaz con implementación provisional) y registro en bitacora en toda decisión; bitacora sin UPDATE/DELETE. Crea el seed de permisos del sistema y plantillas de rol por tipo_organizacion con su clonación al crear tenant. Middleware de resolución de tenant + global scopes + suite de tests de aislamiento cross-tenant que intente fugas en todos los endpoints creados. CRUDs Inertia y endpoints /api/v1 espejo para unidades, agrupaciones, miembros, personas (alta con verificación CURP+fecha), roles con alcances y vigencias. Tests de feature de AccesoService cubriendo: alcance por unidad con descendientes, membresía vencida, sensibilidad insuficiente, rol vencido.

## FASE 2 — Expediente y consentimientos (M4)

**Alcance:** expediente config-driven (secciones/campos/valores versionados con validación), documentos con medialibrary, notas profesionales cifradas, textos de consentimiento versionados con hash, consentimientos con evidencia y revocación, comparticiones cross-tenant, transición de mayoría de edad (job), portal de autollenado titular/tutor; conectar la verificación real de consentimiento en AccesoService.

**Prompt:**
> Lee /docs/03 (M4) y /docs/06. Implementa el módulo de expediente: campos config-driven (una fila por campo, una fila por valor, versionado, estados de validación), documentos tipificados con medialibrary, notas_profesionales con cast cifrado y visibilidad autor/nivel4. Implementa textos_consentimiento versionados con hash SHA-256, consentimientos (titular/tutor, evidencia, vigencia, revocación) y comparticiones_expediente. Sustituye el stub de consentimiento de AccesoService por la verificación real por tipo/propósito/tenant. Job diario de vencimientos y job de transición de mayoría de edad (tutorías → extinta_mayoria_edad, consentimientos de tutor → pendiente_reconsentimiento, bloqueo de acceso de terceros hasta re-consentir). Vistas Inertia: expediente por secciones según acceso, portal de autollenado para titular/tutor con flujo de validación. Endpoints /api/v1 del Doc 07 §2. Tests: menor→mayoría de edad, revocación con efecto inmediato, tutor no validado sin acceso.

## FASE 3 — Catálogo de instrumentos (M5)

**Alcance:** taxonomía, dominios, instrumentos con estatus de licencia, versiones inmutables, escalas, bloques, tipos de reactivo, reactivos/opciones (con organizacion_id_contenido), claves (incluido rol ipsativo), reglas de salto, fórmulas derivadas, poblaciones, baremos en capas + filas, reglas de interpretación (+condiciones), perfiles tipo, ocupaciones, tenant_instrumentos con declaración de licencia, importador Excel con reporte fila a fila; administración del catálogo (plataforma) y habilitación (tenant).

**Prompt:**
> Lee /docs/03 (M5), /docs/04 y /docs/05 §2. Implementa el catálogo completo conforme al diccionario, con versiones_instrumento inmutables tras publicación (bloqueo de escritura de contenido a nivel de servicio y test). Implementa tenant_instrumentos con los flujos: habilitar DP directo, LIC con declaración firmada y captura de contenido privado (organizacion_id_contenido; scope + test de que jamás se sirve a otro tenant). Construye el importador desde la plantilla Excel definida en /docs/04 (hojas instrumento/escalas/bloques/reactivos/opciones/claves/baremos/interpretaciones) con validación y reporte de errores fila a fila. Panel Inertia de catálogo global (rol plataforma) y de habilitación (rol tenant). Endpoints Doc 07 §3. Tests con un instrumento sintético que use cada tipo de reactivo.

## FASE 4 — Seed Ola 1

**Alcance:** seeders idempotentes de contenido completo: NOM-035 GR-I/II/III (reactivos DOF, dominios, categorías, cortes, semáforos), O*NET RIASEC + crosswalk de ocupaciones, PHQ-9/2, GAD-7/2, WHO-5, PSS, AUDIT, Zung ×2, M-CHAT-R/F (2 etapas), AQ-10, Big Five IPIP-120, DISC propio, banco clima/eNPS; centinelas de PHQ-9 y C-SSRS screener; interpretaciones por audiencia de todos.

**Prompt:**
> Lee /docs/04 (Ola 1) y /docs/05. A partir de los archivos de datos en /database/seeds/instrumentos/ (te entrego los textos como archivos de datos versionados), crea seeders idempotentes para cada instrumento de la Ola 1 con: bloques, reactivos, opciones, claves, fórmulas derivadas, baremos/cortes oficiales, reglas de interpretación en las 4 audiencias y banderas. Marca es_centinela y centinela_condiciones en PHQ-9 reactivo 9 y en el C-SSRS screener. Incluye para NOM-035 la estructura de cortes por dominio/categoría/calificación final. Agrega un comando artisan mentia:seed-instrumentos {clave?} y tests que califiquen casos dorados (fixtures de respuestas con resultado esperado) por instrumento.

*(Nota: los textos finales de reactivos DP y las interpretaciones se preparan como archivos de datos revisados antes de esta fase; puedo generarlos contigo instrumento por instrumento.)*

## FASE 5 — Asignaciones y baterías (M6)

**Alcance:** baterías + editor, propósitos plantilla, asignaciones individuales/grupales/campañas (snapshot vs dinámica), destinatarios con tokens de un solo uso, discretas y anónimas, notificaciones (correo; abstracción de canal para WhatsApp), recordatorios, exenciones, monitoreo de avance.

**Prompt:**
> Lee /docs/03 (M6) y /docs/07 §4. Implementa baterías (editor Inertia arrastrable), propósitos y asignaciones conforme al diccionario, con CHECK de instrumento XOR batería. Expansión de agrupación a destinatarios respetando membresías vigentes y flag incluir_nuevos_miembros (listener de altas). Tokens char(64) de un solo uso con expiración por ventana. Flujos es_discreta (visibilidad restringida) y es_anonima (aplicaciones sin persona_id, irreversible, con atributos demográficos configurados). Notificaciones por correo con plantillas y canal abstraído, recordatorios manuales y programados, exenciones con motivo. Dashboard de avance por asignación (respeta anonimato: solo conteos). Endpoints Doc 07 §4 y tests: dinámica vs snapshot, token expirado, doble uso de token, asignación discreta invisible para rol no autorizado.

## FASE 6 — Motor de aplicación web (M7)

**Alcance:** sesiones de aplicación, entrega parcelada, respuestas por lotes idempotentes con tiempo por reactivo, cronómetros server-side por bloque, pausas/reanudación, saltos server-side, bloques de práctica, componentes Vue por tipo de reactivo, modos de presentación (adulto/adolescente/informante/examinador; infantil básico), canje de token anónimo, captura de protocolo (CAP), evaluación síncrona de centinelas (stub de notificación).

**Prompt:**
> Lee /docs/03 (M7), /docs/05 §3 y /docs/07 §5. Implementa el motor de aplicación: iniciar (estructura sin reactivos), entrega parcelada por bloque, POST de respuestas por lotes idempotente por uuid_cliente con tiempo_respuesta_ms y evaluación síncrona de centinelas del lote (servicio AlertaService con notificación stub), cronómetro calculado siempre server-side desde aplicacion_bloques, pausar/reanudar/estado exacto, reglas_salto resueltas en servidor, bloques de práctica con validación de comprensión, finalizar → job de calificación (stub). Frontend Vue: un componente por tipo_reactivo de la Fase 3, layout por modo_presentacion con tema adulto completo, adolescente e informante, e infantil en versión básica (botones grandes, audio TTS del navegador, refuerzos). Pantalla de canje de token anónimo sin login. Formulario de captura de protocolo para instrumentos CAP. Tests: idempotencia de lotes, expiración de bloque con respuestas tardías marcadas, reanudación exacta, salto condicionado, centinela dispara alerta con la aplicación en curso.

## FASE 7 — Pipeline de calificación e interpretación (M8)

**Alcance:** las 6 etapas con registro de estrategias (ScoringStrategyRegistry), estrategias de la Ola 1 (sumas, inversos, conteo_criterio, mchat_dos_etapas, nom035_cortes, audit_zonas, phq_gravedad, ipsativo para DISC propio), resolución de baremos en capas por edad congelada, interpretaciones por audiencia con variables, resultados_normalizados (expediente longitudinal), validez_detalle, comparadores (puesto/sí misma/grupo).

**Prompt:**
> Lee /docs/05 completo y /docs/03 (M8). Implementa el pipeline en jobs encadenados sobre la cola calificacion: Validez → Brutos → AlgoritmosEspeciales → Normalización → Interpretación → Banderas, con instrumento_pipeline como configuración y ScoringStrategyRegistry extensible. Implementa las estrategias listadas para la Ola 1 con tests de casos dorados por estrategia. Resolución de baremo: agrupación→tenant→nacional→global, filtrando por edad_meses_al_aplicar, sexo y escolaridad; resultado sin_norma cuando no aplique. Persiste resultados_escala, resultados_interpretacion (4 audiencias, variables resueltas), resultados_normalizados y validez_detalle. Implementa comparador persona-puesto (% de ajuste ponderado) y detección de cambio significativo entre mediciones (umbral por constructo). Recalificación administrativa: comando que re-ejecuta el pipeline de una aplicación conservando histórico.

## FASE 8 — Alertas, protocolos y resultados en UI (M9 + vistas)

**Alcance:** AlertaService completo (destinatarios por rol/canal, correo real, centro de alertas, atención con resolución obligatoria), requisito de protocolo de actuación por tenant para habilitar centinelas, protocolo_reglas (escalonamiento automático con rastro), vistas de resultados por audiencia (perfil gráfico, longitudinal "ficha hospital" con series por dominio/constructo), mensajes de cierre cuidados para sensibilidad 3–4.

**Prompt:**
> Lee /docs/03 (M9), /docs/05 §3 y §6, /docs/06 §5. Completa AlertaService: alerta_destinatarios por tipo/severidad/rol/canal, notificación inmediata por correo y campana in-app, centro de alertas con estados y resolución obligatoria para cerrar, y bloqueo de asignación de instrumentos con centinelas si el tenant no ha registrado su protocolo de actuación. Implementa protocolo_reglas con las acciones asignar_instrumento/asignar_bateria/notificar_rol/marcar_seguimiento, siempre notificando al responsable y dejando bitácora. Vistas Inertia de resultados: individual por audiencia con gráfica de perfil por escalas, y perfil longitudinal por persona (dominios como tarjetas, serie temporal de resultados_normalizados por constructo, banderas). Mensajes de cierre configurables con recursos de apoyo para instrumentos sensibilidad 3–4. Tests: cadena M-CHAT riesgo medio → asignación automática de etapa 2 → notificación.

## FASE 9 — Reportes, IA y endurecimiento (M10)

**Alcance:** plantillas de reporte, PDF server-side, reporte individual/grupal/NOM-035/longitudinal, integrador con IA (Anthropic API, insumo pseudonimizado, borrador→validación→firma), descarga auditada; módulo ARCO; endurecimiento (2FA roles 3–4, rate limiting, revisión de cifrado, pruebas de fuga), documentación de despliegue.

**Prompt:**
> Lee /docs/05 §5-§6, /docs/06 y /docs/07 §6. Implementa plantillas_reporte y generación PDF server-side (HTML→PDF) para: individual por audiencia, grupal por asignación (distribuciones y semáforos), NOM-035 por centro de trabajo con formato oficial, y longitudinal por persona. Implementa el integrador con IA: servicio que arma el insumo pseudonimizado desde resultados ya interpretados, llama a la API de Anthropic con el prompt de sistema versionado en /config/ia/, guarda reportes_ia como borrador y exige validación con permiso ia.validar_reportes y firma antes de liberar. Descargas vía AccesoService con bitácora. Módulo de solicitudes ARCO (alta, seguimiento, exportación de expediente). Endurecimiento: 2FA obligatoria para roles con sensibilidad ≥3, rate limiting de API, revisión de casts cifrados, y ampliación de la suite de aislamiento/fugas. Prepara /docs/despliegue.md (requisitos, variables, colas, backups).

## FASE 10 (V3) — App Flutter

**Alcance:** cliente puro de API v1: login + canje de token anónimo, renderizadores de tipos de reactivo, modo infantil completo (animaciones, mascota, audio), modo kiosco multi-aplicación, offline (paquetes cifrados, cola local, sincronización idempotente), notificaciones push.

**Prompt (resumen; se detalla al llegar):**
> Lee /docs/07. Crea la app Flutter con arquitectura limpia (data/domain/presentation), cliente Dio contra /api/v1, almacenamiento seguro de tokens, renderizadores por tipo_reactivo equivalentes a los componentes Vue, temas por modo_presentacion con modo infantil completo, kiosco, y capa offline: descarga de paquetes cifrados, base local (Drift), cola de respuestas con uuid_cliente y sincronización con manejo de conflictos según contrato /offline/sincronizar.

---

## Dependencias y ruta crítica

```
F0 → F1 → F2 ─┐
        F3 ───┼→ F5 → F6 → F7 → F8 → F9 → (V3) F10
        F4 ───┘         (F4 puede correr en paralelo desde F3)
```

## Definición de terminado (por fase)

- Migraciones conforme al Doc 03 sin desviaciones no documentadas.
- Tests verdes incluyendo aislamiento de tenant y casos dorados de calificación.
- Endpoints API v1 de la fase implementados y documentados.
- Bitácora verificada en operaciones sensibles nuevas.
- Textos de UI en español mexicano con acentuación completa.
