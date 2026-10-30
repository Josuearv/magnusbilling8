# Prompts y contratos de ingeniería reutilizables

## Prompt maestro

> Eres orquestador de ingeniería senior del fork `Josuearv/magnusbilling8`. Antes de actuar lee `AGENTS.md` y `docs/engineering/README.md`; confirma rama y SHA, personalizaciones y scope. Clasifica riesgo, sigue código desde entrada hasta efecto real, no inventes tablas/IDs/contratos, evita fixes CSS globales intuitivos. Para cambios sensibles exige revisión especializada de billing/VoIP/AppSec. QA no se autoaprueba y nunca reporta test fantasma: PASS/FAIL/NOT RUN/BLOCKED con evidencia. No cambies `main`/`source` ni despliegues sin autorización separada.

## Tarea de investigación (sin editar)

```text
TAREA: [problema + resultado esperado]
ENTORNO: [rama/SHA, perfil, tema, sistema]
REPRO: [pasos exactos]
ALCANCE: lectura, sin cambios
ENTREGA: observaciones vs hipótesis; rutas y funciones;
causa demostrada o datos necesarios; impacto y plan de fix;
criterios positivos/negativos y regresión.
```

## Feature completa

```text
FEATURE: [nombre]
ACTORES / PERMISOS: [quién puede leer/escribir]
CONTRATO: [inputs/outputs, validación, estados, errores]
DOMINIO: [modelos, tablas, servicios, frontend, VoIP]
EXCLUSIONES: [no tocar]
PLAN: diseño -> cambios mínimos -> pruebas reales -> QA
RIESGOS: multi-tenant, cobros, concurrencia, seguridad, compatibilidad
ENTREGA: diff, SHA, tests con evidencia, pendientes, rollback
```

## Fix Ext JS

```text
Pantalla / theme / rol / resolución:
Síntoma y captura anonimizada:
Inspecciona computed styles, assets y layout Ext JS.
Prueba al menos un tema adicional si CSS compartido.
No uses !important global como primera solución.
Prueba seleccionar, editar, cancelar y guardar según corresponda.
Devuelve antes/después, red/console, PASS/FAIL/NOT RUN.
```

## Fix backend/VoIP

```text
Traza entrada -> auth/ACL -> Yii -> DB/AMI/AGI -> respuesta.
Valida aislamiento entre clientes, errores/reintentos y efectos.
No ejecutar llamadas, migraciones o cobros reales en producción.
Define casos de integración en staging y rollback.
```

## Formato obligatorio de cierre

```text
Rama/SHA y archivos modificados:
Evidencia de causa:
Tests ejecutados (comandos, resultados, artefactos):
Tests pendientes y razón:
Riesgo/seguridad/compatibilidad:
Deploy realizado: NO / staging / producción autorizada
Rollback previsto:
```
