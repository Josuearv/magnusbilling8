Ext.define('MBilling.model.CampaignOptimizerAudio', {
    extend: 'Ext.data.Model',
    fields: [
        { name: 'id', type: 'int' },
        { name: 'id_campaign', type: 'int' },
        { name: 'version_tag', type: 'string' },
        { name: 'file_path', type: 'string' },
        { name: 'sha256_checksum', type: 'string' },
        { name: 'file_size_bytes', type: 'int' },
        { name: 'format', type: 'string' },
        { name: 'is_active', type: 'int' },
        { name: 'validated_at', type: 'string' },
        'idCampaignname'
    ],
    proxy: {
        type: 'uxproxy',
        module: 'campaignoptimizeraudio'
    }
});
