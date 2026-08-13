# MENTIA — Vulneraciones de seguridad y restauración

Procedimiento operativo. Cubre la obligación del Doc 06 §4 ("Gestión de
incidentes") y el requisito de **pruebas de restauración periódicas**.

Esto no es documentación de arquitectura: es lo que alguien tiene que hacer un
martes a las tres de la mañana. Está escrito para leerse en ese estado.

---

## 1. Qué es una vulneración que hay que notificar

La LFPDPPP (art. 20) obliga a informar **de forma inmediata** al titular cuando
ocurre una vulneración que **afecte de forma significativa sus derechos
patrimoniales o morales**. No toda incidencia lo es.

Sí lo es, en este sistema:

- Acceso no autorizado a `respuestas`, `expediente_valores`,
  `notas_profesionales`, `resultados_interpretacion` o cualquier reporte
  generado.
- Fuga de `APP_KEY` junto con acceso —aunque sea de sólo lectura— a la base.
- Fuga de tokens de aplicación **en claro** (los de la base están hasheados; los
  que viajan en las ligas de correo, no).
- Pérdida de datos sin respaldo recuperable.
- Cualquier fuga cross-tenant: un tenant que vio expedientes de otro.

**No** lo es por sí solo: un intento de acceso denegado y registrado en bitácora,
una caída de servicio sin pérdida de datos, un correo de invitación enviado a la
dirección equivocada de la misma persona.

Ante la duda, se trata como si lo fuera. Notificar de más cuesta una disculpa;
notificar de menos cuesta una sanción y la confianza de las personas evaluadas.

---

## 2. Las primeras dos horas

En orden. No se salta ningún paso por conveniencia.

1. **Contener.** Revocar sesiones (`php artisan session:flush` si es sesión en
   base o Redis), invalidar tokens de API comprometidos, cerrar el acceso del
   usuario o la IP implicada. Si la sospecha es sobre `APP_KEY`, ver §4.
2. **NO borrar nada.** Ni logs, ni filas, ni cuentas. La `bitacora` es
   append-only y el usuario MySQL de la aplicación va sin `DELETE` sobre ella
   justamente para este momento. Lo que se borre "para limpiar" es lo que después
   no se puede reconstruir.
3. **Congelar evidencia.** Copia de los logs del servidor web, de
   `storage/logs`, y un volcado de `bitacora` del periodo. A un directorio
   aparte, con fecha en el nombre.
4. **Delimitar.** Qué tablas, qué organizaciones, cuántas personas. La consulta
   de partida es la bitácora:

   ```sql
   SELECT actor_persona_id, organizacion_id, accion, recurso_tipo,
          COUNT(*) AS veces, MIN(registrado_en), MAX(registrado_en)
   FROM bitacora
   WHERE registrado_en BETWEEN ? AND ?
   GROUP BY 1,2,3,4
   ORDER BY veces DESC;
   ```

5. **Avisar al responsable del tratamiento.** Mentia es el ENCARGADO; el tenant
   es el responsable ante el titular (Doc 06 §3). La notificación a las personas
   la hace la organización, con nuestro insumo. Que lo haga ella no nos exime de
   dárselo completo y a tiempo.

---

## 3. Qué se le dice al titular

Lo que la ley pide, y en este orden:

1. La naturaleza de la vulneración.
2. Qué datos personales se comprometieron. Con nombre: "los resultados de tu
   tamizaje de ansiedad", no "cierta información".
3. Las recomendaciones para que tome medidas.
4. Las acciones correctivas ya realizadas.
5. Cómo obtener más información.

**En español llano.** Una persona que recibe un aviso lleno de tecnicismos no
puede tomar ninguna medida, que es justamente lo que el aviso existe para
permitir.

---

## 4. Si se comprometió `APP_KEY`

Es el peor caso: con la llave y una copia de la base se lee todo lo cifrado.

1. Generar llave nueva: `php artisan key:generate --show` (no la aplica).
2. Simulacro: `php artisan mentia:rotar-llave --nueva=base64:… --simular`.
3. Rotar de verdad: el mismo comando sin `--simular`.
4. **Después** poner la nueva en `APP_KEY` y reiniciar.

El orden importa: mientras el comando corre, la aplicación tiene que poder
seguir descifrando con la llave vieja lo que todavía no se convirtió.

Si el simulacro reporta valores ilegibles, **no se rota**: significa que algo ya
venía cifrado con otra llave, y rotar el resto dejaría la base a medias.

---

## 5. Restauración de respaldo

### Cada cuánto se prueba

**Trimestral, y después de cada cambio de esquema mayor.** Un respaldo que nunca
se restauró es una suposición, no un respaldo.

### Cómo se prueba

Se restaura sobre una base **desechable**, nunca sobre producción:

```bash
mysql -e "CREATE DATABASE mentia_restauracion"
gunzip < respaldo-AAAA-MM-DD.sql.gz | mysql mentia_restauracion
```

Y se verifica que los datos cifrados se leen —es lo único que prueba que la
llave del entorno corresponde con el respaldo—:

```bash
php artisan tinker --execute="
  config(['database.connections.mysql.database' => 'mentia_restauracion']);
  DB::purge('mysql');
  echo App\Domain\Evaluaciones\Modelos\Respuesta::whereNotNull('valor_texto')->first()?->valor_texto;
"
```

Si eso imprime texto legible, el respaldo sirve. Si lanza `DecryptException`, el
respaldo está cifrado con una llave que ya no existe y **no sirve para nada**:
hay que localizar la llave de esa fecha antes de seguir.

### Qué se anota

Después de cada prueba, una línea en la bitácora de operación: fecha, respaldo
probado, resultado, tiempo que tomó. El tiempo importa: es lo que se le promete
a un tenant cuando pregunta cuánto tarda una recuperación.

### Qué no se purga nunca

`bitacora`, `resultados_archivados` y sus hijas. Son la evidencia que la LFPDPPP
obliga a conservar y lo que permite reconstruir una calificación impugnada
—incluso años después, cuando el baremo con el que se calculó ya no exista—.

---

## 6. Después

- **Escribir qué pasó.** Un documento corto: qué falló, cómo se detectó, qué se
  hizo, en cuánto tiempo, qué se cambió para que no vuelva a pasar.
- **Una prueba automatizada por cada fuga real.** Si algo se filtró, la suite de
  `tests/Feature/Aislamiento/` tiene que crecer con ese caso exacto. Un incidente
  que no deja una prueba detrás se repite.
