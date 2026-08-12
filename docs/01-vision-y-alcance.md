# MENTIA — Documento 01: Visión y Alcance

**Sistema de Evaluación Psicométrica Longitudinal Multi-tenant**
Versión 1.0 — Agosto 2026

---

## 1. Concepto central

Mentia es una plataforma SaaS multi-tenant para la **aplicación, calificación, interpretación y seguimiento longitudinal de pruebas psicométricas, laborales, vocacionales, clínicas breves y de desarrollo**, construida sobre el principio del **expediente psicométrico de vida**: la persona es la entidad raíz y permanente; las evaluaciones son eventos que se acumulan a lo largo de su línea de tiempo, desde el tamizaje de desarrollo en preescolar hasta la selección laboral en la adultez.

**Analogía rectora (modelo hospitalario):** así como un expediente clínico electrónico acumula estudios de laboratorio e imagen y permite ver la evolución de cada "órgano" en el tiempo, Mentia acumula evaluaciones y muestra la evolución de cada **dominio** de la persona (cognitivo, emocional, personalidad, intereses, adaptativo) en puntuaciones normalizadas comparables entre edades.

## 2. Principios de diseño

| # | Principio | Implicación |
|---|-----------|-------------|
| P1 | **Persona ≠ Aplicación** | La persona es global y permanente (ancla: CURP); los procesos pertenecen a tenants. Su expediente la acompaña entre organizaciones, bajo su consentimiento. |
| P2 | **Relacional puro** | MySQL 8, cero JSON en datos de dominio. Una fila por reactivo, una fila por respuesta, una fila por regla. |
| P3 | **Config-driven** | Ningún instrumento se programa; todos se describen mediante catálogos (tipos de reactivo, escalas, claves, baremos, reglas de interpretación). Agregar una prueba nueva = cargar configuración, no código. |
| P4 | **Versionado inmutable** | Instrumentos, baremos, textos de consentimiento e interpretaciones se versionan. Las aplicaciones históricas apuntan a la versión exacta con la que ocurrieron. |
| P5 | **API-first** | Toda la lógica vive en la capa de dominio; web (Inertia) y app (Flutter) son clientes del mismo contrato. |
| P6 | **El sistema sugiere, nunca diagnostica** | Toda salida es "perfil compatible con / se sugiere canalización"; el diagnóstico y la firma son actos del profesional humano. |
| P7 | **Sensibilidad y consentimiento como compuertas** | Ningún permiso abre datos sin nivel de sensibilidad suficiente y consentimiento vigente que ampare el propósito. |
| P8 | **Licenciamiento estructural** | El catálogo distingue dominio público (precargado completo), con copyright (esqueleto precargado, contenido del tenant con licencia) y restringido (solo captura de protocolo). |

## 3. Actores del sistema

| Actor | Descripción | Cliente principal |
|-------|-------------|-------------------|
| Super administrador de plataforma | Opera el catálogo global, tenants, seeds | Web |
| Administrador de organización (tenant) | Configura roles, unidades, agrupaciones, habilita instrumentos | Web |
| Coordinador / Orientador / Reclutador | Arma baterías, lanza asignaciones grupales, monitorea avance, ve resultados hasta su nivel de sensibilidad | Web |
| Psicólogo / Profesional autorizado | Asignación individual discreta, captura de protocolos, notas clínicas, valida reportes de IA, atiende alertas centinela | Web |
| Docente / Supervisor de línea | Solo su alcance, vista resumida, sensibilidad 1–2 | Web |
| Titular (persona evaluada) | Responde evaluaciones, autollenado de expediente, consentimientos, resultados en vista amigable, controla qué comparte | App / Web / liga anónima |
| Tutor (padre/madre/tutor legal) | Igual que titular, en nombre de un menor; responde instrumentos de informante | App / Web / liga anónima |
| Examinador | Aplica y captura protocolos de instrumentos presenciales (WISC, CARS-2, Bender) | Web / App |
| Sistema externo (ATS, SIS, nómina) | Consume API v1 y webhooks | API |

## 4. Casos de uso rectores

1. **Tamizaje escolar anual (kínder/primaria):** el orientador lanza la batería "Tamizaje de desarrollo" al grupo 3°A; los padres responden M-CHAT-R/F por liga de WhatsApp; el sistema califica el algoritmo de dos etapas; riesgo medio/alto dispara automáticamente la etapa de seguimiento y notifica a psicología; el resultado queda en el dominio "Desarrollo temprano" del expediente del niño con sensibilidad psicológica.
2. **Orientación vocacional (secundaria/bachillerato):** batería vocacional (RIASEC O*NET + intereses) aplicada en modo adolescente; el alumno recibe "su mapa de intereses" con ocupaciones afines; el orientador ve el reporte grupal de distribución.
3. **Selección de personal (empresa):** plantilla "Selección mando medio" = Terman + Cleaver + Zavic + Moss (contenido licenciado del tenant), liga con vigencia de 7 días, comparador contra perfil de puesto, reporte integrador redactado por IA y validado por el psicólogo.
4. **Cumplimiento NOM-035 (empresa):** campaña anual GR-II/GR-III por centro de trabajo, anónima o nominal según configuración, con semáforos y reportes en formato STPS.
5. **Seguimiento clínico breve:** la psicóloga carga PHQ-9 + GAD-7 de seguimiento mensual a una persona canalizada; la serie temporal muestra la evolución; un reactivo centinela positivo dispara alerta inmediata.
6. **Expediente de vida:** la persona que fue evaluada en la escuela llega años después a una empresa tenant; al verificar identidad puede consentir vincular (o no) partes de su historial; el reclutador jamás ve dominios clínicos.

## 5. Alcance por versiones

- **V1 (núcleo web):** tenants, permisos con alcances, personas y expediente, catálogo global con seed de dominio público, motor de aplicación web, pipeline de calificación/interpretación, asignaciones individuales y grupales, baterías, alertas centinela, reportes individuales y grupales, API v1 en paralelo.
- **V2:** reportes integradores con IA, comparadores contra perfil de puesto, baremos propios del tenant, protocolos escalonados (reglas condicionales), captura de protocolos de instrumentos restringidos, webhooks.
- **V3 (app Flutter):** cliente de aplicación (modo infantil/adolescente/adulto/kiosco), offline con sincronización, informante remoto, notificaciones.
- **V4:** créditos por aplicación (convenios editoriales), integración con Proctorion para aplicación supervisada, marketplace de instrumentos propios del tenant, multi-idioma.

## 6. Fuera de alcance (explícito)

- Emisión de diagnósticos clínicos automatizados.
- Distribución de contenido de instrumentos con copyright sin licencia del tenant.
- Aplicación en línea de instrumentos cuya editorial lo prohíbe (WISC, ADOS-2, MMPI): solo captura de resultados.
- Sustitución del juicio profesional: toda interpretación de IA es borrador sujeto a validación y firma.

## 7. Suite documental

| Doc | Contenido |
|-----|-----------|
| 01 | Visión y alcance (este documento) |
| 02 | Arquitectura general |
| 03 | Modelo de datos (DER y diccionario) |
| 04 | Catálogo de instrumentos y plan de seed |
| 05 | Motor de calificación e interpretación |
| 06 | Seguridad, permisos y marco legal |
| 07 | Especificación API v1 |
| 08 | Plan de fases y prompts para Claude Code |
| PDF | Reporte técnico ejecutivo |
