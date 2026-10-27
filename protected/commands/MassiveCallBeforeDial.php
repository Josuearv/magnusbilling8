<?php
/**
 * Hook ejecutado antes de generar el archivo .call en MassiveCallCommand.
 * Asigna un Caller ID aleatorio activo de pkg_callerid si la campaña
 * está configurada como 'DYNAMIC' o tiene un Caller ID definido.
 */

if (strtoupper($campaign->callerid) == 'DYNAMIC' || strtoupper($campaign->callerid) != '') {

    // Selecciona un Caller ID aleatorio activo (compatible con valores 1, '1' o 't' de MySQL/MariaDB)
    $sql = "SELECT cid FROM pkg_callerid WHERE activated IN (1, '1', 't') ORDER BY RAND() LIMIT 1";
    $cid = Yii::app()->db->createCommand($sql)->queryScalar();

    if (!empty($cid)) {
        $campaign->callerid = $cid;
    } else {
        if (isset($this->debug) && $this->debug >= 1) {
            echo "MassiveCallBeforeDial: No active Caller ID found in pkg_callerid\n";
        }
    }
}
