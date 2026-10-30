/**
 * Classe que define o grafico de "refill"
 *
 * =======================================
 * ###################################
 * MagnusBilling
 *
 * @package MagnusBilling
 * @author Adilson Leffa Magnus.
 * @copyright Copyright (C) 2005 - 2021 MagnusBilling. All rights reserved.
 * ###################################
 *
 * This software is released under the terms of the GNU Lesser General Public License v3
 * A copy of which is available from http://www.gnu.org/copyleft/lesser.html
 *
 * Please submit bug reports, patches, etc to https://github.com/magnussolution/magnusbilling7/issues
 * =======================================
 * Magnusbilling.org <info@magnussolution.com>
 * 13/02/2012
 */
Ext.define('MBilling.view.dashboard.NetworkChart', {
    extend: 'Ext.ux.panel.Chart',
    alias: 'widget.networkchart',
    store: 'StatusSystem',
    charts: ['line'],
    defaultChart: 'line',
    fieldValue: ['networkin', 'networkout'],
    fieldDescription: 'date',
    labelYLine: t('KB/s'),
    labelXLine: t('Network usage'),
    reference: 'networkchart',
    btnShowColumn: false,
    btnShowBar: false,
    btnShowLine: true,
    btnShowPie: false,
    animate: true,
    btnShowLineHidden: true,
    radius: 3,
    showDownload: false,
    animation: true,
    insetPadding: '10 10 5 10',
    verticalLinesHidden: true,
    horizontalLinesHidden: false,
    hiddenButtonsCharts: true,
    seriesLine: [{
        type: 'line',
        colors: ['#3b82f6'],
        useDarkerStrokeColor: false,
        xField: 'date',
        yField: 'networkin',
        title: t('Inbound'),
        fill: true,
        smooth: true,
        marker: {
            radius: 3
        },
        style: {
            lineWidth: 2,
            stroke: '#3b82f6',
            fillOpacity: 0.15
        }
    }, {
        type: 'line',
        colors: ['#ef4444'],
        useDarkerStrokeColor: false,
        xField: 'date',
        yField: 'networkout',
        title: t('Outbound'),
        fill: true,
        smooth: true,
        marker: {
            radius: 3
        },
        style: {
            lineWidth: 2,
            stroke: '#ef4444',
            fillOpacity: 0.15
        }
    }],
    initComponent: function() {
        var me = this;
        me.rendererFieldValue = function(axis, label, layoutContext) {
            return (layoutContext ? layoutContext.renderer(label) : label) + ' kb/s';
        };
        me.sessionLoad = Ext.create('Ext.util.DelayedTask', function() {
            if (me.store && typeof me.store.load === 'function') {
                me.store.load();
            }
        }, me);
        me.callParent(arguments);
        if (me.store && typeof me.store.on === 'function') {
            me.store.on('load', me.onLoadStore, me);
            me.sessionLoad.delay(500);
        }
    },
    onLoadStore: function() {
        this.onActivateModule();
    },
    onActivateModule: function() {
        this.sessionLoad && this.sessionLoad.delay(10000);
    }
});