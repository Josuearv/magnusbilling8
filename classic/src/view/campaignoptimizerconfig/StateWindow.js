/**
 * Modal Window for Campaign Active Suspensions & States
 *
 * MagnusBilling 8 - Fork Josuearv
 */
Ext.define('MBilling.view.campaignoptimizerconfig.StateWindow', {
    extend: 'Ext.window.Window',
    alias: 'widget.campaignoptimizerstatewindow',
    title: t('Suspensiones Activas y Restricciones de Campaña'),
    width: '85%',
    height: '80%',
    modal: true,
    layout: 'fit',
    maximizable: true,
    closeAction: 'destroy',

    initComponent: function () {
        var me = this;

        var store = Ext.create('MBilling.store.CampaignOptimizerState');

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
                    { header: t('Campaña'), dataIndex: 'idCampaignname', width: 150, sortable: true },
                    { header: t('Teléfono'), dataIndex: 'canonical_number', width: 140, sortable: true },
                    {
                        header: t('Estado'),
                        dataIndex: 'state',
                        width: 130,
                        sortable: true,
                        renderer: function (val) {
                            if (val === 'SUSPENDED_TEMP') {
                                return '<span style="color:#d97706; font-weight:bold;"><i class="x-fa fa-clock-o"></i> SUSPENDIDO</span>';
                            } else if (val === 'RESTRICTED_PERM') {
                                return '<span style="color:#dc2626; font-weight:bold;"><i class="x-fa fa-ban"></i> PERMANENTE</span>';
                            }
                            return '<span style="color:#059669; font-weight:bold;">ACTIVO</span>';
                        }
                    },
                    { header: t('Motivo'), dataIndex: 'reason', flex: 2, sortable: true },
                    { header: t('Suspendido Hasta'), dataIndex: 'suspended_until', width: 150, sortable: true },
                    {
                        header: t('Permanente'),
                        dataIndex: 'is_permanent',
                        width: 100,
                        renderer: Helper.Util.formatBooleanActive,
                        align: 'center'
                    },
                    { header: t('Aplicado Por'), dataIndex: 'applied_by', width: 120, sortable: true },
                    { header: t('Fecha Aplicación'), dataIndex: 'applied_at', width: 150, sortable: true }
                ]
            })
        ];

        me.callParent(arguments);

        me.on('show', function () {
            store.load();
        });
    }
});
