/**
 * Ux to import CSV
 * Adilson L. Magnus <info@magnussolution.com>
 * 11/08/2014
 */
Ext.define('Ext.ux.window.ImportCsv', {
    extend: 'Ext.window.Window',
    requires: ['Ext.ux.form.field.FileUpload'],
    autoShow: true,
    modal: true,
    layout: 'fit',
    iconCls: 'icon-import-csv',
    title: t('Import CSV'),
    width: 480,
    height: 280,
    labelWidthFields: 100,
    htmlTipInfo: '',
    fieldsImport: [],
    listeners: {
        close: function() {
            this.list.setLoading(false);
        }
    },
    initComponent: function() {
        var me = this,
            delimiterField = {
                xtype: 'combobox',
                name: 'delimiter',
                fieldLabel: t('Delimiter'),
                editable: false,
                displayField: 'name',
                valueField: 'value',
                value: 'auto',
                store: Ext.create('Ext.data.Store', {
                    fields: ['value', 'name'],
                    data: [
                        { value: 'auto', name: (t('Auto-detect') || 'Auto') + ' (; / ,)' },
                        { value: ';',    name: (t('Semicolon') || 'Punto y coma') + ' (;)' },
                        { value: ',',    name: (t('Comma') || 'Coma') + ' (,)' }
                    ]
                })
            },
            fieldsImport = Ext.Array.merge(me.fieldsImport, [
                delimiterField,
                {
                    xtype: 'uploadfield',
                    fieldLabel: t('File CSV'),
                    buttonText: t('Browse'),
                    htmlTipInfo: me.htmlTipInfo,
                    maxSize: (window.uploadFaxFilesizeBytes && parseInt(window.uploadFaxFilesizeBytes) >= 33554432)
                        ? parseInt(window.uploadFaxFilesizeBytes)
                        : 33554432
                }
            ]);
        me.items = [{
            xtype: 'form',
            bodyPadding: '16 20',
            defaults: {
                anchor: '100%',
                labelWidth: me.labelWidthFields,
                margin: '0 0 10 0'
            },
            items: fieldsImport
        }];
        if (!me.title || me.title === '') {
            me.title = t('Import CSV') + (me.titleModule ? ' - ' + me.titleModule : '');
        }
        me.bbar = [{
            xtype: 'tbtext',
            cls: 'mb-modal-filesize-hint',
            text: t('Max size file') + ': ' + (window.uploadFaxFilesize || '32M')
        }, '->', {
            text: t('Cancel'),
            cls: 'mb-btn-secondary',
            iconCls: 'icon-cancel',
            handler: function() {
                me.close();
            }
        }, {
            text: t('Import'),
            cls: 'mb-btn-primary',
            iconCls: 'icon-import-csv',
            scope: me,
            handler: me.onImport
        }];
        me.callParent(arguments);
    },
    onImport: function(btn) {
        var me = this,
            store = me.list.store;
        btn.disable();
        me.list.setLoading(true);
        me.down('form').submit({
            url: store.getProxy().api.fromCsv,
            scope: me,
            success: function(form, action) {
                var obj = Ext.decode(action.response.responseText);
                if (obj.success) {
                    Ext.ux.Alert.alert(t('Success'), t(obj.msg), 'success');
                } else {
                    Ext.ux.Alert.alert(t('Error'), t(obj.errors), 'error');
                }
                btn.enable();
                me.list.setLoading(false);
                store.load();
                me.close();
            },
            failure: function(form, action) {
                var errorMsg = t('Unknown error');
                if (action && action.result && (action.result.errors || action.result.msg)) {
                    errorMsg = action.result.errors || action.result.msg;
                } else if (action && action.response && action.response.responseText) {
                    try {
                        var obj = Ext.decode(action.response.responseText);
                        errorMsg = obj.errors || obj.msg || action.response.responseText;
                    } catch (e) {
                        errorMsg = action.response.responseText;
                    }
                }
                Ext.ux.Alert.alert(t('Error'), t(errorMsg), 'error');
                btn.enable();
                me.list.setLoading(false);
            }
        });
    }
});