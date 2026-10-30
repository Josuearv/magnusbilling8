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
Ext.define('MBilling.view.main.Main', {
    extend: 'Ext.container.Viewport',
    alias: 'widget.main',
    layout: 'border',
    controller: 'main',
    initComponent: function() {
        var me = this,
            isMobileLayout = window.isMobileLayout || window.isTablet || window.isTablets;
        me.items = [{
            region: 'north',
            border: false,
            hidden: window.isTablet || window.isTablets,
            reference: 'header',
            dockedItems: [{
                xtype: 'toolbar',
                cls: 'mb-main-header-toolbar',
                height: 50,
                style: {
                    'padding-left': '0px',
                    'padding-top': '4px',
                    'padding-bottom': '4px',
                    'padding-right': '14px'
                },
                items: [{
                    xtype: 'image',
                    src: window.logo,
                    height: 34,
                    style: {
                        'max-height': '34px',
                        'width': 'auto',
                        'object-fit': 'contain',
                        'margin-left': '14px'
                    },
                    hidden: window.isTablet || window.isTablets
                }, '->', {
                    xtype: 'credit',
                    cls: 'mb-header-btn-credit',
                    width: '120px'
                }, {
                    xtype: 'locale',
                    cls: 'mb-header-btn-locale',
                    hidden: window.isTablets || window.isTable
                }, '-', {
                    xtype: 'splitbutton',
                    cls: 'mb-header-btn-user',
                    scale: 'small',
                    iconAlign: 'left',
                    iconCls: 'x-fa fa-user-circle',
                    handler: function() {
                        this.showMenu()
                    },
                    text: App.user.username,
                    menu: [{
                        handler: 'openChangePassword',
                        iconCls: 'x-fa fa-key',
                        text: t('Change password'),
                        hidden: !App.user.isAdmin
                    }, {
                        text: t('Import logo'),
                        iconCls: 'x-fa fa-picture-o',
                        handler: 'importLogo',
                        hidden: App.user.isClient || window.isTablet || window.isTablets
                    }, {
                        text: t('Import Login Background'),
                        iconCls: 'x-fa fa-image',
                        handler: 'importLoginBackground',
                        hidden: !App.user.isAdmin || window.isTablet || window.isTablets
                    }, {
                        text: t('Theme settings'),
                        iconCls: 'x-fa fa-paint-brush',
                        handler: 'openSettings',
                        hidden: !App.user.isAdmin || window.isTablet || window.isTablets
                    }, {
                        text: t('About'),
                        iconCls: 'x-fa fa-info-circle',
                        handler: 'openAbout',
                        hidden: window.isTablets || App.user.l.slice(4, 7) == 'syn'
                    }, '-', {
                        iconCls: 'x-fa fa-sign-out text-danger',
                        text: t('Exit'),
                        handler: 'logout'
                    }]
                }]
            }]
        }, {
            reference: 'tabPanelMenu',
            region: 'west',
            width: window.isTablet ? '100%' : 240,
            minWidth: 150,
            cls: isMobileLayout ? 'mb-mobile-main-menu' : 'mb-main-menu-panel',
            bodyCls: isMobileLayout ? 'mb-mobile-main-menu-body' : 'mb-main-menu-panel-body',
            split: window.isTablet ? false : true,
            collapsible: !window.isTablets || window.isThemeNeptune ? true : false,
            titleCollapse: false,
            collapsed: false,
            layout: 'accordion',
            defaultType: 'treepanel',
            autoScroll: true,
            scrollable: true,
            title: t('Menu'),
            header: !window.isTablet ? true : false,
            defaults: {
                animFloat: true,
                border: window.isThemeNeptune ? false : true,
                cls: isMobileLayout ? 'mb-mobile-main-menu-item' : '',
                bodyCls: isMobileLayout ? 'mb-mobile-main-menu-item-body' : '',
                autoScroll: true,
                scrollable: true,
                rootVisible: false,
                viewConfig: {
                    cls: isMobileLayout ? 'mb-mobile-main-menu-view' : ''
                },
                listeners: {
                    itemclick: 'createTabStandard',
                    afterrender: 'enableMobileMenuTouchScroll',
                    expand: 'enableMobileMenuTouchScroll'
                }
            },
            listeners: {
                render: 'loadMenuStandard',
                afterrender: 'enableMobileMenuTouchScroll'
            }
        }, {
            xtype: 'tabpanel',
            region: 'center',
            reference: 'tabPanelCenter',
            scrollable: true,
            listeners: {
                tabchange: 'changeActivatedTab'
            },
            items: [{
                xtype: 'dashboardmodule',
                hidden: window.isTablet,
                glyph: icons.home,
                title: t('Home'),
                stateful: false,
                scrollable: 'y',
                autoScroll: true
            }]
        }];
        me.callParent(arguments);
    }
});
