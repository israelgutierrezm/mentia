# MENTIA — Documento 03: Modelo de Datos (DER y Diccionario)

Versión 1.0 — Agosto 2026
Convenciones: MySQL 8, InnoDB, utf8mb4. PK `id BIGINT UNSIGNED AUTO_INCREMENT` salvo indicación. Todas las tablas llevan `creado_en`, `actualizado_en`; las de tenant llevan `organizacion_id` con índice. Soft deletes solo donde se indica. **Cero columnas JSON en datos de dominio.**

---

## Vista general de módulos y dependencias

```
[M2 Personas]──────────────┐
     │                     │
[M1 Organizaciones]   [M4 Expediente/Consentimientos]
     │                     │
[M3 Accesos (Spatie+alcances)]
     │
[M5 Catálogo global]───[M6 Asignaciones/Baterías]───[M7 Aplicaciones]
                                                        │
                                     [M8 Resultados]────┤
                                     [M9 Alertas]───────┤
                                     [M10 Reportes]─────┘
```

---

## M1 — Organizaciones

### organizaciones
| Columna | Tipo | Notas |
|---|---|---|
| id | PK | |
| nombre | varchar(160) | |
| tipo_organizacion_id | FK → tipos_organizacion | escuela, empresa, consultorio, dependencia |
| rfc | varchar(13) nullable | |
| estado | enum('activa','suspendida') | |
| configuraciones vía tabla `organizacion_configuraciones (clave, valor)` | | una fila por parámetro |

### tipos_organizacion
`id, clave, nombre, vocabulario_persona ('alumno'/'colaborador'/'paciente'), vocabulario_agrupacion`

### unidades
`id, organizacion_id, unidad_padre_id (FK self, nullable), nombre, tipo (plantel, sede, departamento, área), estado`
Índice: `(organizacion_id, unidad_padre_id)`.

### tipos_agrupacion
`id, organizacion_id nullable (NULL = plantilla del sistema), clave, nombre` — grupo_escolar, vacante, generación, taller, cohorte, centro_trabajo (NOM-035).

### agrupaciones
`id, organizacion_id, unidad_id nullable, tipo_agrupacion_id, nombre, periodo_inicio date null, periodo_fin date null, estado`

### agrupacion_miembros
`id, agrupacion_id, persona_id, rol_en_agrupacion enum('evaluado','titular_responsable'), fecha_alta, fecha_baja nullable`
Índices: `(agrupacion_id, persona_id, fecha_baja)`, `(persona_id)`. La vigencia temporal de membresías dibuja la línea de vida institucional de la persona.

---

## M2 — Personas e identidad

### personas  *(GLOBAL, sin organizacion_id)*
| Columna | Tipo | Notas |
|---|---|---|
| id | PK | |
| uuid | char(36) único | identificador público |
| curp | char(18) único nullable | ancla de identidad MX; nullable para extranjeros/casos sin CURP |
| nombres, primer_apellido, segundo_apellido | varchar | |
| fecha_nacimiento | date | insumo de baremos por edad |
| sexo_registral | enum('M','F','X') | insumo de baremos |
| verificacion_identidad | enum('no_verificada','documental','presencial') | |
| usuario_id | FK → users nullable | la persona puede existir sin cuenta (menores, ligas anónimas) |

### users
Estándar Laravel + `persona_id` FK. Autenticación separada de identidad.

### organizacion_personas  *(vinculación persona↔tenant)*
`id, organizacion_id, persona_id, matricula_o_num_empleado varchar nullable, estado enum('activa','baja'), origen_alta enum('creada','vinculada','reclamada'), fecha_alta, fecha_baja`
Único: `(organizacion_id, persona_id)`.

### tutorias
`id, tutor_persona_id, menor_persona_id, parentesco enum('madre','padre','tutor_legal','otro'), documento_media_id nullable, estado enum('pendiente_validacion','vigente','revocada','extinta_mayoria_edad'), vigencia_inicio, vigencia_fin nullable, validada_por nullable`
Job programado: al cumplir el menor 18 años → estado `extinta_mayoria_edad` + apertura de periodo de re-consentimiento del titular (ver M4).

---

## M3 — Accesos

