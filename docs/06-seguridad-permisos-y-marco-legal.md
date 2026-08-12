# MENTIA — Documento 06: Seguridad, Permisos y Marco Legal

Versión 1.0 — Agosto 2026

---

## 1. Modelo de autorización de cuatro dimensiones

Toda operación sobre datos de una persona se resuelve en un único servicio:

```php
AccesoService::autorizar(
    actor:    Persona,        // quién solicita
    accion:   string,         // permiso Spatie: 'resultados.ver_detalle'
    sujeto:   Persona,        // sobre quién
    recurso:  ?Model,         // qué (resultado, expediente, documento...)
    proposito:?Proposito      // para qué
): DecisionAcceso            // permitido/denegado + motivo + registro en bitácora
```

Orden de verificación (cortocircuito al primer fallo):

1. **Permiso (Spatie, teams):** `$actor->hasPermissionTo($accion)` en el tenant activo.
2. **Alcance:** existe fila vigente en `persona_rol_alcances` cuyo ámbito contiene al sujeto (organización completa, unidad —incluyendo descendientes—, agrupación con membresía vigente, o persona específica). El titular y el tutor vigente tienen alcance implícito sobre sí mismos / su tutelado.
3. **Sensibilidad:** `nivel_sensibilidad` del recurso ≤ `rol_sensibilidad_max` del mejor rol aplicable del actor. Excepciones estructurales: notas profesionales (solo autor y nivel 4), resultados anónimos (nadie los liga a persona), asignaciones discretas (solo autor y nivel ≥ del instrumento).
4. **Consentimiento:** existe consentimiento `vigente` de la persona (o su tutor) que ampare el tipo de tratamiento y, cuando aplica, el propósito y el tenant. Para acceso cross-tenant, además una `comparticion_expediente` vigente para ese dominio y alcance.

Toda decisión —incluidas las denegadas— se escribe en `bitacora` en la misma transacción lógica.

### Reglas transversales
- **Vistas por audiencia:** el mismo resultado tiene representación profesional (puntajes/percentiles/técnica) y representación evaluado/tutor (lenguaje cuidado, fortalezas, recomendaciones). La audiencia se deriva del rol del actor, jamás se elige por parámetro del cliente.
- **Resultados clínicos hacia el evaluado:** los instrumentos de sensibilidad 3–4 muestran al evaluado mensajes configurados de cierre y recursos de apoyo; el detalle clínico solo lo comunica el profesional.
- **Caducidad:** alcances y consentimientos con vigencia; jobs nocturnos marcan vencidos y disparan renovaciones.

## 2. Roles plantilla (seed por tipo de tenant)

| Rol plantilla | Sens. máx | Permisos clave | Alcance típico |
|---|---|---|---|
| Superadmin tenant | 2 | configuración, roles, habilitar instrumentos | organización |
| Coordinador/Orientador/Reclutador | 2 | asignar, baterías, avance, resultados resumen/detalle 1–2, reportes grupales | unidad/agrupación |
| Psicólogo | 4 | + asignación discreta, capturar protocolos, notas, alertas, validar IA, detalle 3–4 | unidad u organización |
| Docente/Supervisor | 1–2 | resultados resumen de su agrupación | agrupación |
| Capturista de expediente | 1 | expediente.editar/validar | unidad |
| Titular | — | su expediente, sus resultados (vista evaluado), consentimientos, comparticiones | persona (self) |
| Tutor | — | igual que titular sobre el menor + responder como informante | persona (tutelado) |
| Examinador | 3–4 | protocolos.capturar | según designación |
| Auditor | lectura | bitacora.consultar | organización |

## 3. Marco legal mexicano

