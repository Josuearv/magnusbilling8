/**
 * Modal Window for Campaign Optimizer Execution Logs
 *
 * MagnusBilling 8 - Fork Josuearv
 */
Ext.define('MBilling.view.campaignoptimizerconfig.LogWindow', {
    extend: 'Ext.window.Window',
    alias: 'widget.campaignoptimizerlogwindow',
    title: t('Historial de Ejecuciones del Optimizador'),
    width: '90%',
    height: '80%',
    modal: true,
    layout: 'fit',
    maximizable: true,
    closeAction: 'destroy',

    initComponent: function () {
        var me = this;

        var store = Ext.create('MBilling.store.CampaignOptimizerLog');

        me.items = [
            Ext.create('Ext.grid.Panel', {
                store: store,
                tbar: [
                    {
                        text: t('Recargar'),
                        iconCls: 'x-fa fa-refresh',
                        handler: function () {
                            store.load();
                        }
                    }
                ],
                bbar: {
                    xtype: 'pagingtoolbar',
                    store: store,
                    displayInfo: true
                },
                columns: [
                    { header: t('ID'), dataIndex: 'id', width: 60 },
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
                    { header: t('Audios Rotados'), dataIndex: 'audios_rotated', width: 95, align: 'center', sortable: true },
                    { header: t('Detalles / Errores'), dataIndex: 'error_details', flex: 2, sortable: true }
                ]
            })
        ];

        me.callParent(arguments);

        me.on('show', function () {
            store.load();
        });
    }
});