### Tablas Spatie (modo teams)
`roles (con organizacion_id como team), permissions (globales), model_has_roles (+organizacion_id), model_has_permissions, role_has_permissions`.
Los **permisos son catálogo fijo del sistema** (seed): `personas.crear`, `expediente.ver`, `expediente.editar`, `expediente.validar`, `evaluaciones.asignar`, `evaluaciones.asignar_individual_discreta`, `baterias.armar`, `resultados.ver_resumen`, `resultados.ver_detalle`, `resultados.exportar`, `protocolos.capturar`, `alertas.atender`, `interpretaciones.editar`, `instrumentos.habilitar`, `instrumentos.capturar_contenido`, `reportes.grupales`, `ia.validar_reportes`, `bitacora.consultar`, etc.

### plantillas_rol  *(global)*
`id, tipo_organizacion_id nullable, clave, nombre` + `plantilla_rol_permisos`. Al crear tenant se clonan a roles Spatie del tenant.

### persona_rol_alcances  *(la dimensión que Spatie no cubre)*
| Columna | Tipo | Notas |
|---|---|---|
| id | PK | |
| organizacion_id | FK | |
| persona_id | FK | |
| rol_id | FK → roles (Spatie) | |
| alcance_tipo | enum('organizacion','unidad','agrupacion','persona') | |
| alcance_id | BIGINT | FK lógica según alcance_tipo |
| vigencia_inicio, vigencia_fin | date, date null | caduca acceso al fin de ciclo |
| otorgado_por | FK personas | |
Índices: `(persona_id, organizacion_id, vigencia_fin)`, `(alcance_tipo, alcance_id)`.

### niveles_sensibilidad  *(global, seed)*
`id, nivel tinyint (1..4), clave ('general','laboral','psicologico','clinico'), nombre, descripcion`

### rol_sensibilidad_max
`rol_id, nivel_sensibilidad_max tinyint` — tope de sensibilidad visible por rol.

### bitacora  *(append-only)*
`id, organizacion_id nullable, actor_persona_id nullable, accion varchar(80), recurso_tipo varchar(80), recurso_id, persona_afectada_id nullable, proposito_id nullable, resultado enum('permitido','denegado'), ip varchar(45), user_agent varchar(255), registrado_en datetime(3)`
Sin UPDATE/DELETE a nivel aplicación y con usuario MySQL de app sin esos privilegios sobre la tabla. Índices: `(persona_afectada_id, registrado_en)`, `(actor_persona_id, registrado_en)`.

---

## M4 — Expediente y consentimientos

### expedientes
`id, persona_id único, estado` — 1:1 con persona (global; los valores capturados guardan el tenant de contexto).

### secciones_expediente *(catálogo global)*
`id, clave ('datos_generales','medico_relevante','legal','documentos','notas_profesionales'...), nombre, orden, nivel_sensibilidad_id`

### expediente_campos *(config-driven, patrón formularios Acadion)*
`id, seccion_id, clave, etiqueta, tipo_dato enum('texto','numero','fecha','catalogo','booleano','archivo'), catalogo_opciones_id nullable, obligatorio bool, quien_puede_llenar enum('titular','tutor','profesional','admin'), nivel_sensibilidad_id, orden, activo`

### expediente_valores *(una fila por respuesta de campo)*
`id, expediente_id, campo_id, organizacion_id_contexto nullable, valor_texto text null, valor_numero decimal null, valor_fecha date null, valor_opcion_id null, media_id null, capturado_por persona_id, estado enum('pendiente_validacion','validado','rechazado'), validado_por nullable, version int`
Histórico por `version`; el valor vigente es la mayor versión validada.

### expediente_documentos
Vía medialibrary + `tipos_documento (id, clave, nombre, requiere_validacion)`; fila propia: `id, expediente_id, tipo_documento_id, media_id, cargado_por, estado, validado_por, vigencia_fin nullable`.

### notas_profesionales
`id, expediente_id, organizacion_id, autor_persona_id, contenido text cifrado, nivel_sensibilidad_id (=4), visible_para enum('autor','nivel_4')` — nunca visibles para el titular directamente.

### textos_consentimiento *(versionados, global o por tenant)*
`id, organizacion_id nullable, tipo_consentimiento_id, version, titulo, cuerpo mediumtext, hash_sha256 char(64), vigente_desde`

### tipos_consentimiento
`id, clave ('tratamiento_datos_sensibles','aplicacion_educativa','aplicacion_laboral','aplicacion_clinica','comparticion_entre_tenants','contacto')`

