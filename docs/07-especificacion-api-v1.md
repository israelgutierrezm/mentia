# MENTIA — Documento 07: Especificación API v1

Versión 1.0 — Agosto 2026
Base: `https://{host}/api/v1` · JSON · UTF-8 · Fechas ISO 8601 con zona · Paginación cursor (`?cursor=&limit=`) · Errores RFC 7807 (`type, title, status, detail, errors{}`) · Idempotencia por `Idempotency-Key` o UUID de recurso donde se indica.

## 1. Autenticación

| Mecanismo | Uso |
|---|---|
| Sanctum token personal (`Authorization: Bearer`) | App Flutter y clientes autenticados; scopes por rol; header `X-Organizacion` selecciona tenant activo |
| Token anónimo de aplicación | Ligas por correo/WhatsApp: `Authorization: Bearer apl_{token}` de un solo uso, ligado a `asignacion_destinatarios`, expira con la ventana |
| Token de integración (máquina) | Sistemas externos (ATS/SIS), scopes restringidos + webhooks |

```
POST /auth/login              → token (usuario+contraseña, 2FA si aplica)
POST /auth/token/refresh
POST /auth/logout
GET  /auth/perfil             → persona, roles, organizaciones disponibles
POST /aplicacion-anonima/canjear   {token} → contexto mínimo de la asignación (sin login)
```

## 2. Organización, personas y expediente

```
GET/POST/PUT      /unidades · /agrupaciones · /agrupaciones/{id}/miembros
GET/POST          /personas                      (alta con verificación CURP+fecha_nac)
POST              /personas/{uuid}/vincular      (reclamar/vincular a tenant)
GET               /personas/{uuid}/linea-vida    (membresías y eventos, según acceso)
GET               /expedientes/{persona_uuid}    (secciones visibles según AccesoService)
PUT               /expedientes/{persona_uuid}/valores        (autollenado; queda pendiente_validacion)
POST              /expedientes/{persona_uuid}/documentos     (multipart; medialibrary)
POST              /expedientes/{persona_uuid}/valores/{id}/validar
GET/POST          /consentimientos               (pendientes, otorgar con evidencia)
POST              /consentimientos/{id}/revocar
GET/POST/DELETE   /comparticiones                (la persona controla su cross-tenant)
GET/POST          /tutorias · POST /tutorias/{id}/validar
```

## 3. Catálogo y habilitación

```
GET  /catalogo/instrumentos?categoria=&dominio=&estatus_licencia=&texto=
GET  /catalogo/instrumentos/{clave}                 (ficha técnica, versiones, escalas)
GET  /tenant/instrumentos                           (estado por tenant)
POST /tenant/instrumentos/{version_id}/habilitar    (DP: directo; LIC: exige declaración)
POST /tenant/instrumentos/{version_id}/declaracion-licencia
POST /tenant/instrumentos/{version_id}/contenido/importar   (plantilla Excel; reporte de errores fila a fila)
GET/POST/PUT /baterias · /baterias/{id}/instrumentos
GET/POST     /propositos
GET/POST/PUT /baremos-propios (tenant) · /perfiles-puesto
```

## 4. Asignaciones

```
POST /asignaciones
     { proposito_id, version_instrumento_id | bateria_id,
       origen: {tipo, agrupacion_id?, incluir_nuevos_miembros?},
       destinatarios: [persona_uuid...],           // individual
       ventana: {inicio, fin}, modo_presentacion, es_discreta?, es_anonima?,
       intentos_permitidos }
GET  /asignaciones?estado=&proposito=
GET  /asignaciones/{folio}                          (detalle + resumen de avance)
GET  /asignaciones/{folio}/destinatarios?estado=    (monitoreo; respeta anonimato)
POST /asignaciones/{folio}/recordatorios
POST /asignaciones/{folio}/cerrar | /cancelar
POST /asignaciones/{folio}/destinatarios/{id}/exentar {motivo}
```

## 5. Aplicación de evaluaciones (contrato de la app)

