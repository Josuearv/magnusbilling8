# Ramas, build, staging y release

## Contexto a verificar en cada tarea

Al 2026-10-30: `main` es default de GitHub y rama usada por el actualizador; `development` aloja personalizaciones del fork; `source` es espejo upstream. Cada nueva operación debe volver a comprobarlo. `protected/commands/update.sh` actualmente hace `git fetch origin main` seguido de `git reset --hard origin/main`. Ejecutarlo en un checkout con trabajo sin guardar puede destruir archivos.

## Flujo recomendado

1. Registrar SHA/estado real del checkout.
2. Trabajar desde `development`, preferentemente en `feat/*` / `fix/*`. Directo a `development` solo con autorización expresa.
3. PR y QA hacia `development` con el [template](../../.github/PULL_REQUEST_TEMPLATE.md).
4. Validar en staging con datos ficticios y Asterisk aislado si aplica.
5. Solo con aprobación del responsable, integrar a `main` mediante release revisado.
6. Despliegue desde commit exacto, con DB/cron/operaciones/paquetes y rollback definidos.
7. Validar assets y API que **realmente** se sirven, cron, logs y llamada de prueba autorizada.

La autorización de cambios en Markdown o rama de desarrollo **no autoriza** un deploy.

## Empaquetado frontend

```sh
node script/build-frontend.mjs
node script/build-frontend.mjs --check
node script/test-frontend-boot.mjs
```

El builder propio concatena archivos según `script/frontend-sources.json` y escribe bundle/manifiestos con hash; no equivale a toda la cadena Sencha Cmd. Nunca modificar artefactos generados sin conocer fuente/cadena. Al probar, distinguir:
- consistencia build,
- boot simulado,
- sesión autenticada en navegador,
- UI y funcionalidades reales.

## Gates de liberación

- [ ] Rama, commit y diff confirmados.
- [ ] Backups y rollback (si corresponde) ensayados.
- [ ] Migraciones/ACL/cron/trunks/recursos CSS y JS revisados.
- [ ] Tests relevantes PASS con evidencia; bloqueos expuestos.
- [ ] Seguridad y QA independiente para riesgos altos/críticos.
- [ ] Aprobación específica de promoción y producción.
- [ ] Monitoreo posterior/plan de abort preparado.

Si falta evidencia crítica, indicar `NOT RELEASE READY`; no "deploy exitoso" solo por push.
