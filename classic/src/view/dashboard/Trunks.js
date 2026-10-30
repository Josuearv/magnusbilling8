Ext.define('MBilling.view.dashboard.Trunks', {
    extend: 'Ext.Panel',
    xtype: 'trunks',
    requires: ['MBilling.view.dashboard.TrunkChart', 'Ext.chart.series.Pie', 'Ext.chart.series.sprite.PieSlice', 'Ext.chart.interactions.Rotate'],
    cls: 'service-type shadow',
    height: 320,
    border: false,
    bodyBorder: false,
    bodyPadding: 16,
    title: t('Trunks'),
    layout: {
        type: 'hbox',
        align: 'stretch'
    },
    initComponent: function() {
        var me = this;
        me.items = [{
            reference: 'trunkChartsContainer',
            width: 140,
            border: false,
            bodyBorder: false,
            hidden: true,
            items: [{
                xtype: 'trunkchart',
                fieldValue: 'nbcall'
            }, {
                xtype: 'trunkchart',
                fieldValue: 'lucro'
            }]
        }, {
            reference: 'trunkDashboardFields',
            flex: 1,
            border: false,
            bodyBorder: false,
            hidden: true,
            layout: {
                type: 'vbox',
                align: 'stretch'
            },
            items: [{
                xtype: 'component'
            }, {
                xtype: 'progressbar',
                cls: 'bottom-indent service-finance',
                height: 4,
                minHeight: 4,
                value: 0
            }, {
                xtype: 'component'
            }, {
                xtype: 'progressbar',
                cls: 'bottom-indent service-research',
                height: 4,
                minHeight: 4,
                value: 0
            }, {
                xtype: 'component'
            }, {
                xtype: 'progressbar',
                cls: 'bottom-indent service-marketing',
                height: 4,
                value: 0
            }, {
                xtype: 'component',
                html: ''
            }]
        }, {
            reference: 'trunkEmptyState',
            flex: 1,
            border: false,
            bodyBorder: false,
            hidden: false,
            html: '<div class="mb-empty-state"><div class="mb-empty-icon-wrap"><i class="x-fa fa-exchange mb-empty-icon"></i></div><div class="mb-empty-title">' + t('No trunk traffic today') + '</div><div class="mb-empty-desc">' + t('Real-time trunk activity and distribution will appear here once calls are placed.') + '</div></div>'
        }];
        me.callParent(arguments);
    }
});