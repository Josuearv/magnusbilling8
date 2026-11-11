/**
 * Modal Window for Campaign Optimizer Execution Logs
 *
 * MagnusBilling 8 - Fork Josuearv
 */
Ext.define('MBilling.view.campaignoptimizerconfig.LogWindow', {
    extend: 'Ext.window.Window',
    alias: 'widget.campaignoptimizerlogwindow',
    title: t('Historial de Ejecuciones del Optimizador'),
    width: '92%',
    height: '82%',
    modal: true,
    layout: 'fit',
    maximizable: true,
    closeAction: 'destroy',

    copyTextToClipboard: function (text) {
        if (!text) {
            Ext.ux.Alert.alert(t('Aviso'), t('No hay detalle o error para copiar en esta fila.'), 'notification');
            return;
        }
        var done = function () {
            Ext.ux.Alert.alert(t('Copiado'), t('Detalle copiado al portapapeles.'), 'success');
        };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done).catch(function () {
                var ta = document.createElement('textarea');
                ta.value = text;
                ta.style.position = 'fixed';
                ta.style.left = '-9999px';
                document.body.appendChild(ta);
                ta.focus();
                ta.select();
                try { document.execCommand('copy'); } catch (e) {}
                document.body.removeChild(ta);
                done();
            });
        } else {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.focus();
            ta.select();
            try { document.execCommand('copy'); } catch (e) {}
            document.body.removeChild(ta);
            done();
        }
    },

    initComponent: function () {
        var me = this;

        var store = Ext.create('MBilling.store.CampaignOptimizerLog');

        var grid = Ext.create('Ext.grid.Panel', {
            store: store,
            scrollable: true,
            viewConfig: {
                enableTextSelection: true
            },
            tbar: [
                {
                    text: t('Recargar'),
                    iconCls: 'x-fa fa-refresh',
                    handler: function () {
                        store.load();
                    }
                },
                '-',
                {
                    text: t('Copiar Detalle / Error (Click en fila)'),
                    iconCls: 'x-fa fa-copy',
                    handler: function () {
                        var sel = grid.getSelectionModel().getSelection();
                        if (!sel || !sel.length) {
                            Ext.ux.Alert.alert(t('Aviso'), t('Seleccione una fila o haga clic directamente sobre el error para copiarlo.'), 'notification');
                            return;
                        }
                        var rec = sel[0];
                        var detail = rec.get('error_details') || ('ID: ' + rec.get('id') + ' | Estado: ' + rec.get('status') + ' | Inicio: ' + rec.get('start_time'));
                        me.copyTextToClipboard(detail);
                    }
                }
            ],
            bbar: {
                xtype: 'pagingtoolbar',
                store: store,
                displayInfo: true
            },
            columns: [
                { header: t('ID'), dataIndex: 'id', width: 65 },
                { header: t('Tipo'), dataIndex: 'execution_type', width: 110, sortable: true },
                { header: t('Solicitado Por'), dataIndex: 'requested_by', width: 120, sortable: true },
                {
                    header: t('Estado'),
                    dataIndex: 'status',
                    width: 110,
                    sortable: true,
                    renderer: function (val) {
                        if (val === 'COMPLETED') {
                            return '<span style="color:#059669; font-weight:bold;">COMPLETED</span>';
                        } else if (val === 'RUNNING') {
                            return '<span style="color:#3b82f6; font-weight:bold;">RUNNING</span>';
                        } else if (val === 'FAILED') {
                            return '<span style="color:#dc2626; font-weight:bold;">FAILED</span>';
                        }
                        return val;
                    }
                },
                { header: t('Inicio'), dataIndex: 'start_time', width: 150, sortable: true },
                { header: t('Duración (s)'), dataIndex: 'duration_seconds', width: 95, align: 'right', sortable: true },
                { header: t('Conciliados'), dataIndex: 'attempts_reconciled', width: 95, align: 'center', sortable: true },
                { header: t('Suspensiones'), dataIndex: 'suspensions_applied', width: 95, align: 'center', sortable: true },
                { header: t('Horarios Act.'), dataIndex: 'schedules_updated', width: 95, align: 'center', sortable: true },
                { header: t('Audios Rotados'), dataIndex: 'audios_rotated', width: 100, align: 'center', sortable: true },
                {
                    header: t('Detalles / Errores (Click para copiar)'),
                    dataIndex: 'error_details',
                    width: 950,
                    minWidth: 600,
                    sortable: true,
                    renderer: function (val, meta) {
                        if (!val) {
                            return '<span style="color:#9ca3af;">—</span>';
                        }
                        var escaped = Ext.String.htmlEncode(String(val));
                        meta.tdAttr = 'data-qtip="' + escaped.replace(/"/g, '&quot;') + '" style="cursor:pointer;"';
                        var singleLine = escaped.replace(/\r?\n/g, ' ↵ ');
                        return '<span style="color:#b91c1c; font-family:monospace;"><i class="x-fa fa-copy" style="margin-right:6px;color:#475569;"></i>' + singleLine + '</span>';
                    }
                }
            ],
            listeners: {
                cellclick: function (view, td, cellIndex, record) {
                    var detail = record.get('error_details');
                    if (detail) {
                        me.copyTextToClipboard(detail);
                    }
                }
            }
        });

        me.items = [grid];

        me.callParent(arguments);

        me.on('show', function () {
            store.load();
        });
    }
});
