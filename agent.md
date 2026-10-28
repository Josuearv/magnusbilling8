# Guía del Agente y Manual de Desarrollo - MagnusBilling 8 (Fork Josuearv)

Este documento es el **manual de referencia técnica, arquitectura y flujo de trabajo** para agentes de IA y desarrolladores que implementen nuevas funcionalidades, resuelvan incidencias o extiendan el sistema **MagnusBilling 8** en este repositorio.

---

## 1. Identidad y Contexto del Proyecto

* **Repositorio Remoto**: `https://github.com/Josuearv/magnusbilling8`
* **Propietario / Autor del Fork**: Josuearv
* **Stack Tecnológico**:
  * **Lenguaje Backend**: PHP 8.1 / 8.2 / 8.3
  * **Framework Web MVC**: Yii Framework 1.1 (ubicado en `protected/`)
  * **Frontend**: Sencha ExtJS 6 Classic Toolkit (ubicado en `app/` y `classic/`)
  * **Base de Datos**: MariaDB 10.x / MySQL 8.0 (motor InnoDB con transacciones ACID)
  * **Motor VoIP**: Asterisk 20 LTS con **PJSIP** (`chan_pjsip`)
  * **Servidor Web**: Apache 2.4 con `mod_php` o `php-fpm`

---

## 2. Estrategia y Flujo de Ramas (Git Workflow)

El repositorio mantiene una política rigurosa de 3 ramas:

```mermaid
gitGraph
   commit id: "upstream/source"
   branch development
   checkout development
   commit id: "custom-modules"
   commit id: "massivecall-hook"
   branch main
   checkout main
   commit id: "v8-production"
   checkout development
   commit id: "feature-en-desarrollo"
```

| Rama | Propósito | Reglas de Obligatorio Cumplimiento |
| :--- | :--- | :--- |
| **`development`** | **Rama de desarrollo activa (Default)** | **TODO agente debe trabajar aquí.** Aquí se crean módulos, se modifican controladores, vistas, scripts y se prueba el código. |
| **`main`** | **Rama de despliegue en producción** | Utilizada por `script/install.sh` y `update.sh` en los servidores en vivo. **Solo recibe merges desde `development`** una vez que los cambios han sido probados. |
| **`source`** | **Espejo limpio del upstream oficial** | **NUNCA modificar archivos directamente en `source`.** Esta rama solo se actualiza vía `Sync fork` (desde `magnussolution/magnusbilling:source`) para inspeccionar parches oficiales sin conflictos. |

