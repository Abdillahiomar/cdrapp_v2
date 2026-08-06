{{--
    ÉTAPE 2 — Graphique narratif principal.
    Partial incluse dans dashboard.blade.php.
    Variables attendues :
    - $serieJours   : array de labels (dates 'Y-m-d')
    - $serieVolume  : array de volumes (Completed) par jour
    - $serieTxn     : array de nb transactions (tous statuts) par jour
    - $periode      : période courante (pour la clé wire)
--}}
<div style="background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:20px; margin-top:20px;"
     wire:key="mainchart-{{ $periode }}">

    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:6px;">
        <div>
            <p style="font-size:13px; font-weight:700; color:#111827; margin:0;">Activité & Volume</p>
            <p style="font-size:11px; color:#9ca3af; margin:3px 0 0;">
                Transactions (tous statuts) et volume abouti, par jour
            </p>
        </div>
        <div style="display:flex; gap:14px; font-size:11px;">
            <span style="display:flex; align-items:center; gap:5px; color:#6b7280;">
                <span style="width:10px; height:10px; border-radius:2px; background:#378ADD; display:inline-block;"></span>
                Transactions
            </span>
            <span style="display:flex; align-items:center; gap:5px; color:#6b7280;">
                <span style="width:10px; height:10px; border-radius:2px; background:#1B2F6E; display:inline-block;"></span>
                Volume (DJF)
            </span>
        </div>
    </div>

    @if(count($serieJours) < 1)
        <div style="text-align:center; padding:50px 20px; color:#9ca3af; font-size:13px;">
            Aucune donnée sur cette période.
        </div>
    @else
        <div wire:ignore style="margin-top:8px;">
            <div id="main-activity-chart"></div>
        </div>
        <script>
            (function () {
                const el = document.querySelector('#main-activity-chart');
                if (!el) return;
                if (el.__chart) { el.__chart.destroy(); }

                const options = {
                    chart: {
                        height: 320,
                        type: 'area',
                        toolbar: { show: false },
                        animations: { enabled: true, easing: 'easeinout', speed: 500 },
                        fontFamily: 'inherit',
                    },
                    series: [
                        { name: 'Transactions', type: 'area', data: @json($serieTxn) },
                        { name: 'Volume (DJF)',  type: 'area', data: @json($serieVolume) },
                    ],
                    colors: ['#378ADD', '#1B2F6E'],
                    dataLabels: { enabled: false },
                    stroke: { curve: 'smooth', width: 2 },
                    fill: {
                        type: 'gradient',
                        gradient: { opacityFrom: 0.30, opacityTo: 0.02, stops: [0, 95] },
                    },
                    xaxis: {
                        categories: @json($serieJours),
                        labels: {
                            style: { colors: '#9ca3af', fontSize: '10px' },
                            rotate: -35,
                            rotateAlways: false,
                            hideOverlappingLabels: true,
                            formatter: function (val) {
                                if (!val) return '';
                                const p = String(val).split('-');
                                return p.length === 3 ? p[2] + '/' + p[1] : val;
                            },
                        },
                        axisBorder: { show: false },
                        axisTicks: { show: false },
                        tooltip: { enabled: false },
                    },
                    yaxis: [
                        {
                            seriesName: 'Transactions',
                            labels: {
                                style: { colors: '#378ADD', fontSize: '10px' },
                                formatter: (v) => Intl.NumberFormat('fr-FR', { notation: 'compact' }).format(v),
                            },
                            title: { text: 'Transactions', style: { color: '#378ADD', fontSize: '10px', fontWeight: 600 } },
                        },
                        {
                            seriesName: 'Volume (DJF)',
                            opposite: true,
                            labels: {
                                style: { colors: '#1B2F6E', fontSize: '10px' },
                                formatter: (v) => Intl.NumberFormat('fr-FR', { notation: 'compact' }).format(v),
                            },
                            title: { text: 'Volume (DJF)', style: { color: '#1B2F6E', fontSize: '10px', fontWeight: 600 } },
                        },
                    ],
                    grid: { borderColor: '#f1f3f7', strokeDashArray: 4 },
                    legend: { show: false },
                    tooltip: {
                        shared: true,
                        intersect: false,
                        x: {
                            formatter: function (idx, opts) {
                                const cats = opts.w.globals.categoryLabels || @json($serieJours);
                                const raw = @json($serieJours)[opts.dataPointIndex] || '';
                                if (!raw) return '';
                                const p = String(raw).split('-');
                                return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : raw;
                            },
                        },
                        y: {
                            formatter: (v) => Intl.NumberFormat('fr-FR').format(v),
                        },
                    },
                };

                el.__chart = new ApexCharts(el, options);
                el.__chart.render();
            })();
        </script>
    @endif
</div>