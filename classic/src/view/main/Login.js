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
    cls: 'auth-locked-window mb-modern-login-window',
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
            customName = me.getCustomWindowValue(window.cn),
            customLogo = me.getCustomLogoPath(window.cl),
            loginItems = [];

        var productName = window.nameCustom || window.agentTitle || 'MagnusBilling';
        if (customName) {
            productName = customName;
        }

        var windowsProductName = window.productCustom || window.agentTitle || t('MagnusBilling System');
        if (customName) {
            windowsProductName = customName;
        }

        var loginLogo = 'resources/images/infinyte_logo.svg';
        if (window.logoCustom) {
            loginLogo = window.logoCustom;
        } else if (customLogo) {
            loginLogo = customLogo;
        } else if (window.agentTitle) {
            loginLogo = 'resources/images/logo_custom_' + window.agentId + '.png';
        }

        me.cls = 'auth-locked-window mb-modern-login-window';
        me.title = window.loginheader ? window.loginheader : t("Sign In");
        me.header = false;

        loginItems.push({
            xtype: 'component',
            cls: 'mb-login-brand-wrapper',
            html: '<div class="mb-login-brand-logo-wrap">' +
                '<img class="mb-login-brand-logo" src="' + Ext.String.htmlEncode(loginLogo) + '" alt="' + Ext.String.htmlEncode(windowsProductName) + '" onerror="if(this.src.indexOf(\'infinyte_logo.svg\')===-1){this.src=\'resources/images/infinyte_logo.svg\';}" />' +
                '</div>' +
                '<h1 class="mb-login-brand-title">' + Ext.String.htmlEncode(t('Great to see you here')) + ' &#128075;</h1>' +
                '<p class="mb-login-brand-subtitle">' + Ext.String.htmlEncode(t("Let's get you signed in. Enter your credentials to continue.")) + '</p>'
        });

        loginItems.push({
            xtype: 'textfield',
            cls: 'auth-textbox mb-modern-input-field mb-field-user',
            inputId: 'login-userid-input',
            name: 'userid',
            reference: 'user',
            fieldLabel: t('Username or email') + ' <span class="mb-login-required">*</span>',
            labelAlign: 'top',
            labelSeparator: '',
            labelPad: 6,
            height: 74,
            width: '100%',
            allowBlank: false,
            emptyText: 'admin@example.com',
            inputAttrTpl: ' autocomplete="username" spellcheck="false" autocapitalize="none" ',
            margin: '0 0 14 0'
        }, {
            xtype: 'textfield',
            cls: 'auth-textbox mb-modern-input-field mb-field-password',
            inputId: 'login-password-input',
            reference: 'password',
            fieldLabel: t('Password') + ' <span class="mb-login-required">*</span>',
            labelAlign: 'top',
            labelSeparator: '',
            labelPad: 6,
            height: 74,
            width: '100%',
            emptyText: '••••••••',
            inputType: 'password',
            name: 'password',
            allowBlank: false,
            inputAttrTpl: ' autocomplete="current-password" ',
            margin: '0 0 12 0',
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
        });

        loginItems.push({
            cls: 'mb-modern-login-links',
            border: false,
            bodyBorder: false,
            layout: {
                type: 'hbox',
                align: 'middle'
            },
            width: '100%',
            margin: '2 0 18 0',
            items: [{
                xtype: 'locale',
                width: 140,
                border: false,
                margin: '0'
            }, {
                xtype: 'component',
                flex: 1,
                border: false,
                cls: 'mb-modern-login-forgot',
                margin: '0 0 0 12',
                html: '<div class="mb-modern-forgot-link">' + Ext.String.htmlEncode(t('Forgot Password?')) + '</div>',
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
        });

        loginItems.push({
            xtype: 'button',
            reference: 'loginButton',
            cls: 'mb-modern-login-submit',
            scale: 'large',
            height: 44,
            width: '100%',
            text: t('Sign In'),
            formBind: true,
            handler: 'onLogin',
            margin: '4 0 0 0'
        });

        if (window.show_signup_button) {
            loginItems.push({
                xtype: 'component',
                cls: 'mb-modern-login-signup-wrap',
                margin: '18 0 0 0',
                html: '<div class="mb-modern-signup-text">' +
                    Ext.String.htmlEncode(t('New here?')) + ' ' +
                    '<a class="mb-modern-signup-link" href="javascript:void(0);">' +
                    Ext.String.htmlEncode(t('Create an account')) +
                    '</a>' +
                    '</div>',
                listeners: {
                    render: function (c) {
                        var linkEl = c.getEl().down('.mb-modern-signup-link');
                        if (linkEl) {
                            linkEl.on('click', function (e) {
                                e.preventDefault();
                                me.getController().onSignup();
                            });
                        }
                    }
                }
            });
        }

        loginItems.push({
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
            cls: 'auth-dialog mb-modern-login-card',
            defaultButton: 'loginButton',
            autoComplete: true,
            border: false,
            bodyBorder: false,
            bodyPadding: '40 44 32',
            header: false,
            width: Math.min(460, Ext.Element.getViewportWidth() - 32),
            layout: {
                type: 'vbox',
                align: 'stretch'
            },
            defaults: {
                border: false
            },
            items: loginItems
        }, {
            xtype: 'component',
            cls: 'mb-modern-login-outer-footer',
            html: '<div class="mb-modern-outer-footer-text">&copy; ' + new Date().getFullYear() + ' ' + Ext.String.htmlEncode(productName) + ' &mdash; by Infinyte</div>',
            margin: '22 0 0 0'
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
