# Playbook frontend — Ext JS 6 Classic, CSS y UX

Aplica al fork: `classic/src/view/`, `classic/src/ux/`, `classic/src/overrides/`, `app/model/`, `app/store/`, `resources/init.css`, `resources/MBilling-all.css`, `sass/`, manifiestos y bundle.

## Diagnóstico obligatorio

1. Reproducir en pantalla **autenticada**; anotar SHA, navegador, ancho/alto, zoom, idioma, tema y rol.
2. Revisar Network (JS, CSS, manifest, fuentes, imágenes, sprite y caché), consola y respuesta de servicios. Una respuesta 200 no implica un recurso correcto.
3. Inspeccionar DevTools: `Computed` y reglas ganadoras, cajas, `overflow`, dimensiones, margin/padding, focus/disabled/hover.
4. Identificar componente Ext JS propietario, `xtype`, `layout`, `anchor`, `flex`, `labelWidth`, `labelAlign`, `bodyPadding` y momento del render.
5. Descartar conflictos de temas, recursos faltantes y empaquetado antes de manipular estilos.

## Reglas de implementación

- Evitar `display:flex !important`, `width:100% !important`, `height`, `position` o `overflow` globales sobre los elementos internos de Ext JS: el framework calcula su geometría.
- No sumar otra cascada global de `!important` para solucionar un componente. Crear una clase propia `mb-*` acotada y retirar reglas en conflicto cuando se haya demostrado la causa.
- Separar tokens visuales (color, typography, radius y spacing) de cálculos de layout.
- Revisar `hover`, `focus`, `disabled`, `invalid`, `checked`, `selected` y estado vacío.
- No editar librerías bajo `ext/` ni hojas generadas directamente salvo causa justificada y estrategia de regeneración.
- Mantener la semántica de `Ext.selection.CheckboxModel`; no reemplazar con un checkbox HTML sin validar selección y eventos.

## Rutas de diagnóstico

| Síntoma | Inspeccionar |
| --- | --- |
| Checkbox desalineado | `classic/src/ux/grid/Panel.js`, selección, encabezado grid, CSS/sprite de tema |
| CallerID/Form | `classic/src/view/callerid/Form.js` y `List.js`, `classic/src/ux/form/Panel.js` |
| Lookup e inputs | `classic/src/ux/form/field/Lookup.js`, `classic/src/overrides/form/field/` |
| Toolbar/grids | `classic/src/ux/grid/Panel.js`, `resources/init.css`, estilos del tema |
| Loader y assets | `index.html`, `app.js`, `script/build-frontend.mjs`, `script/frontend-sources.json` y [frontend_boot.md](../../ia-docs/playbooks/frontend_boot.md) |

## Matriz mínima de visual/funcional

- Desktop 1366×768, 1440×900 y 1920×1080; verificar también tamaños menores si se soportan.
- Administrador vs usuario cliente; tema actual y al menos otro tema si el CSS se comparte.
- Grid sin filas y poblado; selección, varias filas, filtros, sort, paginación, CSV, importación.
- Formulario crear/editar/cancelar/guardar, errores, lookup, teclado/tab/foco, modales, botones visibles.
- Captura antes/después con datos anonimizados; consola y Network sin fallos relevantes; no clipping, scroll inesperado, overlap ni textos cortados.

## Bundle y límites de comprobación

```sh
node script/build-frontend.mjs
node script/build-frontend.mjs --check
node script/test-frontend-boot.mjs
```

`build-frontend.mjs` **modifica** bundle/manifiestos: revisar diff y solo ejecutarlo si la tarea lo requiere. El último script prueba **boot simulado**, no apariencia, sesión real ni CRUD. Si no hay staging y navegador, señalar `NOT RUN`. Consultar [QA_EVIDENCE.md](QA_EVIDENCE.md).