### consentimientos
| Columna | Notas |
|---|---|
| id, persona_id (titular de los datos) | |
| texto_consentimiento_id | versión exacta firmada |
| otorgado_por_persona_id | titular o tutor |
| relacion enum('titular','tutor') | |
| organizacion_id nullable | tenant al que ampara; NULL = plataforma |
| proposito_id nullable | ligado a plantilla de propósito |
| evidencia enum('clic_firmado','firma_digital','documento') + media_id nullable | |
| vigencia_inicio, vigencia_fin nullable, revocado_en nullable, motivo_revocacion | |
| estado enum('vigente','vencido','revocado','pendiente_reconsentimiento') | |
La transición de mayoría de edad marca `pendiente_reconsentimiento` en los otorgados por tutor.

### comparticiones_expediente *(la persona controla su historial cross-tenant)*
`id, persona_id, organizacion_destino_id, dominio_id nullable (NULL = según detalle), consentimiento_id, alcance enum('resumen','detalle'), vigencia_fin nullable, revocado_en nullable`

---

## M5 — Catálogo de instrumentos *(GLOBAL)*

### categorias_instrumento / subcategorias_instrumento
Jerarquía de la taxonomía (personalidad→laboral, cognitivo→factor_g, etc.): `id, padre_id nullable, clave, nombre, orden`.

### dominios *(los "órganos" del expediente)*
`id, clave ('desarrollo_temprano','cognitivo','personalidad','emocional','vocacional','valores','competencias','adaptativo','organizacional'), nombre, orden`

### instrumentos
| Columna | Notas |
|---|---|
| id, clave única, nombre, nombre_corto | |
| subcategoria_id, dominio_id | |
| estatus_licencia | enum('dominio_publico','requiere_licencia_tenant','solo_captura') |
| contenido_incluido | enum('completo','esqueleto','ninguno') |
| nivel_sensibilidad_id | |
| modo_calificacion | enum('algoritmica','captura_protocolo','interpretacion_experta') |
| quien_responde | enum('autoaplicada','informante','examinador','mixta') |
| edad_min_meses, edad_max_meses | población objetivo |
| duracion_estimada_min | |
| requiere_supervision bool | gancho Proctorion |
| ficha: autor, anio, poblacion_norma, referencia_bibliografica | |

### versiones_instrumento
`id, instrumento_id, version varchar(20), idioma char(5) default 'es-MX', estado enum('borrador','publicada','retirada'), publicada_en, notas_version`
**Inmutable tras publicación**; correcciones = nueva versión.

### escalas
`id, version_instrumento_id, clave (D,I,S,C / serie_I...), nombre, escala_padre_id nullable (factores de 2o orden), es_validez bool, orden`

### bloques
`id, version_instrumento_id, clave, titulo, instrucciones mediumtext, orden, tiempo_limite_seg nullable, orden_reactivos enum('fijo','aleatorio'), es_practica bool`
Bloques de práctica con validación de comprensión.

### tipos_reactivo *(catálogo extensible; cada clave mapea a un componente de render)*
`id, clave, nombre, requiere_opciones bool, admite_multimedia bool`
Seed: likert_3/4/5/7, opcion_multiple_correcta, dicotomico, eleccion_forzada_par, eleccion_forzada_cuadro, ranking, diferencial_semantico, matriz_visual, texto_abierto, captura_dibujo, audio_respuesta, observacional_examinador, entrevista_estructurada.

### reactivos *(una fila por reactivo)*
`id, version_instrumento_id, bloque_id, tipo_reactivo_id, codigo varchar(20), enunciado text, media_id nullable, es_inverso bool, es_centinela bool, obligatorio bool, orden, tiempo_limite_seg nullable`

### opciones_reactivo *(una fila por opción)*
`id, reactivo_id, codigo, texto, media_id nullable, es_correcta bool nullable, orden`

### claves_calificacion *(una fila por regla opción→escala)*
`id, version_instrumento_id, reactivo_id, opcion_id nullable, escala_id, peso decimal(6,3), rol enum('normal','mas','menos')`
`rol` cubre ipsativos (Cleaver: la misma opción puntúa a una escala como "más" y a otra como "menos").

### reglas_salto
`id, version_instrumento_id, reactivo_origen_id, opcion_id nullable, condicion enum('respondida','igual','mayor','menor'), valor nullable, destino_tipo enum('reactivo','bloque','fin'), destino_id`

