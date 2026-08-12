# MENTIA — Documento 05: Motor de Calificación e Interpretación

Versión 1.0 — Agosto 2026

---

## 1. Principios

1. **Determinismo:** la calificación es 100% algorítmica y reproducible desde configuración (claves, fórmulas, baremos, reglas). La IA jamás califica ni interpreta desde puntajes crudos.
2. **Trazabilidad:** cada etapa persiste su salida; ante impugnación se reconstruye el camino bruto → normalizado → interpretación.
3. **Pipeline configurable:** cada versión de instrumento declara qué etapas y estrategias usa (`instrumento_pipeline (version_instrumento_id, etapa, estrategia_clave, orden, parametros vía tabla hija)`).
4. **Ejecución en cola** (`calificacion`), salvo centinelas (síncronos por lote de respuestas).

## 2. Las seis etapas

### Etapa 1 — Validez previa
Estrategias disponibles (catálogo):
- `omisiones_max`: % de reactivos sin responder > umbral → dudosa/inválida.
- `patron_repetido`: N respuestas idénticas consecutivas (straight-lining) en likert.
- `tiempo_atipico`: tiempo total o mediana por reactivo fuera de rango configurado (respuesta al azar o asistida).
- `escala_validez`: escalas del propio instrumento (deseabilidad social, infrecuencia, inconsistencia por pares espejo).
- `cronologia_offline`: coherencia de sellos de tiempo locales al sincronizar.
Salida → `aplicaciones.validez` + detalle en `validez_detalle`. `invalida` detiene el pipeline (configurable); `dudosa` continúa con advertencia en reportes.

### Etapa 2 — Puntajes brutos
Estrategias:
- `suma_simple` / `suma_ponderada` (peso por clave), con `es_inverso` aplicando reflexión (max+min−valor).
- `conteo_correctas` (cognitivas), con soporte de `correccion_adivinanza` opcional (aciertos − errores/(k−1)).
- `conteo_ipsativo`: por cada cuadro de elección forzada, la opción marcada "más" suma a su escala rol=mas, la "menos" resta o suma a la escala rol=menos según configuración (cubre Cleaver M/L y T=M−L como fórmula derivada).
- `ranking_ponderado`: posición → puntos (Zavic, Allport).
- `conteo_criterio`: reactivos que cumplen condición (dicotómicos de riesgo: M-CHAT, GR-I).
Luego se evalúan `formulas_derivadas` en su `orden_evaluacion` (índices compuestos, perfiles T de Cleaver, totales por dominio NOM-035).

### Etapa 3 — Algoritmos especiales
Estrategias nombradas para lógicas no lineales:
- `mchat_dos_etapas`: puntaje 0–2 bajo / 3–7 medio → dispara entrevista de seguimiento (reactivos condicionales) → recalifica / ≥8 alto directo.
- `nom035_cortes`: calificación por dominio y categoría con los cortes oficiales y semáforo por nivel (nulo/bajo/medio/alto/muy alto), tanto individual como agregado por centro de trabajo.
- `audit_zonas`, `cssrs_ruta`, `phq_gravedad`, etc.
Cada estrategia es una clase PHP registrada en un `ScoringStrategyRegistry`; agregar una nueva no toca el pipeline.

### Etapa 4 — Normalización (baremos)
Resolución del baremo aplicable por prioridad: **baremo de agrupación del tenant → baremo del tenant → baremo nacional → baremo global publicado**, filtrando filas por `edad_meses_al_aplicar`, `sexo_registral` y escolaridad cuando el baremo lo segmenta. Conversión bruto → percentil / T (media 50, DE 10) / estanina / decatipo / CI de desviación (media 100, DE 15) / etiqueta de semáforo.
Si no existe baremo aplicable: el resultado queda solo en bruto con marca `sin_norma` (visible al profesional, oculto en vistas de evaluado).

