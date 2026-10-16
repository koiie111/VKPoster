// Accessible HTML tables remain the source of truth when canvas or JavaScript is unavailable.
(function () {
    function draw() {
        document.querySelectorAll('[data-analytics-chart]').forEach(function (canvas) {
            if (canvas.dataset.drawn || typeof Chart === 'undefined') return;
            var series = JSON.parse(canvas.dataset.series || '{}');
            canvas.dataset.drawn = '1';
            new Chart(canvas, {type: 'line', data: {labels: Object.keys(series), datasets: [
                {label: 'Охват', data: Object.values(series).map(function (v) { return v.reach; }), borderColor: '#6366f1', spanGaps: false},
                {label: 'Вовлечённость', data: Object.values(series).map(function (v) { return v.engagement; }), borderColor: '#059669', spanGaps: false}
            ]}, options: {responsive: true, maintainAspectRatio: false, animation: false, scales: {y: {beginAtZero: true}}}});
        });
    }
    document.addEventListener('DOMContentLoaded', draw);
    document.addEventListener('htmx:afterSettle', draw);
}());
