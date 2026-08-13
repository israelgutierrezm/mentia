# MENTIA — Despliegue

Lo que hace falta para poner esto en producción, y las decisiones de operación
que no se pueden dejar para después.

---

## 1. Requisitos

| Componente | Versión | Nota |
|---|---|---|
| PHP | 8.3 | 8.4 sube `spatie/laravel-activitylog` a 5.x |
| Extensiones | `pcntl`, `posix`, `intl`, `mbstring`, `gd`, `zip` | `pcntl`/`posix` sólo en el servidor de colas: **Horizon no corre en Windows** |
| MySQL | 8.0+ | InnoDB, `utf8mb4_0900_ai_ci` |
| Redis | 7+ | Colas y caché |
| Node | 20+ | Sólo para compilar; el servidor no lo necesita en ejecución |

`composer.json` declara un override en `config.platform` para poder resolver
dependencias en máquinas de desarrollo sin `pcntl`. **En el servidor de
producción las extensiones tienen que estar de verdad**: el override sólo
engaña al resolvedor, no a PHP.

---

## 2. Variables de entorno

Además de las de Laravel:

```
APP_KEY=                  # obligatoria: sin ella no se descifra NADA
APP_TIMEZONE              # NO HACE NADA en Laravel 12. La zona está en config/app.php

DB_CONNECTION=mysql
REDIS_HOST / REDIS_PASSWORD / REDIS_PORT
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
CACHE_STORE=redis

MAIL_MAILER / MAIL_HOST / MAIL_PORT / MAIL_USERNAME / MAIL_PASSWORD
MAIL_FROM_ADDRESS

ANTHROPIC_API_KEY=        # sin ella el integrador falla ruidoso, no en silencio
IA_MODELO=claude-sonnet-5
IA_MAX_TOKENS=2000

MENTIA_PAIS_NORMA=MX
MENTIA_NIVEL_MINIMO_2FA=3 # no baja de 3 aunque se configure más bajo
MENTIA_DETENER_SI_INVALIDA=true

SANCTUM_STATEFUL_DOMAINS= # el dominio de la SPA, para la sesión por cookie
```

### La llave de cifrado

`APP_KEY` descifra las notas profesionales, las respuestas abiertas y los
secretos de 2FA. **Perderla es perder esos datos**, no poder regenerarlos:
guárdala en un gestor de secretos, no en el repositorio ni en el respaldo de la
base —un respaldo que incluye la llave que lo descifra no es un respaldo
cifrado—.

Rotarla exige descifrar y volver a cifrar cada fila afectada. No hay comando
todavía; escribirlo es trabajo pendiente antes de la primera rotación.

---

## 3. Puesta en marcha

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build

php artisan migrate --force
php artisan db:seed --force        # idempotente: permisos, catálogos, plantillas

php artisan config:cache
php artisan route:cache
php artisan view:cache
```

`db:seed` se puede volver a correr en cada despliegue: todos los seeders usan
`updateOrCreate`. Es lo que mantiene el catálogo de permisos y las plantillas de
reporte al día sin migraciones de datos.

---

## 4. Colas

Cuatro colas con supervisores propios (Doc 02 §7):

| Cola | Qué lleva | Por qué aparte |
|---|---|---|
| `alertas` | Notificación de riesgo | No puede esperar detrás de nada |
| `calificacion` | Las seis etapas del pipeline | El grueso del trabajo |
| `notificaciones` | Invitaciones y recordatorios | Volumen alto, urgencia baja |
| `reportes-ia` | Integrador con IA | Llamadas lentas a un tercero |

```bash
php artisan horizon
```

En desarrollo local, donde Horizon no corre:

```bash
php artisan queue:listen --queue=alertas,calificacion,notificaciones,reportes-ia
```

**El orden de la lista importa**: `queue:listen` atiende las colas en el orden
en que se declaran, así que `alertas` va primero.

---

## 5. Tareas programadas

`routes/console.php` declara el `Schedule`. Hace falta el cron del sistema:

```
* * * * * cd /ruta/al/proyecto && php artisan schedule:run >> /dev/null 2>&1
```

Lo que corre: vencimiento de consentimientos, transición de mayoría de edad,
recordatorios de asignaciones. Sin este cron **nada de eso ocurre**, y el
sistema no se queja: los consentimientos vencidos siguen dejando pasar.

---

## 6. Base de datos

### El usuario de la aplicación NO puede tocar la bitácora

Requisito del Doc 06 §4. La inmutabilidad de `bitacora` está garantizada en el
modelo, pero un modelo se esquiva con un `DB::table()`:

```sql
REVOKE UPDATE, DELETE ON mentia.bitacora FROM 'mentia_app'@'%';
```

### Respaldos

Diarios, cifrados, con retención escalonada. **Y con pruebas de restauración
periódicas**: un respaldo que nunca se restauró es una suposición, no un
respaldo.

La bitácora y los resultados archivados no se purgan con el resto: son la
evidencia que la LFPDPPP obliga a conservar y lo que permite reconstruir una
calificación impugnada.

---

## 7. Seguridad de operación

- **TLS 1.2+ con HSTS.** Todo el tráfico, sin excepción: por aquí viajan tokens
  de aplicación y expedientes clínicos.
- **2FA obligatoria** para roles de sensibilidad 3–4. Se impone en web y en API;
  quien no la tiene queda bloqueado hasta activarla.
- **Rate limiting.** El canje de token va a 30/min por ser adivinable; los
  endpoints de aplicación a 180/min para no sacar a quien contesta rápido de su
  propia evaluación.
- **`public/hot`.** Si quedó de un `npm run dev` muerto, secuestra el frontend y
  ningún `npm run build` se ve. Borrarlo es parte del despliegue.

---

## 8. Antes de habilitar instrumentos con centinelas

El sistema lo bloquea, pero conviene saberlo antes: una organización que vaya a
aplicar un PHQ-9 o un M-CHAT necesita **registrar su protocolo de actuación** y
**designar destinatarios de alertas críticas**. Sin las dos cosas, la asignación
se rechaza.

No es burocracia: encender un detector de ideación suicida sin decir quién
responde produce una alerta a las once de la noche en un buzón que nadie mira
hasta el lunes.

---

## 9. Lo que falta

Honestamente, para que esto sea producción:

- **Contenido de instrumentos.** La Fase 4 está bloqueada: los reactivos del
  PHQ-9, del M-CHAT-R/F y de las Guías de Referencia de la NOM-035 no se
  inventan. Sin ellos hay maquinaria y no hay pruebas que aplicar.
- **Comando de rotación de `APP_KEY`.**
- **Procedimiento de notificación de vulneraciones** (obligación LFPDPPP ante
  afectación significativa): el Doc 06 §4 lo exige documentado y todavía no
  existe.
- **Pruebas de restauración de respaldo**, documentadas con su fecha.
