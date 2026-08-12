# MENTIA — Documento 04: Catálogo de Instrumentos y Plan de Seed

Versión 1.0 — Agosto 2026

Leyenda de **estatus de licencia**:
- **DP** = dominio público / uso libre documentado → se precarga **completo** (reactivos, claves, baremos, interpretaciones)
- **LIC** = con copyright, difundida comercialmente → se precarga **esqueleto** (escalas, algoritmo, baremos de literatura, interpretaciones); el tenant captura reactivos bajo su licencia declarada
- **CAP** = restringida / no viable en línea → **solo captura de protocolo** (el profesional registra puntajes; el sistema barema donde sea legal e integra al expediente)

> ⚠️ Los estatus marcados aquí son punto de partida técnico, no dictamen legal. Antes de liberar cada instrumento DP debe verificarse su término de uso vigente (varios exigen registro, atribución o uso no comercial) y documentarse la fuente en `instrumentos.referencia_bibliografica`. Los LIC requieren la declaración de licencia firmada del tenant.

---

## 1. Personalidad

### 1.1 Laboral / conductual
| Instrumento | Estatus | Dominio | Sensib. | Notas de seed |
|---|---|---|---|---|
| Cleaver (DISC) | LIC | personalidad | 2 | Esqueleto completo: 4 escalas × 3 perfiles (M/L/T), tipo eleccion_forzada_cuadro, ~15 perfiles tipo con interpretaciones propias |
| Kostick (PAPI) | LIC | personalidad | 2 | 20 factores/7 áreas, elección forzada par |
| Moss | LIC | personalidad | 2 | 30 reactivos opción múltiple, 5 áreas de juicio social |
| Gordon PPG-IPG | LIC | personalidad | 2 | 8 escalas, elección forzada |
| LIFO | LIC | personalidad | 2 | 4 estilos, condiciones favorables/estrés |
| DISC genérico propio | DP* | personalidad | 2 | Redacción propia de reactivos DISC (el modelo teórico no es propiedad; los reactivos de Cleaver sí) — alternativa incluible completa |
| Hogan (HPI/HDS/MVPI) | CAP | personalidad | 2 | Plataforma editorial; solo captura |
| Predictive Index | CAP | personalidad | 2 | Solo captura |

### 1.2 Rasgos
| 16PF | LIC | personalidad | 3 | 16 factores + 2o orden, decatipos |
| Big Five (IPIP) | DP | personalidad | 2 | **IPIP (International Personality Item Pool) es dominio público explícito**: IPIP-NEO-120/300 y BFI-equivalentes precargables completos en español — el sustituto legal perfecto de NEO-PI |
| EPQ | LIC | personalidad | 3 | Esqueleto |
| MBTI | CAP | personalidad | 2 | Marca registrada estricta; alternativa: tipologías propias sobre Big Five |

### 1.3 Clínica
| MMPI-2 / MMPI-2-RF | CAP | emocional | 4 | Pearson; solo captura |
| MCMI | CAP | emocional | 4 | Solo captura |
| SCL-90-R | LIC | emocional | 3 | Esqueleto |

### 1.4 Proyectivas
| Machover, HTP, Árbol, Frases Incompletas (Sacks), Wartegg | LIC/CAP | personalidad | 4 | Captura_dibujo/texto + dictamen del profesional; sin calificación algorítmica |
| Rorschach, TAT | CAP | personalidad | 4 | Solo captura de dictamen |

## 2. Inteligencia y aptitudes

