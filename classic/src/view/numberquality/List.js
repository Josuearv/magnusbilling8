/**
 * Grid view for CDR & ASR Number Quality Analytics.
 */
Ext.define('MBilling.view.numberquality.List', {
    extend: 'Ext.grid.Panel',
    alias: 'widget.numberqualitylist',
    border: false,
    columnLines: true,
    selModel: {
        mode: 'SINGLE'
    },
    viewConfig: {
        loadMask: {
            msg: t('Loading...')
        },
        emptyText: '<div class="grid-empty">' +
            t('No scored destinations yet. Run Cron NumberQuality or click Recalculate Now.') +
            '</div>',
        deferEmptyText: false
    },
    listeners: {
        selectionchange: 'onSelectionChange'
    },

    initComponent: function () {
        var me = this;

        me.store = Ext.create('MBilling.store.NumberQuality');

        me.columns = [{
            text: t('Score'),
            dataIndex: 'quality_score',
            width: 72,
            align: 'center',
            renderer: function (val, meta, rec) {
                var num = parseFloat(val || 0).toFixed(0);
                var label = rec.get('quality_label');
                var cls = 'mb-nq-pill-good';
                if (label === 'INSUFFICIENT_DATA') {
                    cls = 'mb-nq-pill-muted';
                } else if (label === 'TRUNK_SUSPECT') {
                    cls = 'mb-nq-pill-trunk';
                } else if (parseFloat(val) < 35) {
                    cls = 'mb-nq-pill-bad';
                } else if (parseFloat(val) < 60) {
                    cls = 'mb-nq-pill-warn';
                }
                return '<span class="mb-nq-pill ' + cls + '">' + num + '</span>';
            }
        }, {
            text: t('Diagnosis'),
            dataIndex: 'quality_label',
            width: 155,
            renderer: function (val, meta, rec) {
                var map = {
                    BAD_SHORT_5S: { txt: 'Corta <=5s', cls: 'mb-nq-pill-bad' },
                    BAD_UNDER_3MIN: { txt: 'Siempre <3m', cls: 'mb-nq-pill-bad' },
                    RIGID_CUT_PATTERN: { txt: 'Corte Rígido', cls: 'mb-nq-pill-bad' },
                    UNREACHABLE_FAIL_PATTERN: { txt: 'Fallo / ASR Crítico', cls: 'mb-nq-pill-bad' },
                    TRUNK_SUSPECT: { txt: 'Posible Troncal', cls: 'mb-nq-pill-trunk' },
                    WATCHLIST: { txt: 'Observación', cls: 'mb-nq-pill-warn' },
                    HEALTHY: { txt: 'Saludable', cls: 'mb-nq-pill-good' },
                    INSUFFICIENT_DATA: { txt: 'Poca Muestra', cls: 'mb-nq-pill-muted' }
                };
                var info = map[val] || { txt: val || 'N/A', cls: 'mb-nq-pill-muted' };
                meta.tdAttr = 'data-qtip="' + Ext.util.Format.htmlEncode(rec.get('reason_summary') || '') + '"';
                return '<span class="mb-nq-pill ' + info.cls + '">' + Ext.util.Format.htmlEncode(info.txt) + '</span>';
            }
        }, {
            text: t('Destination'),
            dataIndex: 'canonical_number',
            width: 125,
            renderer: function (val) {
                return '<b style="color:#1e293b;">' + Ext.util.Format.htmlEncode(val) + '</b>';
            }
        }, {
            text: t('Campaign'),
            dataIndex: 'idCampaignname',
            width: 120,
            renderer: function (val, meta, rec) {
                if (parseInt(rec.get('id_campaign'), 10) <= 0 || !val) {
                    return '<span style="color:#94a3b8;">General</span>';
                }
                return Ext.util.Format.htmlEncode(val);
            }
        }, {
            text: t('Trunk'),
            dataIndex: 'idTrunktrunkcode',
            width: 110,
            renderer: function (val, meta, rec) {
                if (parseInt(rec.get('id_trunk'), 10) <= 0 || !val) {
                    return '<span style="color:#94a3b8;">N/A</span>';
                }
                return Ext.util.Format.htmlEncode(val);
            }
        }, {
            text: t('Attempts'),
            dataIndex: 'attempts',
            width: 74,
            align: 'right'
        }, {
            text: t('Answered'),
            dataIndex: 'answered',
            width: 68,
            align: 'right'
        }, {
            text: 'ASR %',
            dataIndex: 'asr_pct',
            width: 72,
            align: 'right',
            renderer: function (val) {
                var num = parseFloat(val || 0);
                var color = num < 15 ? '#dc2626' : (num < 30 ? '#d97706' : '#16a34a');
                return '<span style="font-weight:700;color:' + color + ';">' + num.toFixed(1) + '%</span>';
            }
        }, {
            text: '<=5s %',
            dataIndex: 'sdr_5s_pct',
            width: 72,
            align: 'right',
            renderer: function (val) {
                var num = parseFloat(val || 0);
                var color = num >= 60 ? '#dc2626' : (num >= 35 ? '#d97706' : '#475569');
                return '<span style="font-weight:700;color:' + color + ';">' + num.toFixed(1) + '%</span>';
            }
        }, {
            text: '>=3 min',
            dataIndex: 'cnt_long_180s',
            width: 68,
            align: 'right'
        }, {
            text: 'ACD (s)',
            dataIndex: 'acd_sec',
            width: 70,
            align: 'right',
            renderer: function (val) {
                return parseFloat(val || 0).toFixed(1) + 's';
            }
        }, {
            text: 'CV',
            dataIndex: 'duration_cv',
            width: 60,
            align: 'right',
            renderer: function (val) {
                return parseFloat(val || 0).toFixed(2);
            }
        }, {
            text: t('Dom. Fail'),
            dataIndex: 'dominant_fail_cause',
            width: 115,
            renderer: function (val, meta, rec) {
                if (!val || val === 'NONE') {
                    return '-';
                }
                return Ext.util.Format.htmlEncode(val + ' (' + parseFloat(rec.get('dominant_fail_pct') || 0).toFixed(0) + '%)');
            }
        }, {
            text: t('Cost'),
            dataIndex: 'buycost_total',
            width: 78,
            align: 'right',
            renderer: function (val) {
                return '$' + parseFloat(val || 0).toFixed(3);
            }
        }, {
            text: t('Detailed Reason'),
            dataIndex: 'reason_summary',
            flex: 1,
            minWidth: 200,
            renderer: function (val, meta) {
                meta.tdAttr = 'data-qtip="' + Ext.util.Format.htmlEncode(val || '') + '"';
                return Ext.util.Format.htmlEncode(val || '');
            }
        }];

        me.dockedItems = [{
            xtype: 'toolbar',
            dock: 'top',
            minHeight: 40,
            layout: {
                overflowHandler: 'Menu'
            },
            items: [{
                xtype: 'combobox',
                reference: 'labelFilter',
                fieldLabel: t('Filter'),
                labelWidth: 38,
                width: 205,
                editable: false,
                value: '',
                store: [
                    ['', 'Todos'],
                    ['BAD_ALL', 'Todos los Malos'],
                    ['BAD_SHORT_5S', 'Cortan <= 5s'],
                    ['BAD_UNDER_3MIN', 'Siempre < 3 min'],
                    ['RIGID_CUT_PATTERN', 'Patrón Corte Rígido'],
                    ['UNREACHABLE_FAIL_PATTERN', 'ASR Crítico / Fallos'],
                    ['TRUNK_SUSPECT', 'Posible Falla de Troncal'],
                    ['WATCHLIST', 'En Observación'],
                    ['HEALTHY', 'Saludables'],
                    ['INSUFFICIENT_DATA', 'Muestra Insuficiente']
                ],
                listeners: {
                    change: 'onFilterChange'
                }
            }, {
                xtype: 'combobox',
                reference: 'minAttemptsFilter',
                fieldLabel: t('Min. Int.'),
                labelWidth: 52,
                width: 130,
                editable: false,
                value: 0,
                store: [
                    [0, 'Todos'],
                    [3, '>= 3'],
                    [5, '>= 5'],
                    [10, '>= 10'],
                    [20, '>= 20'],
                    [50, '>= 50']
                ],
                listeners: {
                    change: 'onFilterChange'
                }
            }, {
                xtype: 'textfield',
                reference: 'searchField',
                emptyText: t('Number, campaign, trunk...'),
                width: 185,
                enableKeyEvents: true,
                listeners: {
                    specialkey: 'onSearchSpecialKey'
                }
            }, {
                text: t('Search'),
                iconCls: 'x-fa fa-search',
                handler: 'onFilterChange'
            }, {
                text: t('Clear'),
                iconCls: 'x-fa fa-eraser',
                handler: 'onClearFilters'
            }, '->', {
                text: t('Recalculate Now'),
                iconCls: 'x-fa fa-refresh',
                handler: 'onRecalculate'
            }, {
                text: 'CSV',
                iconCls: 'x-fa fa-download',
                handler: 'onExportCsv'
            }]
        }, {
            xtype: 'pagingtoolbar',
            dock: 'bottom',
            store: me.store,
            displayInfo: true
        }];

        me.callParent(arguments);
    }
});
