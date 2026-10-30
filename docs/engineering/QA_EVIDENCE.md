# QA senior: pruebas reales, no pruebas fantasma

Cada caso debe registrar: ID, rama/SHA, entorno, condiciones previas, datos ficticios, comando/pasos exactos, esperado, obtenido, artefacto (captura/log/HTTP anonimizado) y resultado.

## Estados exactos

- `PASS` = prueba realmente ejecutada y resultado verificado con evidencia.
- `FAIL` = prueba ejecutada y resultado distinto al esperado, con reproducción.
- `NOT RUN` = no ejecutada, con razón.
- `BLOCKED` = imposible por falta de credenciales de staging, servidor de pruebas, toolchain, etc.

Nunca presentar diseño de tests, revisión estática, mock, login público o sintaxis correcta como E2E/QA aprobado.

## Niveles y límites

| Nivel | Demuestra | No demuestra |
| --- | --- | --- |
| Sintaxis / static | validez superficial del código | operación real |
| Unitario | funciones en aislamiento | integración con API/DB |
| Boot mock | inicialización simulada | sesión real/UI visual |
| Integración | DB/API de prueba | todos los roles/temas |
| UI autenticada | CSS/layout/interacción del navegador | cobro ni telefonía |
| VoIP laboratorio | despacho/AGI/PJSIP/CDR bajo condiciones controladas | carriers y redes externas |
| Staging release | empaquetado y comportamiento observado allí | production sin riesgos |

## Casos mínimos por categoría

- **UI:** grid vacío/poblado, checkbox checked/unchecked, toolbar/paginación, lookup, guardado/error, focus, textos largos, zoom, dos temas si compartidos; evidencias antes/después.
- **API/ACL:** usuario sin permiso, registro ajeno, sesión caducada, CSRF, error 4xx/5xx, payload inválido, export/acciones masivas.
- **DB/billing:** fallo transaccional, idempotencia, doble intento, decimal/cambio de día, migración/rollback en clon.
- **Campañas/VoIP:** simultaneidad, Caller ID fijo/dinámico/ajeno, reintento, restricciones, archivo `.call`, respuesta de trunk, CDR y códigos de salida.
- **Operativo:** hashes de assets, caché, log sin secretos, backup, reversión ensayada si hay deploy.

## Roles separados

El implementador propone tests y ejecuta checks. QA independiente contrasta aceptación con ejecución real; AppSec revisa ACL/CSRF/inyecciones y aislamiento. Para riesgos altos/críticos no aprobar si los casos decisivos están `NOT RUN` o `BLOCKED`.

## Reporte de QA

```text
Rama / SHA / fecha / entorno:
Caso ID | pasos exactos | esperado | obtenido | evidencia | estado
Defectos reproducidos:
Regresiones:
Cobertura no ejecutada y motivos:
Riesgos residuales:
Veredicto: APPROVE / CHANGES REQUIRED / BLOCKED
```

Una captura no prueba causa raíz ni que CRUD funcione; un comando de build no prueba la UI. Se debe reportar exactamente qué se hizo.
