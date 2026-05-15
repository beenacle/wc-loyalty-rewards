/* global Chart, wclrAnalytics */
(function () {
    'use strict';

    var charts = {};
    var i18n = (wclrAnalytics && wclrAnalytics.i18n) || {};

    function $(id) { return document.getElementById(id); }

    function fmtNumber(n) {
        if (n === null || n === undefined || isNaN(n)) { return '0'; }
        return Number(n).toLocaleString();
    }

    function setSpinner(active) {
        var s = $('wclr-analytics-spinner');
        if (!s) return;
        s.classList.toggle('is-active', !!active);
    }

    function getRange() {
        return {
            from: $('wclr-analytics-from').value,
            to: $('wclr-analytics-to').value,
            granularity: $('wclr-analytics-granularity').value
        };
    }

    function applyPreset(preset) {
        var to = new Date();
        var from = new Date();
        if (preset === 'ytd') {
            from = new Date(to.getFullYear(), 0, 1);
        } else {
            var days = parseInt(preset, 10);
            if (isNaN(days)) { return; }
            from.setDate(to.getDate() - (days - 1));
        }
        $('wclr-analytics-from').value = from.toISOString().slice(0, 10);
        $('wclr-analytics-to').value = to.toISOString().slice(0, 10);
        fetchData();
    }

    function renderKpis(k) {
        var cards = [
            { label: i18n.issued || 'Points issued', value: fmtNumber(k.issued) },
            { label: i18n.redeemed || 'Points redeemed', value: fmtNumber(k.redeemed) },
            { label: 'Redemption rate', value: k.redemption_rate_pct + '%' },
            { label: 'Active members', value: fmtNumber(k.active_members) },
            { label: 'Avg points / order', value: fmtNumber(k.avg_points_per_order) },
            { label: 'Outstanding balance', value: fmtNumber(k.total_balance) },
            { label: 'Liability value', value: (k.currency_symbol || '') + fmtNumber(k.liability_value) }
        ];
        var html = cards.map(function (c) {
            return '<div class="wclr-kpi"><div class="wclr-kpi__value">' + c.value +
                '</div><div class="wclr-kpi__label">' + c.label + '</div></div>';
        }).join('');
        $('wclr-analytics-kpis').innerHTML = html;
    }

    function destroyChart(key) {
        if (charts[key]) { charts[key].destroy(); delete charts[key]; }
    }

    function renderTimeseries(ts) {
        destroyChart('timeseries');
        var ctx = $('wclr-chart-timeseries').getContext('2d');
        charts.timeseries = new Chart(ctx, {
            type: 'line',
            data: {
                labels: ts.labels,
                datasets: [
                    {
                        label: i18n.issued || 'Issued',
                        data: ts.issued,
                        borderColor: '#2271b1',
                        backgroundColor: 'rgba(34,113,177,0.15)',
                        tension: 0.25,
                        fill: true
                    },
                    {
                        label: i18n.redeemed || 'Redeemed',
                        data: ts.redeemed,
                        borderColor: '#d63638',
                        backgroundColor: 'rgba(214,54,56,0.15)',
                        tension: 0.25,
                        fill: true
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
            }
        });
    }

    function renderContext(data) {
        destroyChart('context');
        var ctx = $('wclr-chart-context').getContext('2d');
        charts.context = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: data.labels,
                datasets: [{
                    label: i18n.issued || 'Issued',
                    data: data.values,
                    backgroundColor: '#2271b1'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: { legend: { display: false } },
                scales: { x: { beginAtZero: true, ticks: { precision: 0 } } }
            }
        });
    }

    function renderTiers(data) {
        destroyChart('tiers');
        var ctx = $('wclr-chart-tiers').getContext('2d');
        var palette = ['#8c8f94', '#c0c0c0', '#dba617', '#7c3aed', '#2271b1', '#00a32a', '#d63638'];
        charts.tiers = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: data.labels,
                datasets: [{
                    data: data.values,
                    backgroundColor: data.labels.map(function (_, i) { return palette[i % palette.length]; })
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } }
            }
        });
    }

    function renderEmpty(canvasId, msg) {
        var canvas = $(canvasId);
        if (!canvas) return;
        var ctx = canvas.getContext('2d');
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.fillStyle = '#646970';
        ctx.font = '14px -apple-system, BlinkMacSystemFont, sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText(msg, canvas.width / 2, canvas.height / 2);
    }

    function fetchData() {
        setSpinner(true);
        var range = getRange();
        var body = new FormData();
        body.append('action', 'wclr_analytics_fetch');
        body.append('nonce', wclrAnalytics.nonce);
        body.append('from', range.from);
        body.append('to', range.to);
        body.append('granularity', range.granularity);

        fetch(wclrAnalytics.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || !res.success) {
                    throw new Error((res && res.data && res.data.message) || 'error');
                }
                var d = res.data;
                renderKpis(d.kpis);

                if (d.timeseries.labels.length) {
                    renderTimeseries(d.timeseries);
                } else {
                    destroyChart('timeseries');
                    renderEmpty('wclr-chart-timeseries', i18n.noData || 'No data');
                }

                if (d.by_context.labels.length) {
                    renderContext(d.by_context);
                } else {
                    destroyChart('context');
                    renderEmpty('wclr-chart-context', i18n.noData || 'No data');
                }

                if (d.tiers.values.some(function (v) { return v > 0; })) {
                    renderTiers(d.tiers);
                } else {
                    destroyChart('tiers');
                    renderEmpty('wclr-chart-tiers', i18n.noData || 'No data');
                }

                var meta = $('wclr-analytics-meta');
                if (meta) {
                    meta.textContent = 'Range: ' + d.range.from + ' \u2192 ' + d.range.to +
                        ' \u00b7 Granularity: ' + d.range.granularity +
                        ' \u00b7 Generated: ' + d.generated_at;
                }
            })
            .catch(function () {
                var meta = $('wclr-analytics-meta');
                if (meta) { meta.textContent = i18n.error || 'Failed to load analytics.'; }
            })
            .finally(function () { setSpinner(false); });
    }

    document.addEventListener('DOMContentLoaded', function () {
        if (!$('wclr-analytics-kpis')) { return; }

        $('wclr-analytics-apply').addEventListener('click', fetchData);
        $('wclr-analytics-granularity').addEventListener('change', fetchData);

        var presets = document.querySelectorAll('.wclr-analytics-presets [data-preset]');
        presets.forEach(function (btn) {
            btn.addEventListener('click', function () { applyPreset(btn.getAttribute('data-preset')); });
        });

        fetchData();
    });
})();
