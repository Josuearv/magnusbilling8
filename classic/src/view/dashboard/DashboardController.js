Ext.define('MBilling.view.dashboard.DashboardController', {
    extend: 'Ext.app.ViewController',
    alias: 'controller.dashboard',
    onHideView: function() {
        //
    },
    onRenderModule: function() {
        var me = this;
        if (App && App.user && App.user.isAdmin) {
            storeStatusSystem = Ext.create('MBilling.store.StatusSystem');
            storeStatusSystem.load({
                scope: me,
                callback: function(record, operation, success) {
                    if (success && record && record.length && record[0]) {
                        me.onUpdateDashboardNetWork(record);
                    }
                }
            });
            setInterval(function() {
                storeStatusSystem.load({
                    scope: me,
                    callback: function(record, operation, success) {
                        if (success && record && record.length && record[0]) {
                            me.onUpdateDashboardNetWork(record);
                        }
                    }
                });
            }, 10000);

            storeTrunkChart = Ext.create('MBilling.store.TrunkChart');
            storeTrunkChart.load({
                scope: me,
                callback: function(record, operation, success) {
                    if (success && record && record.length && record[0]) {
                        me.onUpdateDashboardTrunk(record);
                    }
                }
            });
            setInterval(function() {
                storeTrunkChart.load({
                    scope: me,
                    callback: function(record, operation, success) {
                        if (success && record && record.length && record[0]) {
                            me.onUpdateDashboardTrunk(record);
                        }
                    }
                });
            }, 30000);
        }
    },
    onUpdateDashboardTrunk: function(record) {
        var me = this;
        if (!record || !record.length || !record[0] || !record[0].data) {
            return;
        }
        var fieldsRef = me.lookupReference('trunkDashboardFields');
        if (!fieldsRef || !fieldsRef.items || !fieldsRef.items.items) {
            return;
        }
        var sumsessionbill = record[0].data.sumsessionbill || 0,
            trunkDetails = '<div class="services-text">' + t('Trunk chart') + '</div><div class="services-legend">',
            avarege = 0;

        if (record[0] && record[0].data && fieldsRef.items.items[0] && fieldsRef.items.items[1]) {
            avarege = sumsessionbill > 0 ? (record[0].data.sessionbill * 100) / sumsessionbill : 0;
            fieldsRef.items.items[0].update('<div class="left-aligned-div">' + (record[0].data.idTrunktrunkcode || '') + '</div><div class="right-aligned-div">' + avarege.toFixed(2) + '%</div>');
            if (typeof fieldsRef.items.items[1].setValue === 'function') {
                fieldsRef.items.items[1].setValue(avarege / 100);
            }
            trunkDetails += '<span><div class="legend-trunk1"></div>' + (record[0].data.idTrunktrunkcode || '') + '</span>';
        }
        if (record[1] && record[1].data && fieldsRef.items.items[2] && fieldsRef.items.items[3]) {
            avarege = sumsessionbill > 0 ? (record[1].data.sessionbill * 100) / sumsessionbill : 0;
            fieldsRef.items.items[2].update('<div class="left-aligned-div">' + (record[1].data.idTrunktrunkcode || '') + '</div><div class="right-aligned-div">' + avarege.toFixed(2) + '%</div>');
            if (typeof fieldsRef.items.items[3].setValue === 'function') {
                fieldsRef.items.items[3].setValue(avarege / 100);
            }
            trunkDetails += '<span><div class="legend-trunk2"></div>' + (record[1].data.idTrunktrunkcode || '') + '</span>';
        }
        if (record[2] && record[2].data && fieldsRef.items.items[4] && fieldsRef.items.items[5]) {
            avarege = sumsessionbill > 0 ? (record[2].data.sessionbill * 100) / sumsessionbill : 0;
            fieldsRef.items.items[4].update('<div class="left-aligned-div">' + (record[2].data.idTrunktrunkcode || '') + '</div><div class="right-aligned-div">' + avarege.toFixed(2) + '%</div>');
            if (typeof fieldsRef.items.items[5].setValue === 'function') {
                fieldsRef.items.items[5].setValue(avarege / 100);
            }
            trunkDetails += '<span><div class="legend-trunk3"></div>' + (record[2].data.idTrunktrunkcode || '') + '</span>';
        }
        trunkDetails += '</div>';
        if (fieldsRef.items.items[6]) {
            fieldsRef.items.items[6].update(trunkDetails);
        }
    },
    onUpdateDashboardNetWork: function(record) {
        var me = this;
        if (!record || !record.length || !record[0] || !record[0].data) {
            return;
        }

        var data = record[0].data,
            cpuPercent = typeof data.cpuPercent === 'number' ? data.cpuPercent : (parseFloat(data.cpuPercent) || 0),
            cpuLoad = typeof data.cpuMediaUso === 'number' ? data.cpuMediaUso : (parseFloat(data.cpuMediaUso) || 0),
            diskPerc = typeof data.disk_perc === 'number' ? data.disk_perc : (parseFloat(data.disk_perc) || 0),
            diskFree = typeof data.disk_free === 'number' ? data.disk_free : (parseFloat(data.disk_free) || 0),
            memTotal = data.memTotal != null ? data.memTotal : 0,
            memUsed = data.memUsed != null ? data.memUsed : 0,
            uptime = data.uptime || '',
            totalUsers = data.totalActiveUsers != null ? data.totalActiveUsers : 0,
            maxCC = data.maximumcc != null ? data.maximumcc : 'CC 0 | CPS 0',
            currency = (App && App.user && App.user.currency) || '$',
            monthProfit = data.monthprofit != null ? data.monthprofit : '0.00',
            monthRefill = data.monthRefill != null ? data.monthRefill : '0.00',
            refEl;

        // CPU Usage
        refEl = me.lookupReference('cpuMediaUso');
        if (refEl) {
            refEl.update(
                '<div class="mb-hw-row">' +
                '<span class="mb-hw-label"><i class="x-fa fa-microchip text-primary"></i> ' + t('CPU actual usage') + '</span>' +
                '<span class="mb-hw-val">' + cpuPercent + '% <small class="text-muted">(' + t('Load') + ': ' + cpuLoad + '%)</small></span>' +
                '</div>'
            );
        }
        refEl = me.lookupReference('cpuPercent');
        if (refEl && typeof refEl.setValue === 'function') {
            refEl.setValue(cpuPercent / 100);
        }

        // Disk Usage
        refEl = me.lookupReference('diskFree');
        if (refEl) {
            refEl.update(
                '<div class="mb-hw-row">' +
                '<span class="mb-hw-label"><i class="x-fa fa-database text-info"></i> ' + t('Disk space') + '</span>' +
                '<span class="mb-hw-val">' + (diskPerc > 90 ? '<span class="mb-badge mb-badge-danger">' + diskPerc + '% ' + t('USED') + '</span> ' : '') + diskFree + ' GB ' + t('Free') + '</span>' +
                '</div>'
            );
        }
        refEl = me.lookupReference('diskPerc');
        if (refEl && typeof refEl.setValue === 'function') {
            refEl.setValue(diskPerc / 100);
        }

        // Memory & Uptime
        refEl = me.lookupReference('memTotal');
        if (refEl) {
            refEl.update(memTotal + ' GB');
        }
        refEl = me.lookupReference('memUsed');
        if (refEl) {
            refEl.update(memUsed + ' GB');
        }
        refEl = me.lookupReference('uptime');
        if (refEl) {
            refEl.update(uptime);
        }

        // Modern KPI Badges with vector FontAwesome icons and SaaS pastel badges
        refEl = me.lookupReference('totalusersdiv');
        if (refEl) {
            refEl.update(
                '<div class="weather-image-container kpi-badge-users">' +
                '<i class="x-fa fa-users weather-kpi-icon"></i>' +
                '</div>' +
                '<div class="weather-details-container">' +
                '<div class="weather-kpi-value">' + totalUsers + '</div>' +
                '<div class="weather-kpi-label">' + t('Active users') + '</div>' +
                '</div>'
            );
        }

        refEl = me.lookupReference('maximumcc');
        if (refEl) {
            refEl.update(
                '<div class="weather-image-container kpi-badge-calls">' +
                '<i class="x-fa fa-headphones weather-kpi-icon"></i>' +
                '</div>' +
                '<div class="weather-details-container">' +
                '<div class="weather-kpi-value">' + maxCC + '</div>' +
                '<div class="weather-kpi-label">' + t('Today peak') + '</div>' +
                '</div>'
            );
        }

        refEl = me.lookupReference('monthprofitdiv');
        if (refEl) {
            refEl.update(
                '<div class="weather-image-container kpi-badge-profit">' +
                '<i class="x-fa fa-line-chart weather-kpi-icon"></i>' +
                '</div>' +
                '<div class="weather-details-container">' +
                '<div class="weather-kpi-value">' + currency + ' ' + monthProfit + '</div>' +
                '<div class="weather-kpi-label">' + t('Month profit') + '</div>' +
                '</div>'
            );
        }

        refEl = me.lookupReference('totalrefill');
        if (refEl) {
            refEl.update(
                '<div class="weather-image-container kpi-badge-refill">' +
                '<i class="x-fa fa-credit-card weather-kpi-icon"></i>' +
                '</div>' +
                '<div class="weather-details-container">' +
                '<div class="weather-kpi-value">' + currency + ' ' + monthRefill + '</div>' +
                '<div class="weather-kpi-label">' + t('Month refill') + '</div>' +
                '</div>'
            );
        }
    }
});
