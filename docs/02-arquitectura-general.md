# MENTIA — Documento 02: Arquitectura General

Versión 1.0 — Agosto 2026

---

## 1. Stack tecnológico

| Capa | Tecnología | Notas |
|------|-----------|-------|
| Backend | Laravel 12 (PHP 8.3+) | Dominio compartido web/API |
| Base de datos | MySQL 8 | InnoDB, utf8mb4, cero JSON en dominio |
| Frontend web | Vue 3 + Inertia.js + Vite | Panel administrativo y aplicación web de evaluaciones |
| Roles y permisos | spatie/laravel-permission (modo **teams**) | `team_id` = `organizacion_id`; capa propia de alcances encima |
| Auditoría | spatie/laravel-activitylog (extendido) | Bitácora inmutable |
| Archivos | spatie/laravel-medialibrary | Documentos de expediente, evidencias |
| Autenticación API | Laravel Sanctum | Tokens app + tokens anónimos de aplicación |
| Colas | Redis + Horizon | Pipeline de calificación, notificaciones, alertas |
| App móvil | Flutter (Dart) | Cliente puro de API v1; V3 |
| IA | API de Anthropic (Claude) | Redacción de reportes integradores sobre resultados ya interpretados |

## 2. Arquitectura de capas (API-first)

```
┌──────────────────────────────────────────────────────────┐
│  CLIENTES                                                │
│  Web (Vue/Inertia) · App Flutter · Liga anónima · API 3os│
└───────────────┬──────────────────────────────────────────┘
                │
┌───────────────▼──────────────────────────────────────────┐
│  HTTP                                                    │
│  Controllers/Web (Inertia, delgados)                     │
│  Controllers/Api/V1 (REST, delgados, Resources)          │
│  Middleware: tenant, sanctum, alcance                    │
└───────────────┬──────────────────────────────────────────┘
                │  (ambos llaman a los MISMOS servicios)
┌───────────────▼──────────────────────────────────────────┐
│  DOMINIO  app/Domain/                                    │
│  ├── Catalogo/        instrumentos, versiones, baremos   │
│  ├── Evaluaciones/    asignaciones, aplicaciones, motor  │
│  ├── Interpretacion/  reglas, perfiles tipo, reportes    │
│  ├── Expedientes/     secciones, campos, documentos      │
│  ├── Accesos/         AccesoService (punto único)        │
│  ├── Consentimientos/                                    │
│  ├── Alertas/         centinelas, protocolos             │
│  ├── Organizaciones/  tenants, unidades, agrupaciones    │
│  └── Personas/        identidad global, tutorías         │
└───────────────┬──────────────────────────────────────────┘
                │
┌───────────────▼──────────────────────────────────────────┐
│  INFRAESTRUCTURA                                         │
│  MySQL 8 · Redis (colas/cache) · Storage · Anthropic API │
└──────────────────────────────────────────────────────────┘
```

Reglas de la arquitectura:

1. **Los controllers no contienen lógica de negocio.** Reciben request → llaman servicio de dominio → devuelven Inertia page o API Resource.
2. **AccesoService es el único punto de autorización** para recursos de personas: `autorizar(actor, accion, sujeto, recurso, proposito)`. Internamente resuelve Spatie (permiso) + alcance + sensibilidad + consentimiento, y registra bitácora en el mismo acto.
3. **API versionada** (`/api/v1/`) desde el primer módulo; cada módulo web entrega sus endpoints y tests en la misma fase.
4. **El pipeline de calificación corre en cola** (jobs encadenados); solo la evaluación de reactivos centinela es síncrona en la recepción de respuestas.

## 3. Multi-tenancy

- **Modelo:** base de datos única, discriminador `organizacion_id` en todas las tablas de tenant, con global scopes de Eloquent + middleware de resolución de tenant.
- **Spatie en modo teams:** `team_foreign_key = organizacion_id`. La misma persona puede tener roles distintos en tenants distintos.
- **Entidades globales (sin tenant):** `personas`, catálogo de instrumentos y todo su contenido, tipos de reactivo, niveles de sensibilidad, permisos del sistema, plantillas de rol.
- **Entidades de tenant:** unidades, agrupaciones, asignaciones, aplicaciones, resultados, expediente-valores capturados en su contexto, roles instanciados, instrumentos propios, baremos propios.
- **Persona global cross-tenant:** la vinculación de una persona a un tenant ocurre vía `organizacion_personas` (alta con verificación de identidad CURP + fecha de nacimiento, y flujo "reclamar mi expediente"). Los datos generados en un tenant pertenecen al contexto de ese tenant; su visibilidad hacia otros tenants la controla exclusivamente la persona mediante consentimientos de compartición.
- **Vocabulario por tipo de tenant:** `tipo_organizacion` (escuela, empresa, consultorio, dependencia) ajusta etiquetas de UI y plantillas precargadas (alumnos/grupos vs. colaboradores/vacantes) sin cambiar el esquema.