```
POST /aplicaciones/iniciar
     { asignacion_destinatario_token | asignacion_destinatario_id, instrumento_orden? }
     → { aplicacion_uuid, estructura: {bloques[{clave,titulo,instrucciones,tiempo_limite_seg,
         total_reactivos}]}, bloque_actual, modo_presentacion, recursos_estaticos[] }

GET  /aplicaciones/{uuid}/bloques/{clave}/reactivos?desde=&cantidad=
     → entrega PARCELADA (protección de contenido; saltos resueltos server-side)

POST /aplicaciones/{uuid}/respuestas               // LOTES, idempotente por uuid_cliente
     { respuestas: [{ uuid_cliente, reactivo_codigo, opcion_codigo?, valor_numerico?,
        valor_texto?, rol_ipsativo?, posicion_ranking?, tiempo_respuesta_ms,
        respondida_en }] }
     → { aceptadas, duplicadas, siguientes?: {bloque, reactivo},
         cronometro: {bloque, restante_seg} }        // server-side SIEMPRE
     ▸ Evaluación SÍNCRONA de centinelas del lote → alertas en tiempo real

GET  /aplicaciones/{uuid}/estado                    (reanudación exacta)
POST /aplicaciones/{uuid}/pausar | /reanudar
POST /aplicaciones/{uuid}/finalizar                 → encola pipeline; 202
POST /aplicaciones/{uuid}/media                     (dibujo/audio, multipart)

# Captura de protocolo (instrumentos CAP)
POST /aplicaciones/protocolo
     { persona_uuid, version_instrumento_id, fecha_aplicacion,
       escalas: [{clave, puntaje_bruto, puntaje_escalar?}], observaciones }
```

### Modo offline (V3)
```
GET  /offline/paquetes?asignaciones=...   → estructura+contenido cifrado para almacenamiento local
POST /offline/sincronizar                 → lotes de respuestas con sellos locales firmados;
                                            respuesta por uuid_cliente: aceptada|duplicada|conflicto
```

## 6. Resultados, reportes y alertas

```
GET  /aplicaciones/{uuid}/resultados        (audiencia derivada del rol; jamás por parámetro)
GET  /personas/{uuid}/perfil-longitudinal?dominio=&constructo=&desde=&hasta=
GET  /personas/{uuid}/comparar-puesto/{perfil_puesto_id}
GET  /asignaciones/{folio}/reporte-grupal
POST /reportes/integrador {asignacion_id|persona_uuid+aplicaciones[]}  → 202 (IA en cola)
GET  /reportes/{id}         · POST /reportes/{id}/validar | /rechazar   (firma profesional)
GET  /reportes/{id}/descargar (PDF; pasa por AccesoService + bitácora)

GET  /alertas?estado=&severidad=
POST /alertas/{id}/atender {resolucion}
```

## 7. Webhooks (V2)

Registro por tenant (`POST /webhooks {url, eventos[], secreto}`), firma HMAC-SHA256 en header, reintentos exponenciales.
Eventos: `aplicacion.completada`, `resultado.disponible`, `alerta.creada`, `asignacion.cerrada`, `reporte.validado`, `consentimiento.revocado`.

## 8. Reglas transversales del contrato

1. **Autorización:** todo endpoint sobre datos de persona pasa por AccesoService; 403 con motivo genérico (sin filtrar existencia de recursos sensibles: 404 opaco cuando corresponde).
2. **Cronómetro:** el cliente nunca envía tiempo restante; solo lo muestra. Al expirar el bloque server-side, respuestas tardías se marcan y el bloque se cierra.
3. **Idempotencia:** `uuid_cliente` en respuestas; `Idempotency-Key` en creación de asignaciones y aplicaciones.
4. **Versionado:** cambios incompatibles → `/api/v2`; los contratos v1 se congelan al liberar la app.
5. **Auditoría:** descargas y lecturas de resultados/reportes registran bitácora con propósito.
6. **Localización:** `Accept-Language` (es-MX default); textos de instrumento siempre en el idioma de su versión.
