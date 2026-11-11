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
Ext.define('MBilling.view.phoneNumber.Controller', {
    extend: 'Ext.ux.app.ViewController',
    alias: 'controller.phonenumber',
    reprocessar: function(btn) {
        var me = this,
            store = me.list.getStore(),
            filterData = me.list.filters.getFilterData(),
            filter = filterData.length ? Ext.encode(filterData) : (store.proxy.extraParams.filter || '');
        Ext.Msg.confirm(t('Confirm'), t('Are you sure you want to reprocess and reactivate the numbers?'), function(btnYes) {
            if (btnYes === 'yes') {
                btn.disable();
                me.list.setLoading(true);
                Ext.Ajax.request({
                    url: 'index.php/phoneNumber/reprocesar/',
                    timeout: 120000,
                    params: {
                        filter: filter
                    },
                    scope: me,
                    success: function(response) {
                        var res = Ext.decode(response.responseText, true) || {};
                        if (res[me.nameSuccessRequest]) {
                            Ext.ux.Alert.alert(me.titleSuccess, res[me.nameMsgRequest], 'success');
                            store.load();
                        } else {
                            var errors = Helper.Util.convertErrorsJsonToString(res[me.nameMsgRequest] || res.errors || 'Error');
                            Ext.ux.Alert.alert(me.titleError, errors, 'error');
                        }
                        btn.enable();
                        me.list.setLoading(false);
                    },
                    failure: function(response) {
                        var res = (response && response.responseText && Ext.decode(response.responseText, true)) || {};
                        Ext.ux.Alert.alert(me.titleError, res.msg || res.errors || 'Error', 'error');
                        btn.enable();
                        me.list.setLoading(false);
                    }
                });
            }
        });
    }
});