### Convención de Commits:
Utilizar formato [Conventional Commits](https://www.conventionalcommits.org/):
* `feat: <descripción>` -> Nueva funcionalidad o módulo.
* `fix: <descripción>` -> Corrección de error o bug.
* `refactor: <descripción>` -> Reorganización de código sin alterar comportamiento.
* `docs: <descripción>` -> Documentación o comentarios.
* `chore: <descripción>` -> Tareas de mantenimiento, dependencias o configuración.

---

## 3. Mapa del Repositorio (Dónde buscar cada componente)

```text
magnusbilling8/
├── agent.md                        # ESTE DOCUMENTO (Guía maestra del agente)
├── ARCHITECTURE.md                 # Arquitectura de alto nivel del sistema
├── PROJECT.md                      # Alcance del producto y prioridades de ingeniería
├── CALL_FAILED_DIAGNOSTIC_CORRELATION.md # Diagnóstico de fallos CDR y uniqueid
├── ia-docs/                        # Base de conocimiento nativa para Agentes e IA
│   ├── domains/                    # Lógica técnica por dominio (billing, massive calls, PJSIP)
│   ├── playbooks/                  # Guías de solución y creación (create_new_module)
│   └── sources/                    # Inventario de tablas de BD y catálogo de módulos
├── cron.php                        # Punto de entrada para comandos CLI y cron
├── index.php                       # Punto de entrada web (Yii Framework)
├── app/                            # Frontend: Modelos y Stores globales ExtJS
│   ├── model/                      # Ext.data.Model (definición de campos y proxys)
│   └── store/                      # Ext.data.Store (colecciones de datos para vistas)
├── classic/                        # Frontend: Vistas y Componentes UI Classic
│   └── src/view/                   # Vistas por módulo (Form.js, List.js, Module.js, Controller.js)
├── protected/                      # Backend Yii 1.1 MVC
│   ├── commands/                   # Comandos de consola y Cron (CLI)
│   │   ├── MassiveCallCommand.php  # Comando de llamadas masivas
│   │   ├── MassiveCallBeforeDial.php # Hook personalizado de rotación de CallerID
│   │   ├── UpdateMysqlCommand.php  # Migrador incremental de base de datos
│   │   └── update.sh               # Script de auto-actualización Git
│   ├── components/                 # Helpers del sistema (AsteriskAccess, Util, Mail)
│   ├── config/                     # Configuración Yii (main.php, cron.php)
│   ├── controllers/                # Controladores HTTP / AJAX para ExtJS
│   ├── models/                     # Modelos de datos ActiveRecord (Yii)
│   └── runtime/                    # Logs de aplicación (application.log)
├── resources/                      # Assets estáticos y telefonía
│   ├── asterisk/                   # Scripts AGI Asterisk (mbilling.php)
│   ├── help/                       # Tooltips de ayuda en interfaz por idioma
│   └── locale/                     # Archivos de traducción i18n
├── script/                         # Despliegue y configuración de servidor
│   ├── database.sql                # Esquema inicial para instalaciones limpias
│   └── install.sh                  # Instalador bash optimizado para VPS
└── lib/                            # Integraciones externas (pasarelas de pago, auth)
```

### Base de Conocimiento y Documentación Integrada

El repositorio cuenta con documentación técnica preexistente de altísimo valor que los agentes deben consultar antes de reinventar la rueda:

1. **`ARCHITECTURE.md`**: Define la arquitectura global, límites de confianza (Asterisk AMI/AGI vs PHP vs Apache), supuestos de despliegue y áreas de alto riesgo (facturación, esquemas de BD, PJSIP).
2. **`PROJECT.md`**: Define el alcance oficial de MagnusBilling 8, la línea base en Asterisk 20 PJSIP, y prioridades de ingeniería (corrección en facturación > estabilidad telefónica > seguridad > mantenibilidad).
3. **`CALL_FAILED_DIAGNOSTIC_CORRELATION.md`**: Manual técnico exhaustivo que explica la correlación de llamadas fallidas entre MariaDB (`pkg_cdr_failed`), Asterisk 20 y `uniqueid`.
4. **`ia-docs/` (Base de Conocimiento RAG para IA)**:
   * **`ia-docs/domains/`**: Desglosa las reglas de negocio por subsistema:
     * `campaigns_and_massive_calls.md`: Explicación del motor de llamadas masivas y spooler.
     * `call_flow_and_billing.md`: Lógica de AGI, cobro en tiempo real y cálculo de CDR.
     * `mbilling8_platform_changes.md`: Inventario de cambios y diferencias entre Magnus 7 y 8.
     * `rates_and_provider_costs.md`: Algoritmos de cálculo de rutas y costos.
     * `sip_and_iax_accounts.md`: Gestión de cuentas SIP y PJSIP endpoints.
     * `payments_and_refill.md`: Pasarelas de pago y recargas.
   * **`ia-docs/playbooks/`**: Guías de acción técnica:
     * `create_new_module_pkg_example.md`: Plantilla de referencia para nuevos módulos `pkg_*`.
     * `troubleshooting_flows.md`: Árboles de decisión para resolución de problemas.
     * `known_issues_fix_patterns.md`: Patrones de parches probados.
   * **`ia-docs/sources/`**:
     * `database_table_index.md`: Índice y descripción de todas las tablas `pkg_*`.
     * `module_catalog.md`: Catálogo de todos los módulos instalados.

---

## 4. Ciclo de Vida para Crear una Nueva Feature (Paso a Paso)

Cuando un agente recibe la tarea de implementar un nuevo módulo o funcionalidad (por ejemplo, una nueva entidad `X`), debe seguir estrictamente este flujo secuencial:

### Paso 1: Base de Datos (`MariaDB` / `MySQL`)
1. **Convención de Nombres**: Toda tabla debe iniciar con el prefijo `pkg_` en minúsculas (ej: `pkg_modulo_nuevo`).
2. **Campos Estándar**:
   * `id`: Clave primaria entera `INT NOT NULL AUTO_INCREMENT PRIMARY KEY`.
   * Claves foráneas: Con prefijo `id_` (ej: `id_user INT NOT NULL`).
   * Estado activo/inactivo: `activated TINYINT(1) NOT NULL DEFAULT 1`.
3. **Migración en Código**:
   * Registrar la creación de la tabla en `protected/commands/UpdateMysqlCommand.php` verificando existencia previa:
     ```php
     if (!$this->tableExists('pkg_modulo_nuevo')) {
         $this->executeDB("CREATE TABLE `pkg_modulo_nuevo` (
             `id` INT NOT NULL AUTO_INCREMENT,
             `id_user` INT NOT NULL,
             `nombre` VARCHAR(100) NOT NULL,
             `activated` TINYINT(1) NOT NULL DEFAULT 1,
             PRIMARY KEY (`id`),
             KEY `id_user` (`id_user`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
     }
     ```
   * Agregar también el DDL a `script/database.sql` para que las instalaciones nuevas desde cero incluyan la tabla.

---

### Paso 2: Backend Yii 1.1 (Modelo y Controlador)

#### A. Modelo: `protected/models/ModuloNuevo.php`
Hereda de la clase base `Model` (`protected/components/Model.php`):
```php
<?php
class ModuloNuevo extends Model
{
    protected $_module = 'modulonuevo';

    public static function model($className = __CLASS__)
    {
        return parent::model($className);
    }

    public function tableName()
    {
        return 'pkg_modulo_nuevo';
    }

    public function primaryKey()
    {
        return 'id';
    }

    public function rules()
    {
        return [
            ['id_user, nombre', 'required'],
            ['id_user', 'numerical', 'integerOnly' => true],
            ['nombre', 'length', 'max' => 100],
            ['activated', 'boolean'],
        ];
    }

    public function relations()
    {
        return [
            'idUser' => [self::BELONGS_TO, 'User', 'id_user'],
        ];
    }
}
```

#### B. Controlador: `protected/controllers/ModulonuevoController.php`
Hereda de la clase base `Controller` (`protected/components/Controller.php`). Proporciona automáticamente las acciones CRUD (`actionRead`, `actionCreate`, `actionUpdate`, `actionDestroy`, `actionCsv`, `actionPdf`):
```php
<?php
class ModulonuevoController extends Controller
{
    public $attributeOrder = 't.id';
    public $extraValues    = ['idUser' => 'username'];

    public function init()
    {
        $this->instanceModel = new ModuloNuevo;
        $this->abstractModel = ModuloNuevo::model();
        $this->titleReport   = Yii::t('zii', 'Modulo Nuevo');
        parent::init();
    }
}
```

---

### Paso 3: Registro en el Menú y Permisos (ACL)

Para que el módulo sea visible y accesible en la interfaz web de MagnusBilling, se debe registrar en dos tablas:

1. **`pkg_module`** (Registro del menú):
   * Insertar el módulo indicando el menú padre (`id_module`), ícono FontAwesome (`iconCls`), y orden:
     * Menús padres comunes: `1` = Clientes, `7` = Facturación, `8` = Tarifas, `9` = Reportes, `10` = Rutas, `12` = Configuración, `13` = Marcación Masiva, `85` = Servicios.
     ```sql
     INSERT INTO `pkg_module` (`id`, `text`, `module`, `iconCls`, `id_module`, `order`) 
     VALUES (150, 't(\'Modulo Nuevo\')', 'modulonuevo', 'x-fa fa-cogs', 12, 10);
     ```
2. **`pkg_group_module`** (Permisos de rol):
   * Otorgar permisos a los grupos deseados:
     * Grupo `1` (Administrador): Permiso total `'crud'`.
     * Grupo `2` (Agente/Revendedor): Según requerimiento (ej. `'crud'` o `'r'`).
     * Grupo `3` (Cliente): Según requerimiento.
     ```sql
     INSERT INTO `pkg_group_module` (`id_group`, `id_module`, `action`, `show_menu`, `createShortCut`, `createQuickStart`) 
     VALUES (1, 150, 'crud', 1, 0, 0);
     ```

---

### Paso 4: Frontend Sencha ExtJS 6 Classic

El frontend requiere 4 archivos en la vista y 2 en el núcleo de la aplicación:

1. **Modelo de datos ExtJS**: `app/model/ModuloNuevo.js`
   ```javascript
   Ext.define('MBilling.model.ModuloNuevo', {
       extend: 'Ext.data.Model',
       fields: [
           { name: 'id', type: 'int' },
           { name: 'id_user', type: 'int' },
           { name: 'nombre', type: 'string' },
           { name: 'activated', type: 'int' },
           'idUserusername'
       ],
       proxy: {
           type: 'uxproxy',
           module: 'modulonuevo'
       }
   });
   ```

2. **Store de datos ExtJS**: `app/store/ModuloNuevo.js`
   ```javascript
   Ext.define('MBilling.store.ModuloNuevo', {
       extend: 'Ext.data.Store',
       model: 'MBilling.model.ModuloNuevo'
   });
   ```

3. **Vistas del Módulo**: `classic/src/view/modulonuevo/`
   * **`Module.js`**: Envoltorio principal del panel:
     ```javascript
     Ext.define('MBilling.view.modulonuevo.Module', {
         extend: 'Ext.ux.panel.Module',
         alias: 'widget.modulonuevomodule',
         controller: 'modulonuevo'
     });
     ```
   * **`Controller.js`**: Controlador de interfaz:
     ```javascript
     Ext.define('MBilling.view.modulonuevo.Controller', {
         extend: 'Ext.ux.app.ViewController',
         alias: 'controller.modulonuevo'
     });
     ```
   * **`List.js`**: Grid con tabla de datos, filtros y columnas:
     ```javascript
     Ext.define('MBilling.view.modulonuevo.List', {
         extend: 'Ext.ux.grid.Panel',
         alias: 'widget.modulonuevolist',
         initComponent: function() {
             var me = this;
             me.columns = [
                 { text: t('ID'), dataIndex: 'id', width: 60 },
                 { text: t('Username'), dataIndex: 'idUserusername', flex: 1 },
                 { text: t('Name'), dataIndex: 'nombre', flex: 2 },
                 { text: t('Status'), dataIndex: 'activated', renderer: Helper.rendererBoolean }
             ];
             me.callParent(arguments);
         }
     });
     ```
   * **`Form.js`**: Ventana modal con campos para crear y editar registros:
     ```javascript
     Ext.define('MBilling.view.modulonuevo.Form', {
         extend: 'Ext.ux.form.Panel',
         alias: 'widget.modulonuevoform',
         initComponent: function() {
             var me = this;
             me.items = [
                 { xtype: 'userlookup', name: 'id_user', fieldLabel: t('Username') },
                 { name: 'nombre', fieldLabel: t('Name'), allowBlank: false },
                 { xtype: 'booleancombo', name: 'activated', fieldLabel: t('Status') }
             ];
             me.callParent(arguments);
         }
     });
     ```

---

### Paso 5: Tareas Programadas y Comandos CLI (Cron)

Si la funcionalidad requiere ejecución periódica o en segundo plano:
1. **Crear Comando**: `protected/commands/MiTareaCommand.php`
   ```php
   <?php
   class MiTareaCommand extends CConsoleCommand
   {
       public function run($args)
       {
           // Lógica de procesamiento
       }
   }
   ```
2. **Invocación desde el Cron**:
   ```bash
   php /var/www/html/mbilling/cron.php MiTarea
   ```

---

### Paso 6: Telefonía, Asterisk 20 y PJSIP

Para cualquier desarrollo que toque llamadas, troncales o numeración:

1. **Canal Saliente Obligatorio (PJSIP)**:
   ```php
   // CORRECTO en Asterisk 20:
   $dialstr = "PJSIP/$destination@$trunkcode";

   // INCORRECTO (Legacy Asterisk 13/16 chan_sip - NO USAR):
   // $dialstr = "SIP/$trunkcode/$destination";
   ```
2. **Archivos de Llamada (`.call`)**:
   * Usar siempre el generador atómico:
     ```php
     $callFile = AsteriskAccess::buildCallFile($directives, $variables);
     AsteriskAccess::generateCallFile($callFile);
     ```
   * Esto escribe en `/var/spool/asterisk/outgoing/.magnusbilling-tmp/` y mueve atómicamente el archivo a `/var/spool/asterisk/outgoing/` para evitar que Asterisk lea archivos a medio escribir.
3. **Hook MassiveCall (`MassiveCallBeforeDial.php`)**:
   * Toda lógica previa a la salida de una llamada masiva debe integrarse en este archivo.
   * La rotación de Caller ID se realiza seleccionando números activos con `ORDER BY RAND() LIMIT 1` sobre la tabla `pkg_callerid`.

---

## 5. Protocolo de Seguridad y Buenas Prácticas

1. **Prevención de Inyecciones SQL (PDO Obligatorio)**:
   * **NUNCA** concatenar variables en cadenas SQL.
   * Usar siempre consultas parametrizadas con `Yii::app()->db->createCommand()`:
     ```php
     $sql = "SELECT * FROM pkg_user WHERE username = :user AND activated = :act";
     $user = Yii::app()->db->createCommand($sql)
         ->bindValue(':user', $username, PDO::PARAM_STR)
         ->bindValue(':act', 1, PDO::PARAM_INT)
         ->queryRow();
     ```
2. **Saltos de Línea LF (Unix)**:
   * Todos los archivos de script (`.sh`, scripts bash) deben guardarse estrictamente con saltos de línea **LF (`\n`)**. NUNCA usar CRLF (`\r\n`), para prevenir el error `\r: command not found` en Linux.
3. **Compatibilidad PHP 8.x**:
   * Declarar propiedades de clase explícitamente (evitar propiedades dinámicas obsoletas en PHP 8.2+).
   * Validar nulos antes de acceder a índices (`$array['key'] ?? null`).
   * No usar funciones eliminadas como `each()` o `create_function()`.

---

## 6. Flujos de Diagnóstico y Depuración (Troubleshooting)

Cuando se presente un fallo o error en el sistema, consultar los logs en el siguiente orden de prioridad:

| Componente | Archivo de Log / Comando de Inspección | Qué buscar |
| :--- | :--- | :--- |
| **Backend Yii** | `/var/www/html/mbilling/protected/runtime/application.log` | Excepciones PHP, errores de SQL, stack traces de controllers. |
| **Asterisk CLI** | `asterisk -rvvvvv` | Fallos de marcado PJSIP, rechazos SIP (403, 404, 503), ejecución de AGI. |
| **Asterisk Log** | `/var/log/asterisk/full` | Registro histórico detallado de llamadas y CDRs. |
| **PJSIP Endpoints** | En Asterisk CLI: `pjsip show endpoints` / `pjsip show aors` | Registro y disponibilidad de troncales y extensiones. |
| **Servidor Web** | `/var/log/apache2/error.log` | Errores 500, bloqueos de `.htaccess`, fallos de PHP-FPM. |
| **Crons y Tareas** | `/var/log/syslog` o `journalctl -u cron -f` | Ejecución y salida de `cron.php MassiveCall` o tareas programadas. |

---

## 7. Checklist Obligatorio del Agente antes de Finalizar una Tarea

Antes de entregar cualquier cambio al usuario o dar por concluida una tarea, el agente debe verificar cada uno de estos 7 puntos:

- [ ] **1. Rama Correcta**: El cambio está realizado y comiteado en la rama **`development`** (NUNCA en `source`, NUNCA directamente en `main`).
- [ ] **2. Sintaxis PHP**: Todo archivo PHP creado o modificado pasa la validación de sintaxis: `php -l <archivo.php>`.
- [ ] **3. Saltos de Línea**: Todos los archivos `.sh` tienen terminación **LF** sin caracteres de retorno de carro (`\r`).
- [ ] **4. Seguridad SQL**: Todas las consultas a base de datos utilizan binding de parámetros PDO (`:param`).
- [ ] **5. Compatibilidad Asterisk**: No se utilizan cadenas obsoletas `SIP/`; todo canal utiliza `PJSIP/<dest>@<trunk>`.
- [ ] **6. Integridad de Migración**: Si se agregaron campos o tablas, están documentados y contemplados en `UpdateMysqlCommand.php` y `script/database.sql`.
- [ ] **7. Push Remoto**: Los commits han sido subidos exitosamente a `origin development` en GitHub.
