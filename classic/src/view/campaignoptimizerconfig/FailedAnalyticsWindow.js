/**
 * Modal Window for Campaign Failed Analytics & Actions
 *
 * MagnusBilling 8 - Fork Josuearv
 */
Ext.define('MBilling.view.campaignoptimizerconfig.FailedAnalyticsWindow', {
    extend: 'Ext.window.Window',
    alias: 'widget.failedanalyticswindow',
    title: t('Analítica de Fallos y Gestión de Acciones'),
    width: '92%',
    height: '88%',
    modal: true,
    layout: 'border',
    maximizable: true,
    closeAction: 'destroy',

    initComponent: function () {
        var me = this;

        var store = Ext.create('MBilling.store.CampaignFailedAnalytics');

        var kpiPanel = Ext.create('Ext.panel.Panel', {
            region: 'north',
            height: 75,
            bodyPadding: '8 15',
            layout: {
                type: 'hbox',
                align: 'middle'
            },
            style: 'background-color: #f8fafc; border-bottom: 1px solid #e2e8f0;',
            items: [
                {
                    xtype: 'component',
                    flex: 1,
                    itemId: 'kpiTotalFailed',
                    html: '<div style="font-size:12px;color:#64748b;text-transform:uppercase;font-weight:600;">Fallos Totales (Hoy)</div><div style="font-size:22px;color:#ef4444;font-weight:bold;">--</div>'
                },
                {
                    xtype: 'component',
                    flex: 1,
                    itemId: 'kpiSuspended',
                    html: '<div style="font-size:12px;color:#64748b;text-transform:uppercase;font-weight:600;">Suspensiones Temporales</div><div style="font-size:22px;color:#f59e0b;font-weight:bold;">--</div>'
                },
                {
                    xtype: 'component',
                    flex: 1,
                    itemId: 'kpiRestricted',
                    html: '<div style="font-size:12px;color:#64748b;text-transform:uppercase;font-weight:600;">Restricciones Permanentes</div><div style="font-size:22px;color:#dc2626;font-weight:bold;">--</div>'
                },
                {
                    xtype: 'component',
                    flex: 1,
                    itemId: 'kpiActive',
                    html: '<div style="font-size:12px;color:#64748b;text-transform:uppercase;font-weight:600;">Contactos Afectados</div><div style="font-size:22px;color:#3b82f6;font-weight:bold;">--</div>'
                },
                {
                    xtype: 'button',
                    text: t('Actualizar KPIs'),
                    iconCls: 'x-fa fa-refresh',
                    handler: function () {
                        me.loadKpis(kpiPanel);
                        store.load();
                    }
                }
            ]
        });

        var grid = Ext.create('Ext.grid.Panel', {
            region: 'center',
            store: store,
            tbar: [
                {
                    xtype: 'textfield',
                    itemId: 'searchPhone',
                    emptyText: t('Buscar por teléfono...'),
                    width: 220,
                    listeners: {
                        specialkey: function (field, e) {
                            if (e.getKey() === e.ENTER) {
                                me.applySearch(store, field.getValue());
                            }
                        }
                    }
                },
                {
                    text: t('Buscar'),
                    iconCls: 'x-fa fa-search',
                    handler: function () {
                        var searchVal = grid.down('#searchPhone').getValue();
                        me.applySearch(store, searchVal);
                    }
                },
                {
                    text: t('Limpiar'),
                    iconCls: 'x-fa fa-eraser',
                    handler: function () {
                        grid.down('#searchPhone').setValue('');
                        store.clearFilter();
                        store.load();
                    }
                },
                '-',
                {
                    itemId: 'btnReactivate',
                    text: t('Reactivar Contacto'),
                    iconCls: 'x-fa fa-check-circle',
                    style: 'background-color: #10b981; color: white;',
                    disabled: true,
                    handler: function () {
                        var selected = grid.getSelectionModel().getSelection();
                        if (!selected || selected.length === 0) return;
                        me.doReactivate(selected[0].get('id'), function () {
                            store.load();
                            me.loadKpis(kpiPanel);
                        });
                    }
                },
                {
                    itemId: 'btnRestrict',
                    text: t('Restringir Permanente'),
                    iconCls: 'x-fa fa-ban',
                    style: 'background-color: #ef4444; color: white;',
                    disabled: true,
                    handler: function () {
                        var selected = grid.getSelectionModel().getSelection();
                        if (!selected || selected.length === 0) return;
                        me.doRestrict(selected[0].get('id'), function () {
                            store.load();
                            me.loadKpis(kpiPanel);
                        });
                    }
                }
            ],
            bbar: {
                xtype: 'pagingtoolbar',
                store: store,
                displayInfo: true
            },
            columns: [
                { header: t('ID'), dataIndex: 'id', width: 55, hidden: true },
                { header: t('Campaña'), dataIndex: 'idCampaignname', width: 140, sortable: true },
                { header: t('Teléfono'), dataIndex: 'canonical_number', width: 130, sortable: true },
                { header: t('Fecha'), dataIndex: 'date_day', width: 100, sortable: true },
                {
                    header: t('Fallos Totales'),
                    dataIndex: 'failure_count',
                    width: 95,
                    align: 'center',
                    sortable: true,
                    renderer: function (v) {
                        return '<span style="color:#b91c1c; font-weight:bold; font-size:13px;">' + v + '</span>';
                    }
                },
                { header: t('No Answer'), dataIndex: 'count_no_answer', width: 85, align: 'center', sortable: true },
                { header: t('Busy'), dataIndex: 'count_busy', width: 75, align: 'center', sortable: true },
                { header: t('Failed'), dataIndex: 'count_failed', width: 75, align: 'center', sortable: true },
                { header: t('Congestion'), dataIndex: 'count_congestion', width: 85, align: 'center', sortable: true },
                { header: t('Chan Unavail'), dataIndex: 'count_chanunavail', width: 95, align: 'center', sortable: true },
                { header: t('Último SIP'), dataIndex: 'last_sip_code', width: 80, align: 'center', sortable: true },
                { header: t('Causa Corte'), dataIndex: 'last_hangup_cause', width: 85, align: 'center', sortable: true },
                {
                    header: t('Estado Actual'),
                    dataIndex: 'current_state',
                    width: 140,
                    sortable: true,
                    renderer: function (val) {
                        if (val === 'SUSPENDED_TEMP') {
                            return '<span style="background-color:#fed7aa; color:#9a3412; padding:3px 8px; border-radius:12px; font-weight:600;"><i class="x-fa fa-clock-o"></i> SUSPENDIDO</span>';
                        } else if (val === 'RESTRICTED_PERM') {
                            return '<span style="background-color:#fecaca; color:#991b1b; padding:3px 8px; border-radius:12px; font-weight:600;"><i class="x-fa fa-ban"></i> RESTRINGIDO</span>';
                        }
                        return '<span style="background-color:#bbf7d0; color:#166534; padding:3px 8px; border-radius:12px; font-weight:600;"><i class="x-fa fa-check"></i> ACTIVO</span>';
                    }
                },
                { header: t('Suspendido Hasta'), dataIndex: 'suspended_until', width: 145, sortable: true },
                { header: t('Último Intento'), dataIndex: 'last_attempt_at', width: 145, sortable: true }
            ],
            listeners: {
                selectionchange: function (sm, selected) {
                    var hasSelection = selected && selected.length > 0;
                    grid.down('#btnReactivate').setDisabled(!hasSelection);
                    grid.down('#btnRestrict').setDisabled(!hasSelection);
                }
            }
        });

        me.items = [kpiPanel, grid];

        me.callParent(arguments);

        me.on('show', function () {
            store.load();
            me.loadKpis(kpiPanel);
        });
    },

    loadKpis: function (panel) {
        Ext.Ajax.request({
            url: 'index.php/campaignfailedanalytics/getKpis',
            method: 'GET',
            success: function (res) {
                var json = Ext.decode(res.responseText, true);
                if (json && json.success && json.kpis) {
                    panel.down('#kpiTotalFailed').update('<div style="font-size:12px;color:#64748b;text-transform:uppercase;font-weight:600;">Fallos Totales (Hoy)</div><div style="font-size:22px;color:#ef4444;font-weight:bold;">' + json.kpis.total_failures_today + '</div>');
                    panel.down('#kpiSuspended').update('<div style="font-size:12px;color:#64748b;text-transform:uppercase;font-weight:600;">Suspensiones Temporales</div><div style="font-size:22px;color:#f59e0b;font-weight:bold;">' + json.kpis.temporary_suspensions + '</div>');
                    panel.down('#kpiRestricted').update('<div style="font-size:12px;color:#64748b;text-transform:uppercase;font-weight:600;">Restricciones Permanentes</div><div style="font-size:22px;color:#dc2626;font-weight:bold;">' + json.kpis.permanent_restrictions + '</div>');
                    panel.down('#kpiActive').update('<div style="font-size:12px;color:#64748b;text-transform:uppercase;font-weight:600;">Contactos Afectados</div><div style="font-size:22px;color:#3b82f6;font-weight:bold;">' + json.kpis.distinct_contacts_affected + '</div>');
                }
            }
        });
    },

    applySearch: function (store, value) {
        if (!value || value.trim() === '') {
            store.clearFilter();
            store.load();
            return;
        }
        store.filter({
            property: 'canonical_number',
            value: value.trim(),
            anyMatch: true
        });
    },

    doReactivate: function (id, cb) {
        var me = this;
        Ext.Msg.confirm(t('Confirmación'), t('¿Desea reactivar este contacto levantando la suspensión temporal?'), function (ans) {
            if (ans === 'yes') {
                Ext.Ajax.request({
                    url: 'index.php/campaignfailedanalytics/reactivate',
                    method: 'POST',
                    params: { id: id },
                    success: function (res) {
                        var json = Ext.decode(res.responseText, true);
                        if (json && json.success) {
                            Ext.Msg.alert(t('Éxito'), json.msg || t('Contacto reactivado exitosamente'));
                            if (cb) cb();
                        } else {
                            Ext.Msg.alert(t('Error'), (json && json.msg) || t('Error al reactivar'));
                        }
                    },
                    failure: function () {
                        Ext.Msg.alert(t('Error'), t('Error de comunicación con el servidor'));
                    }
                });
            }
        });
    },

    doRestrict: function (id, cb) {
        var me = this;
        Ext.Msg.prompt(t('Restricción Permanente'), t('Motivo del bloqueo permanente (ej: No volver a llamar):'), function (btn, text) {
            if (btn === 'ok') {
                var reason = text && text.trim() ? text.trim() : 'Bloqueado manualmente desde analítica';
                Ext.Ajax.request({
                    url: 'index.php/campaignfailedanalytics/restrict',
                    method: 'POST',
                    params: { id: id, reason: reason },
                    success: function (res) {
                        var json = Ext.decode(res.responseText, true);
                        if (json && json.success) {
                            Ext.Msg.alert(t('Éxito'), json.msg || t('Contacto restringido permanentemente'));
                            if (cb) cb();
                        } else {
                            Ext.Msg.alert(t('Error'), (json && json.msg) || t('Error al restringir'));
                        }
                    },
                    failure: function () {
                        Ext.Msg.alert(t('Error'), t('Error de comunicación con el servidor'));
                    }
                });
            }
        }, me, false, 'Solicitud de exclusión / Exceso de fallos');
    }
});
