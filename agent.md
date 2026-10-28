# Guía del Agente - MagnusBilling 8 (Fork Josuearv)

Este documento sirve como manual de referencia técnica y contexto persistente para agentes de IA y desarrolladores que trabajen en el proyecto **MagnusBilling 8** personalizado por **Josuearv**.

---

## 1. Información General del Repositorio

* **Repositorio Remoto**: `https://github.com/Josuearv/magnusbilling8`
* **Propietario**: Josuearv
* **Stack Tecnológico**:
  * **PHP**: 8.1 / 8.2 / 8.3
  * **Framework Web**: Yii Framework 1.1 (MVC en `protected/`)
  * **Frontend**: ExtJS (Sencha Classic Toolkit) + Apache 2.4
  * **Base de Datos**: MariaDB 10.x / MySQL 8.0 (motor InnoDB)
  * **Motor VoIP**: Asterisk 20 LTS con **PJSIP** (`chan_pjsip`)

---

## 2. Estrategia y Flujo de Ramas (Git Workflow)

El repositorio mantiene una estrategia estricta de 3 ramas para facilitar el mantenimiento y sincronización con el proyecto oficial:

```mermaid
gitGraph
   commit id: "upstream/source"
   branch development
   checkout development
   commit id: "custom-modules"
   commit id: "massivecall-hook"
   branch main
   checkout main
   commit id: "release-v8-custom"
   checkout development
   commit id: "work-in-progress"
```

| Rama | Propósito | Reglas |
| :--- | :--- | :--- |
| **`development`** | **Rama de trabajo activa (Default de desarrollo)** | Aquí se agregan módulos, se modifican controladores/modelos y se prueban cambios. Todos los agentes deben trabajar sobre esta rama. |
| **`main`** | **Rama de producción / despliegue** | Es la rama que clonan `script/install.sh` y `update.sh` en los servidores VPS en producción. Solo recibe merges estables desde `development`. |
| **`source`** | **Espejo limpio del autor oficial** | **NUNCA** se tocan archivos directamente en `source`. Esta rama solo se actualiza mediante `Sync fork` (desde `magnussolution/magnusbilling:source`) para inspeccionar parches, novedades o fixes oficiales sin conflictos. |

### Flujo de Sincronización con Upstream:
1. En GitHub: `Sync fork` en la rama `source`.
2. En local: Traer parches o commits específicos desde `source` hacia `development`:
   ```bash
   git checkout development
   git merge source
   # O si es un fix específico:
   # git cherry-pick <COMMIT_SHA>
   ```
3. Una vez probado en `development`, mergear hacia `main`:
   ```bash
   git checkout main
   git merge development
   git push origin main
   ```

---

## 3. Arquitectura del Núcleo y MassiveCall

### A. Ejecución del Cron
El sistema corre llamadas masivas mediante el cron cada minuto:
```bash
* * * * * php /var/www/html/mbilling/cron.php MassiveCall
```
* **Punto de Entrada**: `cron.php` inicia la consola Yii con `protected/config/cron.php`.
* **Comando Principal**: `protected/commands/MassiveCallCommand.php` -> método `run()`.

### B. Hook de Marcado: `MassiveCallBeforeDial.php`
* **Ubicación**: `protected/commands/MassiveCallBeforeDial.php`.
* **Disparador**: Líneas 308-312 de `MassiveCallCommand.php`:
  ```php
  if (file_exists(dirname(__FILE__) . '/MassiveCallBeforeDial.php')) {
      include dirname(__FILE__) . '/MassiveCallBeforeDial.php';
  }
  ```
* **Propósito**: Rotación dinámica de Caller ID usando la tabla `pkg_callerid`.
* **Lógica implementada**:
  * Si `$campaign->callerid` es `'DYNAMIC'` o tiene valor asignado:
  * Ejecuta: `SELECT cid FROM pkg_callerid WHERE activated IN (1, '1', 't') ORDER BY RAND() LIMIT 1`.
  * Asigna `$campaign->callerid = $cid;`.
  * **Regla crítica**: NUNCA usar `SHOW TABLE STATUS` para contar filas en InnoDB, ya que es una estimación aproximada y causa fallos de selección (`not found`).