| Instrumento | Estatus | Dominio | Sensib. | Notas |
|---|---|---|---|---|
| Terman-Merrill | LIC | cognitivo | 2 | 10 series, bloques cronometrados, CI + rango |
| Raven (SPM/APM/CPM) | LIC | cognitivo | 2 | matriz_visual, baremos percentilares por edad |
| Beta III/IV | CAP | cognitivo | 2 | Pearson |
| Barsit | LIC | cognitivo | 2 | 60 reactivos, 10 min |
| Wonderlic | CAP | cognitivo | 2 | |
| WAIS-IV / WISC-V / WPPSI-IV | CAP | cognitivo | 4 | Captura de protocolo con índices |
| Stanford-Binet 5 | CAP | cognitivo | 4 | Captura |
| Otis, Dominós D-48/70, Naipes G | LIC | cognitivo | 2 | Esqueleto |
| DAT / PMA / GATB | LIC-CAP | cognitivo | 2 | Esqueleto por batería de aptitudes |
| Bennett | LIC | cognitivo | 2 | Comprensión mecánica |
| Minnesota oficinesco | LIC | cognitivo | 2 | Rapidez/precisión |
| IPV | LIC | personalidad | 2 | Ventas |
| Matrices tipo-Raven propias / razonamiento abstracto propio | DP* | cognitivo | 2 | Banco propio de reactivos de razonamiento, generable y precargable completo |

## 3. Vocacional

| O*NET Interest Profiler (RIASEC) | **DP** | vocacional | 1 | **Precarga estrella**: 60 reactivos dominio público (US DoL), 6 escalas RIASEC, códigos de 3 letras, crosswalk completo a ocupaciones |
| Herrera y Montes | verificar/DP probable | vocacional | 1 | Uso generalizado en educación pública MX; verificar y precargar |
| Kuder | CAP | vocacional | 1 | Editorial |
| Holland SDS oficial | CAP | vocacional | 1 | PAR; la vía libre es O*NET |
| Estilos de aprendizaje (Kolb-inspirado libre, VARK con permiso) | DP* | vocacional | 1 | Redacción propia |

## 4. Valores y motivación

| Zavic | LIC | valores | 2 | 20 reactivos ranking, 4 valores + 4 intereses |
| Allport-Vernon-Lindzey | LIC | valores | 2 | Esqueleto |
| Schwartz PVQ | DP (uso académico; verificar) | valores | 2 | Posible precarga con atribución |
| Cuestionario de valores propio | DP* | valores | 2 | Redacción propia |

## 5. Emocional / clínico breve — **núcleo de precarga completa**

| Instrumento | Estatus | Sensib. | Seed |
|---|---|---|---|
| **PHQ-9 / PHQ-2** | **DP** (Pfizer, uso libre) | 3 | Completo: cortes 5/10/15/20, **reactivo 9 = centinela** |
| **GAD-7 / GAD-2** | **DP** | 3 | Completo: cortes 5/10/15 |
| **Zung ansiedad / depresión** | DP | 3 | Completo |
| **PSS-10/14** (estrés percibido) | DP | 3 | Completo |
| **WHO-5** (bienestar, OMS) | DP | 2 | Completo |
| **AUDIT / AUDIT-C** (OMS) | DP | 3 | Completo |
| **C-SSRS screener** | DP con registro | 4 | Completo + **protocolo centinela crítico obligatorio** |
| IDARE (STAI) | LIC | 3 | Esqueleto |
| BDI-II, BAI | CAP (Pearson) | 4 | Solo captura |
| **MBI (Maslach)** | LIC (Mind Garden) | 3 | Esqueleto; alternativa DP: escalas de burnout de literatura abierta (p.ej. BAT con permiso) |
| **UWES** (engagement) | DP no comercial c/registro | 2 | Verificar términos para SaaS; posible completo |

## 6. Cumplimiento NOM-035 — **precarga completa, gancho comercial**

| GR-I (acontecimientos traumáticos severos) | **DP (DOF)** | 3 | Completo con algoritmo de secciones |
| GR-II (≤50 trabajadores) | **DP (DOF)** | 3 | Completo: dominios, categorías, cortes y semáforo oficiales |
| GR-III (>50, + entorno organizacional) | **DP (DOF)** | 3 | Completo + reporte por centro de trabajo formato STPS |

## 7. Tamizaje TEA / desarrollo