### formulas_derivadas
`id, version_instrumento_id, escala_destino_id, expresion varchar(255) (notación validada sobre claves de escala), orden_evaluacion` — índices compuestos entre escalas.

### poblaciones_norma
`id, clave, nombre, pais, descripcion, fuente`

### baremos *(cabecera)*
`id, version_instrumento_id, escala_id, poblacion_norma_id, organizacion_id nullable (NULL=global; valor=baremo propio del tenant), tipo_norma enum('percentil','T','estanina','decatipo','ci_desviacion','semaforo'), vigente bool, fuente`

### baremo_filas *(una fila por rango de conversión)*
`id, baremo_id, bruto_min decimal, bruto_max decimal, edad_min_meses null, edad_max_meses null, sexo enum('M','F','X') null, escolaridad_id null, valor_normalizado decimal, etiqueta varchar(40) null (p.ej. 'riesgo alto')`
Índice: `(baremo_id, bruto_min, edad_min_meses)`.

### reglas_interpretacion
`id, version_instrumento_id, escala_id nullable, tipo_regla enum('rango_escala','combinacion','perfil_tipo'), tipo_puntaje enum('bruto','percentil','T','decatipo','ci','semaforo'), operador, valor_min, valor_max, audiencia enum('profesional','evaluado_adulto','tutor','infantil'), texto_interpretacion mediumtext, recomendaciones mediumtext null, bandera enum('verde','amarillo','rojo') null, prioridad int, vigente bool`

### reglas_interpretacion_condiciones *(para combinaciones: una fila por condición)*
`id, regla_id, escala_id, tipo_puntaje, operador, valor_min, valor_max, conector enum('AND','OR')`

### perfiles_tipo
`id, version_instrumento_id, codigo (p.ej. 'D_alto_C_alto', 'RIA'), nombre, descripcion_profesional, descripcion_evaluado, fortalezas, areas_desarrollo, orden` + `perfil_tipo_condiciones` (mismo esquema que condiciones) + `perfil_tipo_ocupaciones (perfil_id, ocupacion_id)` con catálogo `ocupaciones` (crosswalk O*NET precargable).

### tenant_instrumentos *(habilitación por tenant)*
`id, organizacion_id, version_instrumento_id, estado enum('disponible','habilitado','pendiente_contenido','bloqueado'), origen_contenido enum('global','capturado_por_tenant'), declaracion_licencia_texto mediumtext null, declaracion_firmada_por null, evidencia_media_id null, habilitado_en`
Los reactivos capturados por el tenant para instrumentos-esqueleto se guardan en las mismas tablas de contenido con `organizacion_id_contenido` nullable en `reactivos`/`opciones_reactivo` (NULL = contenido global; valor = contenido privado del tenant, jamás visible a otros).

### instrumentos_propios
Mismo esquema de catálogo con `organizacion_id` en `instrumentos` (nullable: NULL = global del sistema; valor = instrumento creado por el tenant).

---

## M6 — Asignaciones y baterías

### baterias
`id, organizacion_id nullable (NULL = plantilla del sistema), clave, nombre, descripcion, orden_instrumentos enum('fijo','libre'), permite_pausas bool, tiempo_total_min nullable, estado`

### bateria_instrumentos
`id, bateria_id, version_instrumento_id, orden, obligatorio bool`

### propositos *(plantillas de asignación)*
`id, organizacion_id nullable, clave, nombre ('Selección mando medio','Tamizaje anual 3°','NOM-035 anual','Seguimiento','Canalización'), bateria_id nullable, version_instrumento_id nullable, tipo_consentimiento_id, vigencia_dias_default, modo_presentacion_default, genera_reporte_integrador bool`

### asignaciones
| Columna | Notas |
|---|---|
| id, folio único, organizacion_id | |
| version_instrumento_id nullable / bateria_id nullable | exactamente uno (CHECK) |
| proposito_id | |
| origen_tipo enum('individual','agrupacion','campania') | |
| agrupacion_id nullable | |
| incluir_nuevos_miembros bool | agrupación dinámica vs snapshot |
| asignado_por persona_id | |
| es_discreta bool | asignación individual visible solo a autorizados |
| es_anonima bool | NOM-035/clima: sin vínculo persona-respuesta (ver nota) |
| ventana_inicio, ventana_fin datetime | |
| intentos_permitidos tinyint default 1 | |
| modo_presentacion | |
| requiere_consentimiento bool + tipo_consentimiento_id | |
| estado enum('borrador','activa','cerrada','cancelada') | |

