Ext.define('MBilling.model.CampaignOptimizerConfig', {
    extend: 'Ext.data.Model',
    fields: [
        { name: 'id', type: 'int' },
        { name: 'id_campaign', type: 'int' },
        { name: 'activated', type: 'int' },
        { name: 'mode', type: 'string' },
        // Canonical database fields
        { name: 'max_daily_attempts', type: 'int' },
        { name: 'max_failed_attempts', type: 'int' },
        { name: 'timezone', type: 'string' },
        { name: 'weekday_start_time', type: 'string' },
        { name: 'weekday_stop_time', type: 'string' },
        { name: 'saturday_start_time', type: 'string' },
        { name: 'saturday_stop_time', type: 'string' },
        { name: 'sunday_start_time', type: 'string' },
        { name: 'sunday_stop_time', type: 'string' },
        { name: 'enable_schedule_jitter', type: 'int' },
        { name: 'jitter_max_minutes', type: 'int' },
        { name: 'enable_audio_rotation', type: 'int' },
        { name: 'audio_source_path', type: 'string' },
        { name: 'audio_rotation_interval_min', type: 'int' },
        // View alias fields
        { name: 'max_calls_daily_per_recipient', type: 'int' },
        { name: 'min_failures_temp_block', type: 'int' },
        { name: 'schedule_weekday_start', type: 'string' },
        { name: 'schedule_weekday_end', type: 'string' },
        { name: 'schedule_weekend_start', type: 'string' },
        { name: 'schedule_weekend_end', type: 'string' },
        { name: 'jitter_range_minutes', type: 'int' },
        { name: 'audio_pool_directory', type: 'string' },
        { name: 'audio_rotation_interval_minutes', type: 'int' },
        { name: 'created_at', type: 'string' },
        { name: 'updated_at', type: 'string' },
        'idCampaignname'
    ],
    proxy: {
        type: 'uxproxy',
        module: 'campaignoptimizerconfig'
    }
});
