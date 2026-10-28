Ext.define('MBilling.model.CampaignFailedAnalytics', {
    extend: 'Ext.data.Model',
    fields: [
        { name: 'id', type: 'int' },
        { name: 'id_campaign', type: 'int' },
        { name: 'id_phonenumber', type: 'int' },
        { name: 'canonical_number', type: 'string' },
        { name: 'date_day', type: 'string' },
        { name: 'failure_count', type: 'int' },
        { name: 'count_no_answer', type: 'int' },
        { name: 'count_busy', type: 'int' },
        { name: 'count_failed', type: 'int' },
        { name: 'count_congestion', type: 'int' },
        { name: 'count_chanunavail', type: 'int' },
        { name: 'last_sip_code', type: 'int' },
        { name: 'last_hangup_cause', type: 'int' },
        { name: 'current_state', type: 'string' },
        { name: 'suspended_until', type: 'string' },
        { name: 'last_attempt_at', type: 'string' },
        { name: 'updated_at', type: 'string' },
        'idCampaignname',
        'idPhonenumbernumber'
    ],
    proxy: {
        type: 'uxproxy',
        module: 'campaignfailedanalytics'
    }
});