| **M-CHAT-R/F** | **DP uso clínico/educativo** | 3 | Completo: 20 reactivos + algoritmo 2 etapas + entrevista de seguimiento (entrevista_estructurada) |
| SCQ | CAP (WPS) | 3 | Solo captura o esqueleto |
| **AQ-10 / AQ-50, CAST, ASSQ** | DP investigación/clínica | 3 | Completos con verificación de términos |
| SRS-2 | CAP | 3 | Captura |
| **EDI** | Sector salud MX (verificar uso privado) | 3 | Ideal completo; en su defecto, tamizaje propio por hitos CDC/OMS (DP*) |
| ASQ-3 | CAP (Brookes) | 3 | Captura |
| Denver II | CAP | 3 | Captura |
| ADOS-2, ADI-R, CARS-2, Vineland-3, ABAS-3, Bayley-4, Battelle-2 | CAP | 4 | Formularios de captura de protocolo + expediente |

## 8. Integridad y confiabilidad

| AMITAI / Midot / similares | CAP | 2 | Integración por captura o API futura |
| Cuestionario de integridad propio (SJT) | DP* | 2 | Redacción propia precargable |

## 9. Competencias / organizacional

| SJT por competencias (motor propio) | DP* | 2 | Constructor de casos situacionales del tenant |
| MLQ, Bar-On, MSCEIT | CAP | 2 | Captura |
| TMMS-24 | DP académico (verificar) | 2 | Posible completo |
| Clima laboral / cultura / satisfacción / **eNPS** | DP* | 1 | Bancos propios precargados; modo anónimo |
| Evaluación 360° | DP* (motor propio) | 2 | V2+: estructura multievaluador |

## 10. Neuropsicológicas / atención

| Bender, Toulouse-Piéron, d2, Stroop, Benton | LIC/CAP | 4 | d2 y Toulouse: esqueleto con captura o versión digital si se licencia; Bender: captura_dibujo + dictamen |
| MMSE / MoCA | LIC (MoCA requiere certificación) | 4 | Captura |

*(DP\* = contenido de redacción propia sobre modelos teóricos no protegidos: se precarga completo porque el texto es original del sistema.)*

---

## Plan de seed por olas

**Ola 1 (lanzamiento V1):**
NOM-035 GR-I/II/III · O*NET RIASEC + crosswalk ocupaciones · PHQ-9/2 · GAD-7/2 · WHO-5 · PSS · AUDIT · Zung ×2 · M-CHAT-R/F · AQ-10 · Big Five IPIP-120 · eNPS/clima propio · DISC propio · centinelas y protocolos de C-SSRS screener.
*Cobertura comercial inmediata: empresa (NOM-035, clima, DISC propio, Big Five), escuela (vocacional, M-CHAT, tamizajes), clínica (PHQ/GAD/C-SSRS).*

**Ola 2:** esqueletos LIC de la batería mexicana clásica: Terman, Cleaver, Zavic, Moss, Kostick, Gordon, Barsit, Raven, IPV, 16PF, IDARE, MBI — con algoritmos, baremos de literatura, perfiles tipo e interpretaciones listas para "encender" al capturar reactivos.

**Ola 3:** captura de protocolo de restringidos (Wechsler, ADOS-2/ADI-R, Vineland, Bayley, MMPI) + AQ-50/CAST/ASSQ + TMMS-24/UWES verificados.

**Ola 4:** SJT builder, 360°, banco propio de razonamiento, integraciones editoriales con créditos.

## Formato de importación

Toda carga de contenido (seed propio o captura del tenant) usa la misma plantilla Excel/CSV normalizada: hojas `instrumento`, `escalas`, `bloques`, `reactivos`, `opciones`, `claves`, `baremos`, `interpretaciones` — validada por un importador con reporte de errores fila a fila. El seed oficial del sistema se versiona en el repositorio como archivos de datos + seeders idempotentes.
