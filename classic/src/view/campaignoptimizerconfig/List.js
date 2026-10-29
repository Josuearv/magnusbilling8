/**
 * Grid List definition for Campaign Optimizer Configuration
 *
 * MagnusBilling 8 - Fork Josuearv
 */
Ext.define('MBilling.view.campaignoptimizerconfig.List', {
    extend: 'Ext.ux.grid.Panel',
    alias: 'widget.campaignoptimizerconfiglist',
    store: 'CampaignOptimizerConfig',
    fieldSearch: 'mode',
    initComponent: function () {
        var me = this;

        me.extraButtons = [
            {
                xtype: 'splitbutton',
                text: t('Optimizer Tools'),
                iconCls: 'x-fa fa-cogs',
                handler: 'onRunNow',
                tooltip: t('Herramientas de sincronización y rotación en vivo'),
                menu: [
                    {
                        text: t('Run Optimizer Now'),
                        iconCls: 'x-fa fa-play',
                        handler: 'onRunNow',
                        tooltip: t('Ejecuta sincronización de horarios, rotación de audio y conciliación de intentos')
                    },
                    {
                        text: t('Rotate Audio Now'),
                        iconCls: 'x-fa fa-music',
                        handler: 'onRotateAudioNow',
                        tooltip: t('Descubre directorios de audio y activa la siguiente versión')
                    }
                ]
            },
            {
                xtype: 'button',
                text: t('Analytics & Logs'),
                iconCls: 'x-fa fa-bar-chart',
                tooltip: t('Inspección de fallos, suspensiones y registros de auditoría'),
                menu: [
                    {
                        text: t('Failed Analytics & Actions'),
                        iconCls: 'x-fa fa-bar-chart',
                        handler: 'onOpenFailedAnalytics',
                        tooltip: t('Inspeccionar analítica de fallos, códigos SIP y gestionar reactivaciones')
                    },
                    {
                        text: t('Active Suspensions'),
                        iconCls: 'x-fa fa-ban',
                        handler: 'onOpenSuspensions',
                        tooltip: t('Ver números suspendidos temporalmente o bloqueados permanentemente')
                    },
                    {
                        text: t('Audio Versions'),
                        iconCls: 'x-fa fa-volume-up',
                        handler: 'onOpenAudios',
                        tooltip: t('Ver versiones de audio descubiertas y validadas con SHA-256')
                    },
                    '-',
                    {
                        text: t('Execution Logs'),
                        iconCls: 'x-fa fa-history',
                        handler: 'onOpenLogs',
                        tooltip: t('Historial de ejecuciones, duraciones y métricas del optimizador')
                    }
                ]
            }
        ];

        me.columns = [
            {
                header: t('ID'),
                dataIndex: 'id',
                width: 60,
                sortable: true
            },
            {
                header: t('Campaign'),
                dataIndex: 'idCampaignname',
                flex: 2,
                minWidth: 150,
                sortable: true
            },
            {
                header: t('Status'),
                dataIndex: 'activated',
                width: 105,
                sortable: true,
                comboRelated: 'booleancombo',
                renderer: function (val) {
                    if (val == 1 || val === true || val === '1') {
                        return '<span class="mb-badge mb-badge-success"><i class="x-fa fa-check"></i> ' + t('Active') + '</span>';
                    }
                    return '<span class="mb-badge mb-badge-danger"><i class="x-fa fa-times"></i> ' + t('Inactive') + '</span>';
                }
            },
            {
                header: t('Mode'),
                dataIndex: 'mode',
                width: 135,
                sortable: true,
                renderer: function (val) {
                    if (val === 'ENFORCING') {
                        return '<span class="mb-badge mb-badge-success"><i class="x-fa fa-shield"></i> ENFORCING</span>';
                    } else if (val === 'OBSERVATION') {
                        return '<span class="mb-badge mb-badge-warning"><i class="x-fa fa-eye"></i> OBSERVATION</span>';
                    }
                    return '<span class="mb-badge mb-badge-neutral"><i class="x-fa fa-power-off"></i> OFF</span>';
                }
            },
            {
                header: t('Max Calls/Day'),
                dataIndex: 'max_calls_daily_per_recipient',
                width: 120,
                align: 'center',
                sortable: true
            },
            {
                header: t('Fail Block Threshold'),
                dataIndex: 'min_failures_temp_block',
                width: 155,
                align: 'center',
                sortable: true
            },
            {
                header: t('Timezone'),
                dataIndex: 'timezone',
                width: 150,
                sortable: true
            },
            {
                header: t('Mon-Fri Schedule'),
                width: 140,
                sortable: false,
                renderer: function (val, meta, rec) {
                    return rec.get('schedule_weekday_start') + ' - ' + rec.get('schedule_weekday_end');
                }
            },
            {
                header: t('Weekend Schedule'),
                width: 140,
                sortable: false,
                renderer: function (val, meta, rec) {
                    return rec.get('schedule_weekend_start') + ' - ' + rec.get('schedule_weekend_end');
                }
            },
            {
                header: t('Jitter (m)'),
                dataIndex: 'jitter_range_minutes',
                width: 90,
                align: 'center',
                sortable: true
            },
            {
                header: t('Audio Directory'),
                dataIndex: 'audio_pool_directory',
                flex: 2,
                minWidth: 150,
                sortable: true
            },
            {
                header: t('Audio Interval (m)'),
                dataIndex: 'audio_rotation_interval_minutes',
                width: 145,
                align: 'center',
                sortable: true
            },
            {
                header: t('Last Updated'),
                dataIndex: 'updated_at',
                width: 155,
                sortable: true
            }
        ];

        me.callParent(arguments);
    }
});
