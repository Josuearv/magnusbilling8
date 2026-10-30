# Playbook backend — Yii 1.1, DB, billing y Asterisk

## Trazar el flujo real

- Web: `index.php` → `protected/controllers/` → `protected/components/` / `protected/models/` → MariaDB/AMI → JSON y store Ext JS.
- Console: `cron.php` → `protected/commands/*Command.php` → servicios/DB/archivos/Asterisk.
- Telefonía: SIP/PJSIP → Asterisk → AGI `resources/asterisk/` → tarifas/rutas/saldos → CDR y estado.

Antes de editar: método de entrada, validaciones, auth, ACL por rol/propietario, datos, errores, efectos externos y variantes.

## Controles obligatorios

- Respetar auth, CSRF, sesión y ACL **server-side**; un campo escondido no protege datos. Probar usuario sin permiso y acceso a registro de otro cliente.
- Parametrizar SQL, validar tipos y límites, escapar respuestas no confiables y redactar logs con PII/secretos. No asumir IDs de grupos ni nombres de columnas sin schema real.
- Para tablas/campos nuevos: revisar `protected/commands/UpdateMysqlCommand.php` y `script/database.sql`, plan de compatibilidad de instalaciones existentes y limpias, backups y rollback.
- Cobros, tarifas y recargas: determinismo monetario, transacciones, redondeo, idempotencia y reintentos sin doble débito/crédito; usar DB de ensayo.
- Cron: locking, retries, alertas y código de salida coherente; nunca registrar `FAILED` y devolver éxito sin contrato justificado.
- Campañas: límites de destinatario/cliente, permisos, reservas, concurrencia, ficheros `.call`, estado y rollback.

## Caller ID y telefonía

- Separar Caller ID fijo, vacío, `DYNAMIC` y reglas de cada campaña. Inspeccionar `MassiveCallCommand.php` y `MassiveCallBeforeDial.php` antes de cambiar; no seleccionar números de otro usuario sin un permiso explícito.
- Asterisk 20/PJSIP es referencia para instalaciones nuevas; el flujo real puede depender de `$providertech`. No forzar un cambio global de dialstrings sin pruebas.
- AMI/AGI, shell y Asterisk CLI son límites de confianza; comandos allowlisted, timeouts y salida sanitizada.
- Para CDR failed, consultar [CALL_FAILED_DIAGNOSTIC_CORRELATION.md](../../CALL_FAILED_DIAGNOSTIC_CORRELATION.md). Correlacionar por `(uniqueid, id_server)` cuando existan eventos; no rellenar con match de número/hora como certeza.
- Distinguir estado **actual** de trunk de causa histórica de una llamada.
- Prohibido probar sobre trunks, saldos o números de cliente reales sin autorización y laboratorio.

## Verificación no equivalente

`php -l` valida sintaxis, no autorización, efectos DB o facturación. Prueba real para Yii: HTTP con roles, errores, persistencia, registros ajenos y reintentos. Para campañas/VoIP: cron, despacho, respuesta SIP/AMI/AGI y CDR de test correlacionado en entorno aislado. Para billing: idempotencia/transacciones/edge cases. Ver [QA_EVIDENCE.md](QA_EVIDENCE.md).
