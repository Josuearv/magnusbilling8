# MagnusBilling 8 · Engineering OS (fork Josuearv)

Esta documentación organiza la ingeniería asistida por IA. **El código de la revisión exacta y las pruebas observadas mandan sobre ejemplos de los documentos.** No se han reparado funcionalidades al publicar estas guías.

## Índice obligatorio

| Documento | Propósito |
| --- | --- |
| [AGENTS.md](../../AGENTS.md) | Reglas iniciales, identidad del stack y precauciones |
| [WORKFLOW.md](WORKFLOW.md) | Flujo desde diagnóstico hasta entrega, gates y roles |
| [FRONTEND_EXTJS.md](FRONTEND_EXTJS.md) | CSS, theme, grids, formularios, bundles y UI autenticada |
| [BACKEND_TELEPHONY.md](BACKEND_TELEPHONY.md) | Yii, MariaDB, billing, ACL, cron, PJSIP, AMI/AGI |
| [QA_EVIDENCE.md](QA_EVIDENCE.md) | QA con evidencia y sin pruebas fantasma |
| [RELEASE.md](RELEASE.md) | Git, artefactos, staging, promoción y rollback |
| [TEMPLATES.md](TEMPLATES.md) | Prompts repetibles, especificación de tareas y entrega |
| [BACKLOG.md](BACKLOG.md) | Hallazgos iniciales pendientes de investigación |

## Documentos del producto que se conservan

- [ARCHITECTURE.md](../../ARCHITECTURE.md) y [PROJECT.md](../../PROJECT.md): arquitectura y prioridades.
- [CONTRIBUTING.md](../../CONTRIBUTING.md): contribuciones y pruebas del proyecto.
- [ia-docs/README.md](../../ia-docs/README.md): política de fuentes y catálogo RAG.
- [qa_protocol.md](../../ia-docs/playbooks/qa_protocol.md): preguntas técnicas; los criterios de pruebas para cambios se amplían en `QA_EVIDENCE.md`.
- [frontend_boot.md](../../ia-docs/playbooks/frontend_boot.md): arranque y bundle; **no** demuestra QA visual.
- [create_new_module_pkg_example.md](../../ia-docs/playbooks/create_new_module_pkg_example.md): ejemplos de módulos, no IDs ni SQL universalmente aplicables.
- [CAMPAIGN_OPTIMIZER_PLAN.md](../CAMPAIGN_OPTIMIZER_PLAN.md): especificación del optimizador; distinguir diseño de funcionamiento real.

## Jerarquía de evidencia

- **[OBSERVADO]** reproducido en navegador/DB/log/test con entorno y fecha.
- **[CODIGO]** comprobado en archivo y SHA; puede no estar desplegado.
- **[HIPOTESIS]** explicación plausible todavía no verificada.
- **[DECISION]** decisión aprobada con criterios y trade-offs.
- **[PENDIENTE]** comprobación que no se ejecutó o está bloqueada.

## Roles de trabajo

Planner (requisitos), arquitecto (dependencias/contratos), frontend Ext JS, backend Yii, experto DB/VoIP, QA independiente, AppSec y Release. Son responsabilidades, no una obligación de ejecutar ocho procesos. En alto riesgo, el implementador **no** se aprueba a sí mismo.

Las nuevas features y fixes deben registrar rama/SHA, impacto, criterios de aceptación, estado de pruebas y operaciones de despliegue **realmente** efectuadas.