### Etapa 5 — Interpretación
Se resuelven en orden de `prioridad`:
1. `rango_escala`: condición sobre una escala (en el tipo de puntaje declarado).
2. `combinacion`: AND/OR de condiciones multi-escala.
3. `perfil_tipo`: pertenencia a tipologías (perfiles Cleaver, código RIASEC de 3 letras por las 3 escalas más altas con manejo de empates, tipos DISC).
Cada regla emite su texto por **audiencia** (profesional / evaluado_adulto / tutor / infantil) → `resultados_interpretacion`. Los textos admiten variables (`{nombre}`, `{percentil}`) resueltas al generar.

### Etapa 6 — Banderas y protocolos
- Banderas de reglas (verde/amarillo/rojo) se copian a `resultados_normalizados`.
- `protocolo_reglas` evalúa condiciones → acciones automáticas: asignar instrumento/batería de 2a etapa, notificar rol, marcar seguimiento. Todo protocolo automático deja rastro en bitácora y notifica al profesional responsable (nunca actúa "en silencio").

## 3. Reactivos centinela (fuera del pipeline: tiempo real)

- `reactivos.es_centinela = 1` + tabla `centinela_condiciones (reactivo_id, opcion_id/valor, severidad)`.
- Al recibir **cada lote** de respuestas (`POST /respuestas`), el servidor evalúa centinelas del lote de forma síncrona. Positivo → `alertas` severidad crítica + notificación inmediata por los canales configurados al rol responsable (psicólogo de guardia del tenant), aunque la aplicación siga en curso.
- Casos seed: PHQ-9 reactivo 9 (ideación) con cualquier valor > 0; C-SSRS screener rutas de riesgo; GR-I sección de exposición con afectación.
- Al evaluado se le muestra, al finalizar instrumentos de sensibilidad 3–4, un mensaje cuidado con recursos de apoyo (configurable por tenant), nunca "diste positivo a X".
- Cada tenant debe documentar su **protocolo de actuación** (responsable, tiempos de respuesta, escalamiento) como requisito de habilitación de instrumentos con centinelas.

## 4. Comparadores

- **Persona vs. puesto:** `perfiles_puesto` con rangos esperados por escala y ponderación → % de ajuste + detalle por criterio (dentro/fuera de rango).
- **Persona vs. sí misma:** series de `resultados_normalizados` por constructo; el sistema marca **cambios significativos** (Δ ≥ umbral configurable por constructo, p.ej. ±1 DE) entre mediciones.
- **Persona vs. grupo:** posición relativa dentro de su agrupación (para reportes grupales y distribuciones).

## 5. Motor de reportes

| Tipo | Contenido | Audiencias |
|---|---|---|
| Individual por instrumento | Puntajes, gráfica de perfil, interpretaciones, validez | profesional / evaluado / tutor |
| Integrador de batería | Síntesis multi-instrumento; borrador redactado por IA sobre interpretaciones resueltas; requiere validación y firma | profesional; versión evaluado tras validación |
| Grupal | Distribuciones, semáforos, listas de bandera (solo roles autorizados) | coordinador/profesional |
| NOM-035 | Formatos por centro de trabajo con cortes oficiales | empresa/STPS |
| Longitudinal ("ficha hospital") | Dominios, series temporales normalizadas, eventos, banderas históricas | profesional; versión resumida titular |

Los PDF se generan server-side desde `plantillas_reporte` (HTML → PDF), se almacenan en `reportes_generados` y su descarga pasa por AccesoService + bitácora.

## 6. Capa de IA (contrato estricto)

**Entrada:** JSON estructurado de resultados ya calificados e interpretados (escalas, normas, textos de interpretación resueltos, banderas, validez), pseudonimizado (sin CURP ni datos de contacto; nombre solo si el reporte final lo requiere y el consentimiento lo ampara).
**Tareas permitidas:** redactar reporte integrador; señalar inconsistencias entre instrumentos; resumir evolución longitudinal; sugerir batería de profundización según protocolos configurados.
**Prohibido:** calificar, diagnosticar, inferir condiciones no evaluadas, contradecir las interpretaciones configuradas.
**Salida:** siempre `reportes_ia.estado = borrador`; solo un rol con `ia.validar_reportes` lo convierte en reporte liberado, con firma y registro. El prompt de sistema de esta integración se versiona en el repositorio como configuración.
