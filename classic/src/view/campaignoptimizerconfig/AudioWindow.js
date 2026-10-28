/**
 * Modal Window for Campaign Discovered Audio Versions
 *
 * MagnusBilling 8 - Fork Josuearv
 */
Ext.define('MBilling.view.campaignoptimizerconfig.AudioWindow', {
    extend: 'Ext.window.Window',
    alias: 'widget.campaignoptimizeraudiowindow',
    title: t('Versiones de Audio Descubiertas y Validadas (SHA-256)'),
    width: '85%',
    height: '75%',
    modal: true,
    layout: 'fit',
    maximizable: true,
    closeAction: 'destroy',

    initComponent: function () {
        var me = this;

        var store = Ext.create('MBilling.store.CampaignOptimizerAudio');

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
                    { header: t('Versión / Tag'), dataIndex: 'version_tag', width: 140, sortable: true },
                    { header: t('Ruta de Archivo'), dataIndex: 'file_path', flex: 2, sortable: true },
                    { header: t('Formato'), dataIndex: 'format', width: 80, align: 'center', sortable: true },
                    { header: t('Tamaño (Bytes)'), dataIndex: 'file_size_bytes', width: 110, align: 'right', sortable: true },
                    { header: t('SHA-256 Checksum'), dataIndex: 'sha256_checksum', width: 220, sortable: true },
                    {
                        header: t('Activo'),
                        dataIndex: 'is_active',
                        width: 90,
                        align: 'center',
                        sortable: true,
                        renderer: function (val) {
                            if (val == 1) {
                                return '<span style="color:#059669; font-weight:bold;"><i class="x-fa fa-check-circle"></i> SÍ</span>';
                            }
                            return '<span style="color:#9ca3af;">NO</span>';
                        }
                    },
                    { header: t('Validado En'), dataIndex: 'validated_at', width: 150, sortable: true }
                ]
            })
        ];

        me.callParent(arguments);

        me.on('show', function () {
            store.load();
        });
    }
});
