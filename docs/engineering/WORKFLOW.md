# Flujo obligatorio de desarrollo

## 1. Clasificar

Categorías: `UI-CSS`, `FRONTEND`, `API-YII`, `DB`, `BILLING`, `TELEPHONY`, `CAMPAIGN`, `SECURITY` y `RELEASE`.

- **Bajo:** documentación o cambios UI limitados y sin comportamiento modificado.
- **Medio:** stores, CRUD, componentes UI compartidos, contrato HTTP.
- **Alto:** permisos, clientes, campañas, cron, trunks y migraciones.
- **Crítico:** cobros, recargas, tarifas, despacho real de llamadas y cambios destructivos.

El riesgo define revisión y pruebas; no se rebaja por urgencia.

## 2. Descubrir (G0)

Leer `AGENTS.md`, `ARCHITECTURE.md`, `PROJECT.md` y el dominio correspondiente. En un checkout local:

```sh
git branch --show-current
git rev-parse HEAD
git status --short
git remote -v
git log -5 --oneline
```

Registrar rama/HEAD. Comparar `source`, `main` y `development` solo para comprender diferencias; no asumir sincronía. No cambiar de rama ni descartar cambios locales sin permiso. Los agentes deberían crear `fix/*` o `feat/*` desde `development` para código; commit directo a `development` solo con autorización expresa.

## 3. Investigar (G1)

Antes de editar:
1. Registrar síntoma, reproducción y esperado vs obtenido.
2. Separar [OBSERVADO] de [HIPOTESIS] y [CODIGO].
3. Identificar entrada → validación/ACL → vista/controller/model/servicio → DB/AMI/AGI → salida.
4. Verificar versiones, recursos, permisos, estados y caminos alternativos.
5. Identificar riesgos laterales y pruebas de regresión.
6. Cuando falta acceso al navegador/servidor, marcar `BLOCKED`: no inventar reproducción.

## 4. Planear (G2)

Entregar contrato de tarea con actores, scope, no-scope, archivos, esquema de datos, permisos, estados, errores, criterios de aceptación positivos/negativos y rollback. Para features grandes definir vertical slices revisables. La arquitectura es responsable de los límites; el planner de la aceptación.

## 5. Implementar (G3)

Cambio mínimo; conservar personalizaciones; aplicar controles server-side. No añadir `!important` globales por intuición. No introducir dependencias ni modificar código Ext JS vendor si una extensión localizada resuelve el caso. Regenerar bundles solo cuando corresponda. Incluir traducciones, migraciones compatibles y documentación del comportamiento.

## 6. Verificar (G4) y revisar (G5)

Ejecutar pruebas reales y registrar comando, entorno, resultado y evidencia por caso. Contemplar roles y datos ajenos, dependencias fallidas, concurrencia, recuperación y regresión. QA hace pruebas negativas independientes; AppSec revisa auth/ACL/CSRF, inyecciones y secretos. Un mock no es aceptación E2E; la sintaxis no es prueba de cobro o telefonía. Consultar [QA_EVIDENCE.md](QA_EVIDENCE.md).

## 7. Preparar publicación (G6)

Revisar diff, SHA, build/artefactos, migración, backups, staging y rollback. Cualquier despliegue, merge a `main`, reset, llamadas o cambios de producción exigen autorización separada. Ver [RELEASE.md](RELEASE.md).

## 8. Cierre del agente

```text
TAREA / RIESGO / RAMA / SHA:
SÍNTOMA Y CAUSA (o hipótesis restante):
ARCHIVOS Y CONTRATOS CAMBIADOS:
PRUEBAS: PASS / FAIL / NOT RUN / BLOCKED (ID + pasos + evidencia):
RIESGOS, REGRESIONES Y ROLLBACK:
DEPLOY: NO REALIZADO / STAGING / PRODUCCIÓN (con autorización)
```

Nunca declarar la tarea "QA aprobada" con criterios críticos sin ejecutar.