**Nota anonimato:** cuando `es_anonima=1`, `asignacion_destinatarios` controla quién puede responder (token) pero la `aplicacion` resultante guarda `persona_id NULL` + atributos demográficos mínimos autorizados (centro de trabajo). Irreversible por diseño.

### asignacion_destinatarios
`id, asignacion_id, persona_id, quien_responde_persona_id nullable (informante), estado enum('pendiente','consentimiento_pendiente','notificada','en_curso','completada','expirada','exenta'), token char(64) único nullable, token_expira_en, motivo_exencion null`
Índices: `(asignacion_id, estado)`, `(persona_id)`, `(token)`.

### protocolo_reglas *(escalonamiento automático, V2)*
`id, organizacion_id nullable, si_version_instrumento_id, condicion_escala_id, tipo_puntaje, operador, valor, entonces_accion enum('asignar_instrumento','asignar_bateria','notificar_rol','marcar_seguimiento'), entonces_ref_id, notificar_rol_id nullable, activo`

---

## M7 — Aplicaciones y respuestas

### aplicaciones *(una instancia de respuesta de UN instrumento por UNA persona)*
| Columna | Notas |
|---|---|
| id, uuid, organizacion_id | |
| asignacion_destinatario_id | |
| version_instrumento_id | redundante intencional para consultas |
| persona_id nullable | NULL si anónima |
| quien_respondio_persona_id nullable | informante/examinador |
| modalidad enum('en_linea','presencial_kiosco','offline_sync','captura_protocolo','captura_fisica') | |
| modo_presentacion | |
| estado enum('iniciada','en_pausa','completada','expirada','anulada') | |
| iniciada_en, finalizada_en, tiempo_efectivo_seg | |
| dispositivo, ip | |
| edad_meses_al_aplicar smallint | congelada para baremos |
| validez enum('pendiente','valida','dudosa','invalida') + motivo_invalidez | |
| numero_intento tinyint | |

### aplicacion_bloques *(estado por bloque, cronómetro server-side)*
`id, aplicacion_id, bloque_id, iniciado_en, finalizado_en, tiempo_consumido_seg, estado`

### respuestas *(tabla crítica; una fila por reactivo respondido)*
| Columna | Notas |
|---|---|
| id BIGINT, aplicacion_id, reactivo_id | |
| opcion_id nullable | selección simple |
| valor_numerico decimal null | likert/diferencial |
| valor_texto text null | abiertas |
| media_id null | dibujo/audio |
| rol_ipsativo enum('mas','menos') null | Cleaver |
| posicion_ranking tinyint null | |
| uuid_cliente char(36) único | idempotencia app/offline |
| tiempo_respuesta_ms int | oro conductual |
| respondida_en datetime(3), origen enum('online','offline') | |
Índices: `(aplicacion_id, reactivo_id)` único, `(reactivo_id)`. Particionado lógico/archivado por año previsto.

### respuestas_ranking / detalle adicional
Para ranking multi-opción: una fila por opción con `posicion_ranking` (mismo esquema de respuestas, N filas por reactivo con único ajustado `(aplicacion_id, reactivo_id, opcion_id)`).

### capturas_protocolo *(instrumentos solo-captura)*
`id, aplicacion_id, escala_id, puntaje_bruto decimal, puntaje_escalar decimal null, observaciones text, capturado_por persona_id` — el examinador registra puntajes de WISC/ADOS/etc.; el pipeline entra desde la etapa de normalización.

---

## M8 — Resultados

### resultados_escala *(salida del pipeline por escala)*
`id, aplicacion_id, escala_id, puntaje_bruto decimal, baremo_id nullable, valor_normalizado decimal null, tipo_norma, etiqueta_norma varchar(40) null, calculado_en`
Único `(aplicacion_id, escala_id)`.

### resultados_interpretacion
`id, aplicacion_id, regla_interpretacion_id nullable, perfil_tipo_id nullable, audiencia, texto_resuelto mediumtext, bandera, orden`

