/**
 * ViewController for CDR & ASR Number Quality Analytics.
 */
Ext.define('MBilling.view.numberquality.Controller', {
    extend: 'Ext.app.ViewController',
    alias: 'controller.numberquality',

    onRenderModule: function () {
        var me = this;
        me.list = me.lookupReference('qualityList');
        me.detail = me.lookupReference('qualityDetail');
        me.banner = me.lookupReference('summaryBanner');
        me.store = me.list.getStore();
        me.activeTrunkId = 0;

        me.onFilterChange();
        me.loadSummary();
    },

    onDestroyModule: function () {
        var me = this;
        if (me.summaryReq) {
            Ext.Ajax.abort(me.summaryReq);
        }
        if (me.detailReq) {
            Ext.Ajax.abort(me.detailReq);
        }
    },

    onFilterChange: function () {
        var me = this;
        if (!me.store) {
            return;
        }
        var label = me.lookupReference('labelFilter').getValue();
        var minAtt = me.lookupReference('minAttemptsFilter').getValue();
        var query = Ext.String.trim(me.lookupReference('searchField').getValue() || '');

        var params = {};
        if (label) {
            params.quality_label = label;
        }
        if (minAtt !== null && minAtt !== '' && parseInt(minAtt, 10) > 0) {
            params.min_attempts = parseInt(minAtt, 10);
        }
        if (query !== '') {
            params.query = query;
        }
        if (me.activeTrunkId > 0) {
            params.id_trunk = me.activeTrunkId;
        }

        me.store.getProxy().setExtraParams(params);
        me.store.loadPage(1);
    },

    onSearchSpecialKey: function (field, e) {
        if (e.getKey() === e.ENTER) {
            this.onFilterChange();
        }
    },

    onClearFilters: function () {
        var me = this;
        me.activeTrunkId = 0;
        me.lookupReference('labelFilter').setValue('');
        me.lookupReference('minAttemptsFilter').setValue(0);
        me.lookupReference('searchField').setValue('');
        me.onFilterChange();
        me.loadSummary();
    },

    loadSummary: function () {
        var me = this;
        if (me.summaryReq) {
            Ext.Ajax.abort(me.summaryReq);
        }
        me.summaryReq = Ext.Ajax.request({
            url: 'index.php/numberquality/summary',
            method: 'GET',
            success: function (res) {
                var json = Ext.decode(res.responseText, true);
                if (json && json.success) {
                    me.renderSummaryBanner(json.kpis || {}, json.trunks || []);
                }
            }
        });
    },

    renderSummaryBanner: function (k, trunks) {
        var me = this;
        if (!me.banner) {
            return;
        }
        var html = '<div class="mb-nq-kpi-strip">' +
            me.kpiCard('Números Malos', k.bad_numbers || 0, '#dc2626', 'Score bajo / patrones nocivos') +
            me.kpiCard('Cortan <= 5s', k.bad_short_5s || 0, '#b91c1c', 'SDR <=5s >= 60%') +
            me.kpiCard('Siempre < 3m', k.bad_under_3min || 0, '#d97706', '0 llamadas >= 180s') +
            me.kpiCard('Posible Troncal', k.trunk_suspect || 0, '#6d28d9', 'Bueno en otra troncal') +
            me.kpiCard('ASR / SDR<=5s Global',
                parseFloat(k.global_asr_pct || 0).toFixed(1) + '% / ' + parseFloat(k.global_sdr_5s_pct || 0).toFixed(1) + '%',
                '#0f172a',
                (k.total_attempts || 0) + ' intentos analizados'
            ) +
            me.kpiCard('Costo en Malos', '$' + parseFloat(k.bad_buycost || 0).toFixed(2), '#334155',
                k.last_updated_at ? 'Act: ' + Ext.util.Format.htmlEncode(k.last_updated_at) : 'Sin sincronizar') +
            '</div>';

        if (trunks && trunks.length > 0) {
            html += '<div style="padding:5px 10px;background:#ffffff;border-bottom:1px solid #e2e8f0;font-size:11px;display:flex;gap:12px;flex-wrap:wrap;align-items:center;">' +
                '<span style="font-weight:700;color:#475569;">TRUNKS (30d):</span>';
            Ext.Array.each(trunks, function (tr) {
                var activeStyle = (me.activeTrunkId === parseInt(tr.id_trunk, 10)) ? 'border:1px solid #2563eb;background:#eff6ff;' : 'border:1px solid #e2e8f0;background:#f8fafc;';
                html += '<span style="' + activeStyle + 'border-radius:4px;padding:2px 7px;color:#1e293b;">' +
                    '<b>' + Ext.util.Format.htmlEncode(tr.trunkcode) + '</b>: ' +
                    'ASR ' + parseFloat(tr.asr_pct || 0).toFixed(1) + '% &middot; ' +
                    '<=5s ' + parseFloat(tr.sdr_5s_pct || 0).toFixed(1) + '% ' +
                    (parseInt(tr.suspect_numbers, 10) > 0 ? '(<span style="color:#6d28d9;font-weight:700;">' + tr.suspect_numbers + ' susp.</span>)' : '') +
                    '</span>';
            });
            html += '</div>';
        }

        me.banner.update(html);
    },

    kpiCard: function (title, value, color, sub) {
        return '<div class="mb-nq-kpi-card">' +
            '<div class="mb-nq-kpi-title">' + Ext.util.Format.htmlEncode(title) + '</div>' +
            '<div class="mb-nq-kpi-val" style="color:' + color + ';">' + Ext.util.Format.htmlEncode(String(value)) + '</div>' +
            '<div class="mb-nq-kpi-sub">' + Ext.util.Format.htmlEncode(String(sub || '')) + '</div>' +
            '</div>';
    },

    onSelectionChange: function (selModel, selected) {
        var me = this;
        var hasRec = selected && selected.length > 0;
        me.toggleActionButtons(hasRec);

        if (!hasRec) {
            me.selectedRecord = null;
            me.detail.setDetailData({ score: null, histogram: [], calls: [] });
            return;
        }

        me.selectedRecord = selected[0];
        if (me.detail && me.detail.collapsed) {
            me.detail.expand();
        }
        if (me.detailReq) {
            Ext.Ajax.abort(me.detailReq);
        }
        me.detail.setLoading(t('Loading...'));

        me.detailReq = Ext.Ajax.request({
            url: 'index.php/numberquality/detail',
            method: 'GET',
            params: {
                canonical_number: me.selectedRecord.get('canonical_number'),
                id_campaign: me.selectedRecord.get('id_campaign'),
                id_trunk: me.selectedRecord.get('id_trunk')
            },
            callback: function (opts, ok, res) {
                me.detail.setLoading(false);
                if (!ok) {
                    return;
                }
                var json = Ext.decode(res.responseText, true);
                if (json && json.success) {
                    var score = json.score || me.selectedRecord.getData();
                    if (!score.campaign_name) {
                        score.campaign_name = me.selectedRecord.get('idCampaignname') || 'General';
                    }
                    if (!score.trunk_code) {
                        score.trunk_code = me.selectedRecord.get('idTrunktrunkcode') || 'N/A';
                    }
                    me.detail.setDetailData({
                        score: score,
                        histogram: json.histogram || [],
                        calls: json.calls || []
                    });
                }
            }
        });
    },

    toggleActionButtons: function (enabled) {
        this.lookupReference('btnSuspendTemp').setDisabled(!enabled);
        this.lookupReference('btnRestrictPerm').setDisabled(!enabled);
        this.lookupReference('btnReactivate').setDisabled(!enabled);
    },

    onRecalculate: function () {
        var me = this;
        Ext.Msg.confirm(
            t('Recalculate Quality Analytics'),
            t('This runs incremental CDR sync and recomputes rolling 30-day scores. Continue?'),
            function (btn) {
                if (btn !== 'yes') {
                    return;
                }
                me.getView().setLoading(t('Calculating CDR analytics...'));
                Ext.Ajax.request({
                    url: 'index.php/numberquality/recalculate',
                    method: 'POST',
                    timeout: 120000,
                    callback: function (opts, ok, res) {
                        me.getView().setLoading(false);
                        var json = ok ? Ext.decode(res.responseText, true) : null;
                        if (json && json.success) {
                            Ext.Msg.alert(
                                t('Success'),
                                t('Sync complete') + ': ' + (json.scores_updated || 0) + ' ' + t('destinations scored') +
                                ' (' + (json.duration_sec || 0) + 's)'
                            );
                            me.onFilterChange();
                            me.loadSummary();
                        } else {
                            Ext.Msg.alert(t('Error'), (json && (json.msg || json.reason)) || t('Failed to recalculate analytics.'));
                        }
                    }
                });
            }
        );
    },

    onExportCsv: function () {
        var me = this;
        var params = Ext.Object.toQueryString(me.store.getProxy().getExtraParams() || {});
        var url = 'index.php/numberquality/csv' + (params ? '?' + params : '');
        window.open(url, '_blank');
    },

    onSuspendTemp: function () {
        this.applyOperatorAction('TEMP_SUSPEND', t('Confirm 24h pause for this number?'));
    },

    onRestrictPerm: function () {
        this.applyOperatorAction('RESTRICT_PERM', t('Confirm permanent restriction for this number?'));
    },

    onReactivate: function () {
        this.applyOperatorAction('REACTIVATE', t('Confirm reactivation for this number?'));
    },

    applyOperatorAction: function (actionType, confirmMsg) {
        var me = this;
        if (!me.selectedRecord) {
            return;
        }
        var num = me.selectedRecord.get('canonical_number');
        Ext.Msg.confirm(t('Confirm Action'), confirmMsg + ' (' + Ext.util.Format.htmlEncode(num) + ')', function (btn) {
            if (btn !== 'yes') {
                return;
            }
            me.detail.setLoading(t('Applying...'));
            Ext.Ajax.request({
                url: 'index.php/numberquality/applySuggestion',
                method: 'POST',
                params: {
                    id: me.selectedRecord.get('id'),
                    action_type: actionType,
                    hours: 24,
                    canonical_number: num,
                    id_campaign: me.selectedRecord.get('id_campaign'),
                    id_user: me.selectedRecord.get('id_user')
                },
                callback: function (opts, ok, res) {
                    me.detail.setLoading(false);
                    var json = ok ? Ext.decode(res.responseText, true) : null;
                    if (json && json.success) {
                        Ext.Msg.alert(t('Success'), json.msg || t('Action applied.'));
                        me.store.reload();
                    } else {
                        Ext.Msg.alert(t('Error'), (json && json.msg) || t('Failed to apply action.'));
                    }
                }
            });
        });
    }
});
