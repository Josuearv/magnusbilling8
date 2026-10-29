/**
 * Class to view Login
 *
 * Adilson L. Magnus <info@magnussolution.com> 
 * 08/07/2014
 */
Ext.define('MBilling.view.main.Login', {
    extend: 'Ext.window.Window',
    requires: ['MBilling.view.main.ForgetPassword', 'MBilling.view.main.Signup', 'MBilling.view.main.LoginController'],
    xtype: 'login',
    controller: 'login',
    cls: window.isMac ? 'auth-locked-window mb-mac-login-window' : 'auth-locked-window mb-windows-login-window mb-modern-login-window',
    closable: false,
    resizable: false,
    autoShow: true,
    titleAlign: 'center',
    maximized: true,
    modal: true,
    header: false,
    layout: {
        type: 'vbox',
        align: 'center',
        pack: 'center'
    },
    initComponent: function () {
        var me = this,
            isMac = window.isMac === true,
            isWindows = !isMac,
            customName = me.getCustomWindowValue(window.cn),
            customLogo = me.getCustomLogoPath(window.cl),
            loginItems = [];
        if (window.nameCustom) {
            var productName = window.nameCustom;
        } else {
            var productName = window.agentTitle || 'MagnusBilling';
        }
        if (customName) {
            productName = customName;
        }
        if (window.productCustom) {
            var windowsProductName = window.productCustom;
        } else {
            var windowsProductName = window.agentTitle || t('MagnusBilling System');
        }
        if (customName) {
            windowsProductName = customName;
        }

        if (window.logoCustom) {
            var loginLogo = window.logoCustom;
        } else {
            var loginLogo = window.agentTitle ? 'resources/images/logo_custom_' + window.agentId + '.png' : 'resources/images/logo.png';
        }
        if (customLogo) {
            loginLogo = customLogo;
        }

        me.cls = isMac ? 'auth-locked-window mb-mac-login-window' : 'auth-locked-window mb-windows-login-window mb-modern-login-window';
        me.title = window.loginheader ? window.loginheader : t("Log in");
        me.header = false;
        if (isMac) {
            loginItems.push({
                xtype: 'component',
                cls: 'mb-mac-login-brand',
                html: '<div class="mb-mac-login-app-icon">' + (customLogo ?
                    '<img class="mb-mac-login-logo" src="' + Ext.String.htmlEncode(customLogo) + '" alt="" />' :
                    '<span class="x-fa fa-cloud"></span>') + '</div>' +
                    '<div class="mb-mac-login-product">' + Ext.String.htmlEncode(productName) + '</div>'
            });
        } else {
            loginItems.push({
                xtype: 'component',
                cls: 'mb-login-brand-wrapper',
                html: '<div class="mb-login-brand-logo-wrap">' +
                    '<img class="mb-login-brand-logo" src="' + Ext.String.htmlEncode(loginLogo) + '" alt="' + Ext.String.htmlEncode(windowsProductName) + '" />' +
                    '</div>' +
                    '<h1 class="mb-login-brand-title">' + Ext.String.htmlEncode(windowsProductName) + '</h1>' +
                    '<p class="mb-login-brand-subtitle">' + Ext.String.htmlEncode(t('Sign into your account')) + '</p>'
            });
        }
        loginItems.push({
            xtype: 'component',
            cls: 'mb-login-field-label-wrap',
            html: '<label class="mb-login-field-label" for="login-userid-input">' + Ext.String.htmlEncode(t('Username or email')) + '</label>'
        }, {
            xtype: 'textfield',
            cls: 'auth-textbox mb-modern-input-field mb-field-user',
            inputId: 'login-userid-input',
            name: 'userid',
            reference: 'user',
            hideLabel: true,
            allowBlank: false,
            emptyText: t('Username or email'),
            inputAttrTpl: ' autocomplete="username" spellcheck="false" autocapitalize="none" '
        }, {
            xtype: 'component',
            cls: 'mb-login-field-label-wrap',
            html: '<label class="mb-login-field-label" for="login-password-input">' + Ext.String.htmlEncode(t('Password')) + '</label>'
        }, {
            xtype: 'textfield',
            cls: 'auth-textbox mb-modern-input-field mb-field-password',
            inputId: 'login-password-input',
            reference: 'password',
            emptyText: t('Password'),
            inputType: 'password',
            name: 'password',
            hideLabel: true,
            allowBlank: false,
            inputAttrTpl: ' autocomplete="current-password" ',
            triggers: {
                reveal: {
                    cls: 'x-fa fa-eye mb-trigger-reveal-eye',
                    tooltip: t('Show / hide password'),
                    weight: 1,
                    handler: function () {
                        var field = this;
                        var input = field.inputEl ? field.inputEl.dom : null;
                        if (!input) {
                            return;
                        }
                        var trig = field.getTrigger('reveal');
                        var trigEl = trig && trig.el ? trig.el : null;
                        if (input.type === 'password') {
                            input.type = 'text';
                            if (trigEl) {
                                trigEl.removeCls('fa-eye').addCls('fa-eye-slash');
                            }
                        } else {
                            input.type = 'password';
                            if (trigEl) {
                                trigEl.removeCls('fa-eye-slash').addCls('fa-eye');
                            }
                        }
                    }
                }
            }
        }, {
            xtype: 'button',
            reference: 'loginButton',
            cls: isMac ? 'mb-mac-login-submit' : 'mb-windows-login-submit mb-modern-login-submit',
            scale: 'large',
            height: 48,
            iconAlign: 'right',
            iconCls: 'x-fa fa-arrow-right',
            text: t('Login'),
            formBind: true,
            handler: 'onLogin'
        }, {
            xtype: 'box',
            cls: isMac ? 'mb-mac-login-separator' : 'mb-windows-login-separator mb-modern-login-separator',
            html: '<div class="outer-div"><div class="seperator">' + t('OR') + '</div></div > ',
            hidden: !window.show_signup_button,
            margin: '10 0'
        }, {
            xtype: 'button',
            cls: isMac ? 'mb-mac-login-create' : 'mb-windows-login-create mb-modern-login-create',
            scale: 'large',
            height: 44,
            iconAlign: 'right',
            iconCls: 'x-fa fa-user-plus',
            text: t('Create an account'),
            handler: 'onSignup',
            hidden: !window.show_signup_button
        }, {
            cls: isMac ? 'mb-mac-login-links' : 'mb-windows-login-links mb-modern-login-links',
            border: false,
            bodyBorder: false,
            layout: 'hbox',
            items: [{
                xtype: 'locale',
                width: 140,
                border: false,
                margin: '5 0 0 0'
            }, {
                flex: 1,
                border: false,
                bodyBorder: false,
                cls: isMac ? 'mb-mac-login-forgot' : 'mb-windows-login-forgot mb-modern-login-forgot',
                margin: '14 0 0 16',
                html: '<div class="mb-modern-forgot-link" style="text-align: right; cursor: pointer; white-space: nowrap;">' + Ext.String.htmlEncode(t('Forgot your password?')) + '</div>',
                listeners: {
                    render: function (c) {
                        c.getEl().on({
                            click: function () {
                                Ext.widget('forgetPassword');
                            }
                        });
                    }
                }
            }]
        }, {
            xtype: 'component',
            cls: 'mb-modern-login-footer-tag',
            html: '<div class="mb-modern-login-footer-text">MagnusBilling 8 &bull; Enterprise VoIP Platform</div>'
        }, {
            xtype: 'box',
            id: 'myCaptcha',
            name: 'captcha',
            reference: 'captcha',
            listeners: {
                'afterrender': function () {
                    if (typeof grecaptcha != "undefined") {
                        grecaptcha.ready(function () {
                            grecaptcha.execute(window.reCaptchaKey, {
                                action: 'homepage'
                            }).then(function (token) {
                                window.captcha = token;
                            });
                        });
                    }
                }
            }
        });
        me.items = [{
            defaultFocus: 'textfield:focusable:not([hidden]):not([disabled]):not([value])',
            cls: isMac ? 'auth-dialog mb-mac-login-card' : 'auth-dialog mb-windows-login-card mb-modern-login-card',
            defaultButton: 'loginButton',
            autoComplete: true,
            border: false,
            bodyBorder: false,
            bodyPadding: isMac ? '34 38 28' : '36 40 32',
            header: false,
            width: Math.min(440, Ext.Element.getViewportWidth() - 32),
            layout: {
                type: 'vbox',
                align: 'stretch'
            },
            defaults: {
                margin: '0',
                border: false
            },
            items: loginItems
        }];
        me.callParent(arguments);
    },

    getCustomWindowValue: function (value) {
        value = Ext.String.trim(String(value || ''));
        return value && value !== 'undefined' && value !== 'null' ? value : '';
    },

    getCustomLogoPath: function (value) {
        value = this.getCustomWindowValue(value);
        if (!value) {
            return '';
        }
        if (/^(?:[a-z]+:)?\/\//i.test(value) || value.charAt(0) === '/' || value.indexOf('/') !== -1) {
            return value;
        }
        return 'resources/images/' + value;
    }
});
