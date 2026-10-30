# Backlog de investigación — 30 de octubre de 2026

Base de observación: `development` SHA `d628fa158bd95cfb1a5da450c3ddf4d473e6807f`. Este archivo **no acredita errores solucionados ni pruebas en servidor**. Confirmar situación antes de crear un fix.

## MB8-UI-001 · Campos, checkbox y toolbars (P1)

- **[OBSERVADO EN CAPTURA]** apariencia inconsistente en grid/checkbox, espaciado y formulario CallerID/Users.
- **[CODIGO]** `classic/src/ux/grid/Panel.js` usa `Ext.selection.CheckboxModel`; `classic/src/view/callerid/Form.js` usa `userlookup` y `booleancombo`; `resources/init.css` contiene reglas `!important` amplias sobre Ext JS.
- **[HIPOTESIS]** cascada, sprite del tema, geometría de layout o cache; **sin causa demostrada**.
- **[PENDIENTE]** browser autenticado, computed CSS, ruta de assets, antes/después, CRUD y al menos dos temas.

## MB8-FE-002 · Bundle y boot (P1)

- **[CODIGO/DOC]** `script/build-frontend.mjs` genera bundle/manifiestos; `ia-docs/playbooks/frontend_boot.md` registra incidencias 404/boot.
- **[PENDIENTE]** comprobar SHA desplegado, asset/version real, consola, fonts y sesión autenticada. El boot mock no valida UX.

## MB8-BE-003 · Rotación Caller ID (P0 para investigación)

- **[CODIGO]** `protected/commands/MassiveCallBeforeDial.php` contiene una condición que puede elegir un Caller ID aleatorio con cualquier valor no vacío y una consulta sin filtro explícito por propietario.
- **[RIESGO HIPOTÉTICO]** reemplazo de Caller ID fijo y uso cruzado de números.
- **[PENDIENTE]** confirmar contrato de producto, schema, escenarios fijo/DYNAMIC/vacío, dos clientes, número ajeno y archivo `.call` en laboratorio. No modificar automáticamente.

## MB8-BE-004 · Resultado de CampaignOptimizer (P1)

- **[CODIGO]** `protected/commands/CampaignOptimizerCommand.php` registra `FAILED` ante excepción y termina con retorno `0`.
- **[PENDIENTE]** contrato del job runner, inyección de fallo controlado y prueba de exit code/alerta.

## MB8-OPS-005 · Divergencia de ramas y updater (P0 para deploy)

- **[CODIGO]** `protected/commands/update.sh` obtiene `main` y hace `git reset --hard origin/main`.
- **[OBSERVADO GITHUB]** `development` contiene trabajo propio; no asumir sincronía con `main` ni con el servidor.
- **[PENDIENTE]** política de release aprobada, rama/commit real instalado y reversión probada.

P0 = alto impacto potencial en datos/llamadas/release; P1 = impacto visual/funcional notable. Priorización no significa que el defecto esté confirmado. Cada ticket debe partir de [WORKFLOW.md](WORKFLOW.md).
