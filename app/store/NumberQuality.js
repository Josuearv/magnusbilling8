Ext.define('MBilling.store.NumberQuality', {
    extend: 'Ext.data.Store',
    model: 'MBilling.model.NumberQuality',
    remoteSort: true,
    pageSize: 50,
    sorters: [{
        property: 'quality_score',
        direction: 'ASC'
    }]
});
