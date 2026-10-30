Ext.define('MBilling.view.dashboard.DashboardController', {
    extend: 'Ext.app.ViewController',
    alias: 'controller.dashboard',
    onHideView: function() {
        //
    },
    onRenderModule: function() {
        var me = this;
        if (App.user.isAdmin) {
            storeStatusSystem = Ext.create('MBilling.store.StatusSystem');
            storeStatusSystem.load({
                scope: me,
                callback: function(record) {
                    me.onUpdateDashboardNetWork(record)
                }
            });
            setInterval(function() {
                storeStatusSystem.load({
                    scope: me,
                    callback: function(record) {
                        me.onUpdateDashboardNetWork(record)
                    }
                });
            }, 10000);
            storeTrunkChart = Ext.create('MBilling.store.TrunkChart');
            storeTrunkChart.load({
                scope: me,
                callback: function(record) {
                    if (record[0]) {
                        me.onUpdateDashboardTrunk(record)
                    }
                }
            });
            setInterval(function() {
                storeTrunkChart.load({
                    scope: me,
                    callback: function(record) {
                        me.onUpdateDashboardTrunk(record)
                    }
                });
            }, 30000);
        }
    },
    onUpdateDashboardTrunk: function(record) {
        var me = this;
        trunkDetails = '<div class="services-text">' + t('Trunk chart') + '</div>' + '<div class="services-legend">';
        if (record[0]) {
            avarege = (record[0].data.sessionbill * 100) / record[0].data.sumsessionbill;
            me.lookupReference('trunkDashboardFields').items.items[0].update('<div class="left-aligned-div">' + record[0].data.idTrunktrunkcode + '</div><div class="right-aligned-div">' + avarege.toFixed(2) + '%</div>');
            me.lookupReference('trunkDashboardFields').items.items[1].setValue(avarege / 100);
            trunkDetails += '<span><div class="legend-trunk1"></div>' + record[0].data.idTrunktrunkcode + '</span>';
        }
        if (record[1]) {
            avarege = (record[1].data.sessionbill * 100) / record[0].data.sumsessionbill;
            me.lookupReference('trunkDashboardFields').items.items[2].update('<div class="left-aligned-div">' + record[1].data.idTrunktrunkcode + '</div><div class="right-aligned-div">' + avarege.toFixed(2) + '%</div>');
            me.lookupReference('trunkDashboardFields').items.items[3].setValue(avarege / 100);
            trunkDetails += '<span><div class="legend-trunk2"></div>' + record[1].data.idTrunktrunkcode + '</span>';
        }
        if (record[2]) {
            avarege = (record[2].data.sessionbill * 100) / record[0].data.sumsessionbill;
            me.lookupReference('trunkDashboardFields').items.items[4].update('<div class="left-aligned-div">' + record[2].data.idTrunktrunkcode + '</div><div class="right-aligned-div">' + avarege.toFixed(2) + '%</div>');
            me.lookupReference('trunkDashboardFields').items.items[5].setValue(avarege / 100);
            trunkDetails += '<span><div class="legend-trunk3"></div>' + record[2].data.idTrunktrunkcode + '</span>';
        }
        trunkDetails += '<div>';
        me.lookupReference('trunkDashboardFields').items.items[6].update(trunkDetails);
    },
    onUpdateDashboardNetWork: function(record) {
        var me = this,
            cpuPercent = (record && record[0] && record[0].data.cpuPercent) || 0,
            cpuLoad = (record && record[0] && record[0].data.cpuMediaUso) || 0,
            diskPerc = (record && record[0] && record[0].data.disk_perc) || 0,
            diskFree = (record && record[0] && record[0].data.disk_free) || 0,
            userstpl;

        // CPU Usage
        var cpuUsageHtml = '<div class="mb-hw-row">' +
            '<span class="mb-hw-label"><i class="x-fa fa-microchip text-primary"></i> ' + t('CPU actual usage') + '</span>' +
            '<span class="mb-hw-val">' + cpuPercent + '% <small class="text-muted">(' + t('Load') + ': ' + cpuLoad + '%)</small></span>' +
            '</div>';
        me.lookupReference('cpuMediaUso').update(cpuUsageHtml);
        me.lookupReference('cpuPercent').setValue(cpuPercent / 100);

        // Disk Usage
        var diskUsageHtml = '<div class="mb-hw-row">' +
            '<span class="mb-hw-label"><i class="x-fa fa-database text-info"></i> ' + t('Disk space') + '</span>' +
            '<span class="mb-hw-val">' + (diskPerc > 90 ? '<span class="mb-badge mb-badge-danger">' + diskPerc + '% ' + t('USED') + '</span> ' : '') + diskFree + ' GB ' + t('Free') + '</span>' +
            '</div>';
        me.lookupReference('diskFree').update(diskUsageHtml);
        me.lookupReference('diskPerc').setValue(diskPerc / 100);

        // Memory & Uptime
        me.lookupReference('memTotal').update(record[0].data.memTotal + ' GB');
        me.lookupReference('memUsed').update(record[0].data.memUsed + ' GB');
        me.lookupReference('uptime').update(record[0].data.uptime);

        // Modern KPI Badges with vector FontAwesome icons and SaaS pastel badges
        userstpl = '<div class="weather-image-container kpi-badge-users">' +
            '<i class="x-fa fa-users weather-kpi-icon"></i>' +
            '</div>' +
            '<div class="weather-details-container">' +
            '<div class="weather-kpi-value">' + (record[0].data.totalActiveUsers || 0) + '</div>' +
            '<div class="weather-kpi-label">' + t('Active users') + '</div>' +
            '</div>';
        me.lookupReference('totalusersdiv').update(userstpl);

        userstpl = '<div class="weather-image-container kpi-badge-calls">' +
            '<i class="x-fa fa-headphones weather-kpi-icon"></i>' +
            '</div>' +
            '<div class="weather-details-container">' +
            '<div class="weather-kpi-value">' + (record[0].data.maximumcc || 0) + '</div>' +
            '<div class="weather-kpi-label">' + t('Today peak') + '</div>' +
            '</div>';
        me.lookupReference('maximumcc').update(userstpl);

        userstpl = '<div class="weather-image-container kpi-badge-profit">' +
            '<i class="x-fa fa-line-chart weather-kpi-icon"></i>' +
            '</div>' +
            '<div class="weather-details-container">' +
            '<div class="weather-kpi-value">' + App.user.currency + ' ' + (record[0].data.monthprofit || '0.00') + '</div>' +
            '<div class="weather-kpi-label">' + t('Month profit') + '</div>' +
            '</div>';
        me.lookupReference('monthprofitdiv').update(userstpl);

        userstpl = '<div class="weather-image-container kpi-badge-refill">' +
            '<i class="x-fa fa-credit-card weather-kpi-icon"></i>' +
            '</div>' +
            '<div class="weather-details-container">' +
            '<div class="weather-kpi-value">' + App.user.currency + ' ' + (record[0].data.monthRefill || '0.00') + '</div>' +
            '<div class="weather-kpi-label">' + t('Month refill') + '</div>' +
            '</div>';
        me.lookupReference('totalrefill').update(userstpl);
    }
});
