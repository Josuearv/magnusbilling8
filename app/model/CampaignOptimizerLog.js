Ext.define('MBilling.model.CampaignOptimizerLog', {
    extend: 'Ext.data.Model',
    fields: [
        { name: 'id', type: 'int' },
        { name: 'execution_type', type: 'string' },
        { name: 'requested_by', type: 'string' },
        { name: 'status', type: 'string' },
        { name: 'start_time', type: 'string' },
        { name: 'duration_seconds', type: 'number' },
        { name: 'attempts_reserved', type: 'int' },
        { name: 'attempts_reconciled', type: 'int' },
        { name: 'suspensions_applied', type: 'int' },
        { name: 'suspensions_expired', type: 'int' },
        { name: 'schedules_updated', type: 'int' },
        { name: 'audios_rotated', type: 'int' },
        { name: 'error_details', type: 'string' }
    ],
    proxy: {
        type: 'uxproxy',
        module: 'campaignoptimizerlog'
    }
});