### LFPDPPP — datos personales sensibles
Los datos de salud mental, resultados psicométricos y clínicos son **datos sensibles**: exigen consentimiento **expreso y por escrito** (firma autógrafa, firma electrónica o cualquier mecanismo de autenticación equivalente — el consentimiento con clic autenticado + evidencia se documenta como tal).
Implementación:
- **Aviso de privacidad** integral y simplificado por tenant, versionado (`textos_consentimiento`), con registro de qué versión exacta aceptó cada persona (hash del documento).
- **Consentimiento por finalidad:** tratamiento general ≠ aplicación laboral ≠ compartición entre tenants; cada uno es un consentimiento distinto y revocable por separado.
- **Derechos ARCO:** módulo de solicitudes (acceso, rectificación, cancelación, oposición) con plazos, respuesta documentada y efectos técnicos definidos (exportación del expediente; rectificación vía versionado de valores; cancelación = supresión/bloqueo según obligaciones de conservación, documentando excepciones).
- **Menores:** consentimiento del tutor acreditado (`tutorias` validadas); **re-consentimiento al llegar a mayoría de edad** (proceso automático de transición) — sin re-consentimiento, los datos quedan en bloqueo de acceso para terceros hasta que el titular decida.
- **Principio de minimización:** cada rol ve lo mínimo necesario (la matriz sensibilidad × alcance lo materializa); la IA recibe datos pseudonimizados.
- **Encargados y transferencias:** contratos de encargo con el tenant (el tenant es responsable; la plataforma, encargado), y cláusula específica para el procesamiento de IA (proveedor como sub-encargado, sin uso de datos para entrenamiento).

### NOM-035-STPS-2018
- Las Guías de Referencia son de aplicación obligatoria según tamaño de centro de trabajo; el sistema implementa sus reactivos, cortes y semáforos oficiales, reportes por centro de trabajo y resguardo de evidencias por el tiempo que exige la norma.
- Modo anónimo/nominal configurable conforme a la política del patrón, con anonimato irreversible por diseño cuando se elige.

### Selección de personal y no discriminación
- Los resultados se limitan al propósito del proceso (el reclutador ve su proceso, no el historial).
- El sistema no expone diagnósticos ni datos clínicos en contextos laborales; los comparadores operan sobre escalas laborales (sensibilidad ≤ 2).
- Trazabilidad completa de calificación como defensa ante impugnaciones (LFT/CONAPRED).

### Propiedad intelectual de instrumentos
- Estatus de licencia estructural en el catálogo (Doc 04).
- Declaración de licencia firmada por el tenant para instrumentos LIC + evidencia opcional; cadena de responsabilidad registrada (quién cargó qué contenido y cuándo).
- Protección de contenido: entrega parcelada de reactivos, sin endpoints de listado completo, deshabilitación de copiado/impresión en cliente, marca de agua de sesión en modos sensibles, y créditos por aplicación cuando exista convenio editorial (V4).

## 4. Seguridad técnica

| Control | Implementación |
|---|---|
| Cifrado en tránsito | TLS 1.2+ obligatorio, HSTS |
| Cifrado en reposo (aplicación) | Encrypted casts en `respuestas.valor_texto`, `notas_profesionales.contenido`, resultados sensibilidad 3–4 y campos sensibles de expediente; llaves gestionadas fuera del repo, rotación planificada |
| Autenticación | Contraseñas robustas + 2FA opcional (obligatoria para roles nivel 3–4); Sanctum para API; tokens anónimos de un solo uso con expiración para ligas de aplicación |
| Sesiones de aplicación | Token ligado a asignación-destinatario, invalidado al completar; cronómetros server-side |
| Aislamiento de tenant | Global scopes + middleware + **pruebas automatizadas de aislamiento** (suite que intenta fugas cross-tenant en cada endpoint) |
| Bitácora inmutable | Append-only; usuario MySQL de la app sin UPDATE/DELETE sobre `bitacora` |
| Backups | Diarios cifrados, retención escalonada, pruebas de restauración periódicas |
| Rate limiting | Por token/IP en API; umbral estricto en endpoints de respuestas |
| Gestión de incidentes | Procedimiento documentado de notificación de vulneraciones de seguridad de datos (obligación LFPDPPP ante afectación significativa) |
| Registro de accesos a sensibles | Ya cubierto por bitácora: quién vio qué resultado, cuándo, con qué propósito |

## 5. Alertas de riesgo (obligación ética operativa)

- Los tenants que habiliten instrumentos con reactivos centinela deben designar responsable(s) de atención y registrar su protocolo (tiempos, escalamiento, recursos de canalización) antes de poder asignarlos.
- La alerta crítica notifica en tiempo real por múltiples canales y exige cierre documentado (`alertas.resolucion`).
- El sistema muestra al evaluado recursos de apoyo configurados; no realiza intervención clínica automatizada.