### resultados_normalizados *(la tabla del expediente longitudinal)*
`id, persona_id, dominio_id, constructo varchar(60) ('ansiedad_rasgo','razonamiento','D'...), version_instrumento_id, aplicacion_id, organizacion_id_contexto, fecha date, tipo_norma, valor decimal, bandera null`
Índice: `(persona_id, dominio_id, constructo, fecha)` — alimenta todas las gráficas evolutivas sin joins pesados.

### validez_detalle
`id, aplicacion_id, verificacion enum('omisiones','patron_repetido','tiempo_atipico','escala_validez','cronologia_offline'), resultado enum('paso','advertencia','fallo'), detalle varchar(255)`

---

## M9 — Alertas

### alertas
`id, organizacion_id, persona_id nullable, aplicacion_id nullable, tipo enum('centinela','bandera_resultado','protocolo','validez'), severidad enum('critica','alta','media'), reactivo_id nullable, mensaje, estado enum('nueva','notificada','atendida','cerrada'), atendida_por nullable, atendida_en, resolucion text null, creada_en datetime(3)`
Las críticas (centinela) se generan **síncronamente** al recibir el lote de respuestas y notifican al rol responsable configurado en `alerta_destinatarios (organizacion_id, tipo, severidad, rol_id, canal enum('app','correo','sms'))`. Protocolo de actuación documentado por tenant en `organizacion_configuraciones`.

---

## M10 — Reportes e IA

### plantillas_reporte
`id, organizacion_id nullable, tipo enum('individual','integrador','grupal','longitudinal','nom035'), audiencia, version_instrumento_id nullable, bateria_id nullable, estructura_html mediumtext, vigente`

### reportes_generados
`id, organizacion_id, tipo, persona_id nullable, asignacion_id nullable, aplicacion_id nullable, plantilla_id, media_id (PDF), generado_por, generado_en, firmado_por nullable, firmado_en`

### reportes_ia
`id, reporte_generado_id, modelo varchar(60), insumo_hash char(64), borrador mediumtext, estado enum('borrador','validado','rechazado'), validado_por nullable, observaciones_validacion`
La IA recibe **resultados ya calificados e interpretados** (nunca respuestas crudas ni datos identificables innecesarios) y su salida siempre requiere validación profesional antes de liberarse.

### perfiles_puesto *(comparador de selección)*
`id, organizacion_id, nombre, descripcion` + `perfil_puesto_criterios (perfil_puesto_id, escala_id, tipo_puntaje, valor_min, valor_max, ponderacion)` → % de ajuste candidato-puesto.

---

## Diagrama entidad-relación resumido (núcleo)

```
personas 1─N organizacion_personas N─1 organizaciones
personas 1─1 expedientes 1─N expediente_valores
personas 1─N consentimientos N─1 textos_consentimiento
personas 1─N tutorias (menor) / (tutor)
organizaciones 1─N unidades 1─N agrupaciones 1─N agrupacion_miembros N─1 personas
roles(Spatie) 1─N persona_rol_alcances N─1 personas
instrumentos 1─N versiones_instrumento 1─N {escalas, bloques, reactivos}
reactivos 1─N opciones_reactivo 1─N claves_calificacion N─1 escalas
versiones_instrumento 1─N baremos 1─N baremo_filas
versiones_instrumento 1─N reglas_interpretacion / perfiles_tipo
organizaciones 1─N tenant_instrumentos N─1 versiones_instrumento
baterias 1─N bateria_instrumentos N─1 versiones_instrumento
asignaciones 1─N asignacion_destinatarios 1─1..N aplicaciones
aplicaciones 1─N respuestas N─1 reactivos
aplicaciones 1─N resultados_escala N─1 escalas
aplicaciones 1─N resultados_interpretacion
personas 1─N resultados_normalizados N─1 dominios
aplicaciones 1─N alertas
```

## Decisiones de integridad destacadas

1. **CHECK** en `asignaciones`: exactamente uno de `version_instrumento_id`/`bateria_id`.
2. **FK RESTRICT** generalizado; nada se borra si tiene aplicaciones (se retira/desactiva).
3. `edad_meses_al_aplicar` se congela en la aplicación: los baremos se resuelven contra la edad al momento, no la actual.
4. `respuestas.uuid_cliente` único global → idempotencia de sincronización.
5. Contenido de tenant (`organizacion_id_contenido`) jamás se sirve a otro tenant a nivel de query scope + prueba automatizada de aislamiento.