### C. Telefonía Asterisk 20 y PJSIP
* **Formato de Canal Saliente**: **Obligatoriamente PJSIP**:
  ```php
  $dialstr = "$providertech/$destination@$trunkcode"; // Ejemplo: PJSIP/123456789@MyTrunk
  ```
  *(NO revertir a sintaxis `SIP/trunk/destination` de Asterisk 13/16, ya que en Asterisk 20 fallará).*
* **Generación de Archivos de Llamada**:
  * Usar siempre `AsteriskAccess::buildCallFile($directives, $variables)`.
  * Escribir usando `AsteriskAccess::generateCallFile($call, $sleepNext)` (utiliza el directorio temporal atómico `.magnusbilling-tmp` para evitar lecturas truncadas del spooler de Asterisk).
* **Configuración de Troncal para Caller ID Dinámico**:
  * En la troncal PJSIP en la interfaz web de MagnusBilling, asegurarse de activar:
    * `trust_id_outbound = yes`
    * `send_pai = yes` o `send_rpid = yes`

---

## 4. Instalador y Actualizador Personalizados

### A. Instalador: `script/install.sh`
* Clona directamente el repositorio:
  ```bash
  git clone -b main https://github.com/Josuearv/magnusbilling8.git /var/www/html/mbilling
  ```
* **Protección de Apache**: La carpeta `/var/www/html/mbilling/script` y los archivos `*.sql` están bloqueados con `deny from all` en Apache. **NO borrar la carpeta `script/`**, para no ensuciar el árbol de Git.
* **Comando de instalación en VPS limpio**:
  ```bash
  curl -O https://raw.githubusercontent.com/Josuearv/magnusbilling8/main/script/install.sh
  bash install.sh
  ```

### B. Actualizador: `protected/commands/update.sh`
* Realiza un fetch y reset seguro desde Git:
  ```bash
  git fetch origin main
  git reset --hard origin/main
  bash "$UPDATE_COMMAND"
  ```
* Ejecuta migraciones de base de datos pendientes: `php /var/www/html/mbilling/cron.php UpdateMysql`.

---

## 5. Reglas de Desarrollo y Buenas Prácticas

1. **Fin de Línea (Line Endings)**:
   * **Obligatorio LF (`\n`)** en todos los scripts shell (`.sh`, scripts bash). NUNCA guardar con CRLF (`\r\n`), ya que causará errores de sintaxis en Linux (`\r: command not found`).
2. **Estructura de Nuevos Módulos**:
   * **Modelos**: Crear en `protected/models/NombreModelo.php` heredando de `Model` (o `ActiveRecord`).
   * **Controladores**: Crear en `protected/controllers/NombreModuloController.php` heredando de `Controller`.
   * **Comandos Cron**: Crear en `protected/commands/NombreCommand.php` heredando de `ConsoleCommand`.
   * **Componentes / Helpers**: Crear en `protected/components/`.
3. **Consultas a Base de Datos**:
   * Usar siempre `Yii::app()->db->createCommand(...)` con binding de parámetros para prevenir inyecciones SQL:
     ```php
     $cmd = Yii::app()->db->createCommand("SELECT * FROM pkg_user WHERE id = :id");
     $cmd->bindValue(':id', (int) $id, PDO::PARAM_INT);
     $user = $cmd->queryRow();
     ```
4. **Permisos de Archivos en Servidor**:
   * Controladores, modelos y vistas: `root:root` `0644`.
   * Scripts ejecutables en `protected/commands/*.sh`: `root:root` `0755`.
   * AGI Asterisk (`resources/asterisk/mbilling.php`): `asterisk:asterisk` `0500`.
   * Runtime / Logs (`protected/runtime/`): `www-data:www-data` `0750`.
