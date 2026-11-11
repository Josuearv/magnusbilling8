/**
 * Classe que define a lista de "CallShopCdr"
 *
 * =======================================
 * ###################################
 * MagnusBilling
 *
 * @package MagnusBilling
 * @author Adilson Leffa Magnus.
 * @copyright Copyright (C) 2005 - 2021 MagnusBilling. All rights reserved.
 * ###################################
 *
 * This software is released under the terms of the GNU Lesser General Public License v3
 * A copy of which is available from http://www.gnu.org/copyleft/lesser.html
 *
 * Please submit bug reports, patches, etc to https://github.com/magnussolution/magnusbilling7/issues
 * =======================================
 * Magnusbilling.org <info@magnussolution.com>
 * 01/10/2013
 */
Ext.define('MBilling.view.campaign.Controller', {
    extend: 'Ext.ux.app.ViewController',
    alias: 'controller.campaign',
    isSubmitForm: true,
    init: function () {
        var me = this;
        me.control({
            'typecampaigndestinationcombo': {
                select: me.onSelectMethod
            }
        });
        me.callParent(arguments);
    },
    onSelectMethod: function (combo, records) {
        this.showFieldsRelated(records.getData().showFields);
    },
    showFieldsRelated: function (showFields) {
        var me = this,
            form = me.formPanel.getForm(),
            fields = me.formPanel.getForm().getFields(),
            activeField = Ext.get(Ext.Element.getActiveElement()).component;
        me.onSetVisibleFiel(activeField, form, activeField.value);
    },
    onSetVisibleFiel: function (activeField, form, fieldShow) {
        if (activeField.value == 'undefined') activeField.setValue('undefined');
        if (activeField.name.match("^type_0")) {
            form.findField('id_queue_0').setVisible(fieldShow.match("^queue"));
            form.findField('id_sip_0').setVisible(fieldShow.match("^pjsip"));
            form.findField('id_ivr_0').setVisible(fieldShow.match("^ivr"));
            form.findField('extension_0').setVisible(fieldShow.match("^group|^number|^custom"));
        }
    },
    onEdit: function () {
        var me = this,
            form = me.formPanel.getForm(),
            record = me.list.getSelectionModel().getSelection()[0];
        if (record.getData()['type_0'] == 'ivr') {
            form.findField('id_ivr_0').setVisible(true);
            form.findField('id_sip_0').setVisible(false);
            form.findField('id_queue_0').setVisible(false);
            form.findField('extension_0').setVisible(false);
        } else if (record.getData()['type_0'] == 'pjsip') {
            form.findField('id_sip_0').setVisible(true);
            form.findField('id_ivr_0').setVisible(false);
            form.findField('id_queue_0').setVisible(false);
            form.findField('extension_0').setVisible(false);
        } else if (record.getData()['type_0'] == 'queue') {
            form.findField('id_queue_0').setVisible(true);
            form.findField('id_sip_0').setVisible(false);
            form.findField('id_ivr_0').setVisible(false);
            form.findField('extension_0').setVisible(false);
        } else if (record.getData()['type_0'].match("custom|number|group")) {
            form.findField('extension_0').setVisible(true);
            form.findField('id_ivr_0').setVisible(false);
            form.findField('id_sip_0').setVisible(false);
            form.findField('id_queue_0').setVisible(false);
        } else {
            form.findField('id_queue_0').setVisible(false);
            form.findField('id_sip_0').setVisible(false);
            form.findField('id_ivr_0').setVisible(false);
            form.findField('extension_0').setVisible(false);
        }
        me.callParent(arguments);
    },
    onNew: function () {
        var me = this,
            form = me.formPanel.getForm(),
            record = me.list.getSelectionModel().getSelection()[0];
        form.findField('id_ivr_0').setVisible(false);
        form.findField('id_sip_0').setVisible(false);
        form.findField('id_queue_0').setVisible(false);
        form.findField('extension_0').setVisible(false);
        me.callParent(arguments);
    },
    submitForm: function (values) {
        var me = this,
            store = me.store;
        var pb = me.formPanel.getForm().getFieldValues().id_phonebook;
        Ext.apply(me.params, {
            id_phonebook_array: Ext.isArray(pb) ? pb.join(',') : (pb || '')
        });
        me.formPanel.add({
            xtype: 'hiddenfield',
            name: me.idProperty,
            value: me.formPanel.idRecord
        });
        me.formPanel.getForm().submit({
            url: me.store.getProxy().api.create,
            params: me.params,
            scope: me,
            success: function (form, action) {
                var obj = Ext.decode(action.response.responseText);
                if (obj.success) {
                    Ext.ux.Alert.alert(me.titleSuccess, obj.msg, 'success');
                    me.formPanel.fireEvent('Aftersave', me.formPanel, obj.rows[0]);
                } else {
                    errors = Helper.Util.convertErrorsJsonToString(obj.msg);
                    if (!Ext.isObject(obj.errors)) {
                        Ext.ux.Alert.alert(me.titleError, errors, 'error');
                    } else {
                        form.markInvalid(obj.errors);
                        Ext.ux.Alert.alert(me.titleWarning, me.msgFormInvalid, 'warning');
                    }
                }
                me.store.load();
                me.formPanel.setLoading(false);
                me.saveButton.enable();
            },
            failure: function (form, action) {
                var obj = Ext.decode(action.response.responseText),
                    errors = Helper.Util.convertErrorsJsonToString(obj.errors);
                if (!Ext.isObject(obj.errors)) {
                    Ext.ux.Alert.alert(me.titleError, errors, 'error');
                } else {
                    form.markInvalid(obj.errors);
                    Ext.ux.Alert.alert(me.titleWarning, errors, 'error');
                }
                me.formPanel.setLoading(false);
                me.saveButton.enable();
            }
        });
        //delete me.params['id_phonebook_array'];
    },
    onTestCampaign: function (btn) {
        var me = this,
            idCampaign = null;

        if (btn && btn.up('form')) {
            var form = btn.up('form').getForm(),
                idField = form.findField('id');
            idCampaign = idField ? idField.getValue() : (me.formPanel ? me.formPanel.idRecord : null);
            if (!idCampaign) {
                Ext.ux.Alert.alert(me.titleError, t('Please save the campaign first.'), 'notification');
                return;
            }
        } else if (me.list) {
            var selection = me.list.getSelectionModel().getSelection();
            if (selection.length === 0) {
                Ext.ux.Alert.alert(me.titleError, t('Please select one campaign'), 'notification');
                return;
            } else if (selection.length > 1) {
                Ext.ux.Alert.alert(me.titleError, t('Please select only one campaign'), 'notification');
                return;
            }
            idCampaign = selection[0].get('id');
        }

        if (!idCampaign) {
            Ext.ux.Alert.alert(me.titleError, t('Please select one campaign'), 'notification');
            return;
        }

        Ext.Ajax.request({
            url: 'index.php/campaign/testCampaign',
            params: {
                id: idCampaign
            },
            scope: me,
            success: function (response) {
                var res = Ext.decode(response.responseText);
                if (res[me.nameSuccessRequest]) {
                    Ext.ux.Alert.alert(me.titleSuccess, res[me.nameMsgRequest], 'success');
                } else {
                    var errors = Helper.Util.convertErrorsJsonToString(res[me.nameMsgRequest]);
                    Ext.ux.Alert.alert(me.titleError, errors, 'error');
                }
            },
            failure: function (response) {
                try {
                    var res = Ext.decode(response.responseText);
                    var errors = Helper.Util.convertErrorsJsonToString(res[me.nameMsgRequest] || res.msg);
                    Ext.ux.Alert.alert(me.titleError, errors, 'error');
                } catch (e) {
                    Ext.ux.Alert.alert(me.titleError, t('Error checking campaign'), 'error');
                }
            }
        });
    }
});
