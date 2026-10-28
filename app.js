/*
 * Application entry point for MagnusBilling 8
 */
Ext.Loader.setConfig({
    enabled: true,
    paths: {
        'MBilling': 'app',
        'MBilling.Application': 'classic/src/Application.js',
        'MBilling.view': 'classic/src/view',
        'Ext.ux': 'classic/src/ux',
        'Helper': 'app/helper',
        'Overrides': 'classic/src/overrides',
        'Ext.chart': 'ext/packages/charts/src/chart',
        'Ext.draw': 'ext/packages/charts/src/draw'
    }
});

Ext.application({
    name: 'MBilling',
    extend: 'MBilling.Application'
});
