Ext.define('Ext.ux.button.Credit', {
    extend: 'Ext.Button',
    alias: 'widget.credit',
    height: 30,
    iconCls: 'x-fa fa-wallet',
    initComponent: function () {
        var me = this;
        if (!App.user.isAdmin) {
            me.text = App.user.currency + ' ' + Ext.util.Format.number(App.user.credit, '0.00');
            Ext.Ajax.request({
                url: 'index.php/user/credit',
                success: function (r) {
                    r = Ext.decode(r.responseText);
                    App.user.credit = r.rows.credit;
                    me.setText(App.user.currency + ' ' + Ext.util.Format.number(App.user.credit, '0.00'));
                }
            });
            me.handler = setInterval(function () {
                Ext.Ajax.request({
                    url: 'index.php/user/credit',
                    success: function (r) {
                        r = Ext.decode(r.responseText);
                        App.user.credit = r.rows.credit;
                        me.setText(App.user.currency + ' ' + Ext.util.Format.number(App.user.credit, '0.00'));
                    }
                });
            }, 15000);
        } else {
            me.hidden = true;
        }
        me.callParent(arguments);
    }
});
