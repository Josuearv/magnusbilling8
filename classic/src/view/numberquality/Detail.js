/**
 * Drilldown panel showing duration histogram, pattern stats, and recent CDRs
 * for a selected destination number, plus explicit operator approval actions.
 */
Ext.define('MBilling.view.numberquality.Detail', {
    extend: 'Ext.panel.Panel',
    alias: 'widget.numberqualitydetail',
    title: t('Destination Drilldown'),
    scrollable: true,
    bodyStyle: 'background:#ffffff;',

    initComponent: function () {
        var me = this;

        me.tbar = [{
            text: t('Pause 24h'),
            reference: 'btnSuspendTemp',
            iconCls: 'x-fa fa-pause',
            disabled: true,
            handler: 'onSuspendTemp'
        }, {
            text: t('Restrict'),
            reference: 'btnRestrictPerm',
            iconCls: 'x-fa fa-ban',
            disabled: true,
            handler: 'onRestrictPerm'
        }, {
            text: t('Reactivate'),
            reference: 'btnReactivate',
            iconCls: 'x-fa fa-check',
            disabled: true,
            handler: 'onReactivate'
        }];

        me.tpl = new Ext.XTemplate(
            '<div class="mb-nq-detail-wrap">',
            '<tpl if="!score">',
            '  <div style="color:#64748b;padding:24px 8px;text-align:center;">',
            '    ' + t('Select a destination row from the grid to inspect its duration distribution and call history.'),
            '  </div>',
            '</tpl>',
            '<tpl if="score">',
            '  <div style="border-bottom:1px solid #e2e8f0;padding-bottom:10px;margin-bottom:10px;">',
            '    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">',
            '      <div style="font-size:17px;font-weight:700;color:#0f172a;">{score.canonical_number:htmlEncode}</div>',
            '      <span class="mb-nq-pill {[this.pillClass(values.score.quality_label)]}">{score.quality_label:htmlEncode}</span>',
            '    </div>',
            '    <div style="font-size:12px;color:#475569;margin-top:4px;">{score.reason_summary:htmlEncode}</div>',
            '    <div style="font-size:11px;color:#64748b;margin-top:6px;">',
            '      Score: <b>{score.quality_score}</b>/100 &middot; ',
            '      Confianza: <b>{[Math.round((values.score.confidence || 0) * 100)]}%</b> &middot; ',
            '      ACD: <b>{score.acd_sec}s</b> &middot; StdDev: <b>{score.duration_stddev}s</b> &middot; CV: <b>{score.duration_cv}</b>',
            '    </div>',
            '    <div style="font-size:11px;color:#64748b;margin-top:2px;">',
            '      Campaña: <b>{score.campaign_name:htmlEncode}</b> &middot; Troncal: <b>{score.trunk_code:htmlEncode}</b>',
            '    </div>',
            '  </div>',
            '  <div style="margin-bottom:12px;">',
            '    <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:#475569;margin-bottom:6px;">',
            '      ' + t('Answered Duration Distribution') + ' ({score.answered} ' + t('answered') + ')',
            '    </div>',
            '    <tpl for="histogram">',
            '      <div class="mb-nq-hist-row">',
            '        <div class="mb-nq-hist-lbl">{bucket:htmlEncode}</div>',
            '        <div class="mb-nq-hist-bar-bg">',
            '          <div class="mb-nq-hist-bar-fill" style="width:{pct}%;background:{[this.barColor(values.bucket)]};"></div>',
            '        </div>',
            '        <div style="width:74px;text-align:right;color:#334155;">{count} ({pct}%)</div>',
            '      </div>',
            '    </tpl>',
            '  </div>',
            '  <div>',
            '    <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:#475569;margin-bottom:4px;">',
            '      ' + t('Recent Call Attempts (max 50)') + '',
            '    </div>',
            '    <table class="mb-nq-call-table">',
            '      <thead><tr>',
            '        <th>' + t('Date') + '</th>',
            '        <th>' + t('Trunk') + '</th>',
            '        <th>' + t('Duration') + '</th>',
            '        <th>' + t('Result / Cause') + '</th>',
            '      </tr></thead>',
            '      <tbody>',
            '      <tpl for="calls">',
            '        <tr>',
            '          <td>{starttime:htmlEncode}</td>',
            '          <td>{trunkcode:htmlEncode}</td>',
            '          <td><tpl if="call_type === \'ANSWERED\'"><b>{duration_sec}s</b><tpl else>-</tpl></td>',
            '          <td>',
            '            <tpl if="call_type === \'ANSWERED\'">',
            '              <span style="color:#16a34a;font-weight:600;">{cause_label:htmlEncode}</span>',
            '            <tpl else>',
            '              <span style="color:#dc2626;font-weight:600;">{cause_label:htmlEncode}</span>',
            '            </tpl>',
            '          </td>',
            '        </tr>',
            '      </tpl>',
            '      <tpl if="calls.length === 0">',
            '        <tr><td colspan="4" style="color:#94a3b8;text-align:center;padding:12px;">' + t('No recent call details in window.') + '</td></tr>',
            '      </tpl>',
            '      </tbody>',
            '    </table>',
            '  </div>',
            '</tpl>',
            '</div>',
            {
                pillClass: function (label) {
                    if (label === 'HEALTHY') { return 'mb-nq-pill-good'; }
                    if (label === 'TRUNK_SUSPECT') { return 'mb-nq-pill-trunk'; }
                    if (label === 'WATCHLIST') { return 'mb-nq-pill-warn'; }
                    if (label === 'INSUFFICIENT_DATA') { return 'mb-nq-pill-muted'; }
                    return 'mb-nq-pill-bad';
                },
                barColor: function (bucket) {
                    if (bucket === '0-5s') { return '#ef4444'; }
                    if (bucket === '6-30s') { return '#f59e0b'; }
                    if (bucket === '31-179s') { return '#3b82f6'; }
                    return '#16a34a';
                }
            }
        );

        me.data = { score: null, histogram: [], calls: [] };
        me.callParent(arguments);
    },

    setDetailData: function (payload) {
        this.update(payload || { score: null, histogram: [], calls: [] });
    }
});