## 4. Módulos del sistema

| # | Módulo | Contenido |
|---|--------|-----------|
| M1 | Organizaciones | Tenants, unidades (jerarquía), agrupaciones tipificadas, membresías con vigencia |
| M2 | Personas e identidad | Persona global, tutorías, vinculación a tenants, transición mayoría de edad |
| M3 | Accesos | Spatie teams + alcances + sensibilidad + AccesoService + bitácora |
| M4 | Expediente | Secciones config-driven, campos/valores, documentos, notas profesionales, consentimientos |
| M5 | Catálogo | Categorías, instrumentos, versiones, escalas, bloques, reactivos, opciones, claves, baremos, licenciamiento |
| M6 | Asignaciones | Órdenes individuales/grupales/campañas, destinatarios, plantillas de propósito, baterías |
| M7 | Aplicación | Sesiones de respuesta, estado y reanudación, cronómetros server-side, entrega parcelada, modos de presentación |
| M8 | Calificación | Pipeline: validez → brutos → algoritmos → normalización → interpretación → banderas |
| M9 | Alertas y protocolos | Reactivos centinela, notificación urgente, reglas escalonadas |
| M10 | Reportes | Individual por audiencia, integrador de batería (IA), grupal, longitudinal, comparadores |
| M11 | API v1 | Contratos REST, tokens anónimos, sincronización offline, webhooks |

## 5. Flujo maestro de una evaluación

```
Asignación (admin/psicólogo, individual o grupal)
  → Verificación de consentimiento (existente o solicitud al titular/tutor)
    → Notificación (correo / WhatsApp / app) con token de un solo uso
      → Aplicación (web o app; modo de presentación según perfil de edad)
        → Respuestas por lotes (idempotentes, timestamp por reactivo)
          → [síncrono] Evaluación de reactivos centinela → alerta inmediata si aplica
        → Finalización
          → [cola] Pipeline de calificación (6 etapas)
            → Resultados por escala + normalizados + interpretaciones por audiencia
              → Banderas / protocolos escalonados (asignación automática de 2a etapa)
                → Reportes (individual, grupal, integrador con IA pendiente de validación)
                  → Expediente longitudinal actualizado (dominio correspondiente)
```

## 6. Modos de presentación (skins del motor de aplicación)

El reactivo es el mismo; la experiencia se configura por `modo_presentacion` en la asignación:

| Modo | Audiencia | Características |
|------|-----------|-----------------|
| infantil | 3–8 años | Mascota guía, animaciones, audio TTS del reactivo, botones grandes, refuerzos positivos, sesiones cortas |
| adolescente | 9–17 | Visual, barra de progreso, lenguaje cercano, gamificación ligera |
| adulto | 18+ | Sobrio, instrucciones formales, cronómetro visible cuando aplica |
| informante | Padre/maestro | Encabezado "sobre [nombre del menor]", redacción en tercera persona |
| examinador | Profesional | Formularios de captura de protocolo, campos de observación |
| kiosco | Presencial | Multi-aplicación secuencial en un mismo dispositivo, sesión supervisada, bloqueo de salida |

## 7. Infraestructura y no funcionales

- **Cifrado:** TLS en tránsito; cifrado a nivel aplicación (Laravel encrypted casts) para respuestas y resultados de sensibilidad 3–4; llaves rotables por tenant en V2.
- **Escala de datos:** `respuestas` es la tabla crítica (proyección: decenas de millones de filas). Índices compuestos `(aplicacion_id, reactivo_id)`, `(persona_id, creado_en)`; estrategia de archivado frío por año; sin FK hacia tablas calientes desde reportería (se lee de tablas de resultados).
- **Colas:** Horizon con colas separadas `calificacion`, `alertas` (prioridad máxima), `notificaciones`, `reportes-ia`.
- **Cronómetros server-side:** el tiempo restante siempre se calcula en servidor a partir de `iniciado_en` + duración del bloque; el cliente solo lo muestra.
- **Offline (V3):** paquete de asignación descargable (estructura + contenido cifrado local), respuestas con UUID cliente y firmas de tiempo local, sincronización idempotente con auditoría de reloj.
- **Observabilidad:** logs estructurados, métricas de pipeline (tiempo de calificación, tasa de aplicaciones inválidas), alertas de operación.

## 8. Integración con el ecosistema propio

- **Proctorion:** aplicación supervisada de instrumentos con flag `requiere_supervision` (V4).
- **Acadion:** consumo de API v1 para tamizajes y vocacional dentro del contexto escolar (tenant escuela).
- **admin-base:** el panel parte de la fundación administrativa reutilizable ya planeada (plantilla, cambio de rol, layout).
