Ext.define('MBilling.model.CampaignOptimizerState', {
    extend: 'Ext.data.Model',
    fields: [
        { name: 'id', type: 'int' },
        { name: 'id_campaign', type: 'int' },
        { name: 'id_phonenumber', type: 'int' },
        { name: 'canonical_number', type: 'string' },
        { name: 'state', type: 'string' },
        { name: 'reason', type: 'string' },
        { name: 'suspended_until', type: 'string' },
        { name: 'is_permanent', type: 'int' },
        { name: 'applied_by', type: 'string' },
        { name: 'applied_at', type: 'string' },
        { name: 'updated_at', type: 'string' },
        'idCampaignname',
        'idPhonenumbernumber'
    ],
    proxy: {
        type: 'uxproxy',
        module: 'campaignoptimizerstate'
    }
});
