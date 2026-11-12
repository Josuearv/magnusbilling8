/**
 * CDR & ASR Number Quality Analytics Module.
 *
 * Layout:
 *  - north: KPI strip + trunk health quick filter summary
 *  - center: Scored numbers grid with label chips & search
 *  - east: Selected destination drilldown (histogram + 50 recent CDRs + operator actions)
 */
Ext.define('MBilling.view.numberquality.Module', {
    extend: 'Ext.panel.Panel',
    alias: 'widget.numberqualitymodule',
    controller: 'numberquality',
    layout: 'border',
    border: false,
    listeners: {
        afterrender: 'onRenderModule',
        destroy: 'onDestroyModule'
    },
    items: [{
        xtype: 'component',
        region: 'north',
        reference: 'summaryBanner',
        html: '<div class="mb-nq-kpi-strip"><div class="mb-nq-kpi-card"><div class="mb-nq-kpi-title">' +
            t('Loading analytics...') + '</div></div></div>'
    }, {
        xtype: 'numberqualitylist',
        region: 'center',
        reference: 'qualityList'
    }, {
        xtype: 'numberqualitydetail',
        region: 'east',
        reference: 'qualityDetail',
        width: 350,
        minWidth: 260,
        split: true,
        collapsible: true
    }]
});
