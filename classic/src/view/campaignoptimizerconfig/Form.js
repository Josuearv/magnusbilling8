/**
 * Form definition for Campaign Optimizer Configuration
 *
 * MagnusBilling 8 - Fork Josuearv
 */
Ext.define('MBilling.view.campaignoptimizerconfig.Form', {
    extend: 'Ext.ux.form.Panel',
    alias: 'widget.campaignoptimizerconfigform',
    initComponent: function () {
        var me = this;

        me.items = [
            {
                xtype: 'campaigncombo',
                name: 'id_campaign',
                fieldLabel: t('Campaign'),
                allowBlank: false
            },
            {
                xtype: 'booleancombo',
                name: 'activated',
                fieldLabel: t('Active'),
                value: 1
            },
            {
                xtype: 'combobox',
                name: 'mode',
                fieldLabel: t('Mode'),
                forceSelection: true,
                editable: false,
                store: [
                    ['OBSERVATION', 'OBSERVATION (Monitoreo)'],
                    ['ENFORCING', 'ENFORCING (Aplicar límites)'],
                    ['OFF', 'OFF (Desactivado)']
                ],
                value: 'OBSERVATION',
                allowBlank: false
            },
            {
                xtype: 'numberfield',
                name: 'max_calls_daily_per_recipient',
                fieldLabel: t('Max Calls / Day'),
                minValue: 1,
                maxValue: 50,
                value: 6,
                allowBlank: false
            },
            {
                xtype: 'numberfield',
                name: 'min_failures_temp_block',
                fieldLabel: t('Failures Temp Block'),
                minValue: 1,
                maxValue: 20,
                value: 5,
                allowBlank: false
            },
            {
                xtype: 'textfield',
                name: 'timezone',
                fieldLabel: t('Timezone'),
                value: 'America/Argentina/Buenos_Aires',
                allowBlank: false
            },
            {
                xtype: 'textfield',
                name: 'schedule_weekday_start',
                fieldLabel: t('Mon-Fri Start'),
                value: '08:30:00',
                regex: /^([01]\d|2[0-3]):[0-5]\d:[0-5]\d$/,
                regexText: 'Formato requerido: HH:MM:SS',
                allowBlank: false
            },
            {
                xtype: 'textfield',
                name: 'schedule_weekday_end',
                fieldLabel: t('Mon-Fri End'),
                value: '20:30:00',
                regex: /^([01]\d|2[0-3]):[0-5]\d:[0-5]\d$/,
                regexText: 'Formato requerido: HH:MM:SS',
                allowBlank: false
            },
            {
                xtype: 'textfield',
                name: 'schedule_weekend_start',
                fieldLabel: t('Weekend Start'),
                value: '09:00:00',
                regex: /^([01]\d|2[0-3]):[0-5]\d:[0-5]\d$/,
                regexText: 'Formato requerido: HH:MM:SS',
                allowBlank: false
            },
            {
                xtype: 'textfield',
                name: 'schedule_weekend_end',
                fieldLabel: t('Weekend End'),
                value: '18:00:00',
                regex: /^([01]\d|2[0-3]):[0-5]\d:[0-5]\d$/,
                regexText: 'Formato requerido: HH:MM:SS',
                allowBlank: false
            },
            {
                xtype: 'numberfield',
                name: 'jitter_range_minutes',
                fieldLabel: t('Jitter Range (min)'),
                minValue: 0,
                maxValue: 60,
                value: 30,
                allowBlank: false
            },
            {
                xtype: 'textfield',
                name: 'audio_pool_directory',
                fieldLabel: t('Audio Directory'),
                value: '/opt/audio',
                allowBlank: false
            },
            {
                xtype: 'numberfield',
                name: 'audio_rotation_interval_minutes',
                fieldLabel: t('Audio Interval (min)'),
                minValue: 1,
                maxValue: 1440,
                value: 15,
                allowBlank: false
            }
        ];

        me.callParent(arguments);
    }
});
