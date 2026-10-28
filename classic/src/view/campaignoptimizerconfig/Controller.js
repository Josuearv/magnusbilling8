/**
 * Controller definition for Campaign Optimizer
 *
 * MagnusBilling 8 - Fork Josuearv
 */
Ext.define('MBilling.view.campaignoptimizerconfig.Controller', {
    extend: 'Ext.ux.app.ViewController',
    alias: 'controller.campaignoptimizerconfig',

    onRunNow: function () {
        var me = this;
        Ext.Msg.confirm(
            t('Ejecución Inmediata'),
            t('¿Desea ejecutar el ciclo completo de optimización ahora (horarios, audios, reconciliación)?'),
            function (ans) {
                if (ans === 'yes') {
                    Ext.getBody().mask(t('Ejecutando optimizador...'));
                    Ext.Ajax.request({
                        url: 'index.php/campaignoptimizerconfig/runNow',
                        method: 'POST',
                        timeout: 120000,
                        success: function (res) {
                            Ext.getBody().unmask();
                            var json = Ext.decode(res.responseText, true);
                            if (json && json.success) {
                                var msg = '<b>Optimización completada:</b><br>' +
                                    '• Intentos conciliados: ' + (json.attempts_reconciled || 0) + '<br>' +
                                    '• Suspensiones aplicadas: ' + (json.suspensions_applied || 0) + '<br>' +
                                    '• Horarios actualizados: ' + (json.schedules_updated || 0) + '<br>' +
                                    '• Audios rotados: ' + (json.audios_rotated || 0);
                                Ext.Msg.alert(t('Éxito'), msg);
                                if (me.store) me.store.load();
                            } else {
                                Ext.Msg.alert(t('Error'), (json && json.msg) || t('Error al ejecutar optimizador'));
                            }
                        },
                        failure: function () {
                            Ext.getBody().unmask();
                            Ext.Msg.alert(t('Error'), t('Error de comunicación con el servidor'));
                        }
                    });
                }
            }
        );
    },

    onRotateAudioNow: function () {
        var me = this;
        Ext.getBody().mask(t('Rotando audio...'));
        Ext.Ajax.request({
            url: 'index.php/campaignoptimizerconfig/rotateAudioNow',
            method: 'POST',
            timeout: 60000,
            success: function (res) {
                Ext.getBody().unmask();
                var json = Ext.decode(res.responseText, true);
                if (json && json.success) {
                    var msg = '<b>Rotación de audio completada:</b><br>' +
                        'Versión activa: ' + (json.version_tag || 'N/A') + '<br>' +
                        'Archivo: ' + (json.file_path || 'N/A');
                    Ext.Msg.alert(t('Éxito'), msg);
                } else {
                    Ext.Msg.alert(t('Aviso'), (json && json.msg) || t('No se pudo rotar el audio'));
                }
            },
            failure: function () {
                Ext.getBody().unmask();
                Ext.Msg.alert(t('Error'), t('Error de comunicación con el servidor'));
            }
        });
    },

    onOpenFailedAnalytics: function () {
        Ext.widget('failedanalyticswindow').show();
    },

    onOpenSuspensions: function () {
        Ext.widget('campaignoptimizerstatewindow').show();
    },

    onOpenAudios: function () {
        Ext.widget('campaignoptimizeraudiowindow').show();
    },

    onOpenLogs: function () {
        Ext.widget('campaignoptimizerlogwindow').show();
    }
});
