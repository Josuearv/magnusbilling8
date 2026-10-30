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
    height: 230,
    labelWidthFields: 90,
    htmlTipInfo: '',
    fieldsImport: [],
    listeners: {
        close: function() {
            this.list.setLoading(false);
        }
    },
    initComponent: function() {
        var me = this,
            fieldsImport = Ext.Array.merge(me.fieldsImport, [{
                xtype: 'uploadfield',
                fieldLabel: t('File CSV'),
                htmlTipInfo: me.htmlTipInfo
            }]);
        me.items = [{
            xtype: 'form',
            bodyPadding: '16 20',
            defaults: {
                anchor: '100%',
                labelWidth: me.labelWidthFields
            },
            items: fieldsImport
        }];
        if (!me.title || me.title === '') {
            me.title = t('Import CSV') + (me.titleModule ? ' - ' + me.titleModule : '');
        }
        me.bbar = [{
            xtype: 'tbtext',
            text: t('Max size file') + ' ' + window.uploadFaxFilesize
        }, '->', {
            iconCls: 'icon-import-csv',
            text: t('Import'),
            width: 150,
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
                if (Ext.isObject(action.response)) {
                    var obj = Ext.decode(action.response.responseText);
                    Ext.ux.Alert.alert(t('Error'), t(obj.errors), 'error');
                } else {
                    Ext.ux.Alert.alert(t('Error'), t(action.response.responseText), 'error', true, false);
                }
                btn.enable();
                me.list.setLoading(false);
                me.close();
            }
        });
    }
});