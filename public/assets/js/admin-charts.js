// Admin dashboard charts. Every <canvas data-chart="{json}"> is drawn with Chart.js (vendored, no CDN); colours come from the design tokens,
// so the charts follow the theme (and the owner's custom colours). Each chart also gets its numbers as a table inside <details>, which is what
// screen readers use (the canvas itself carries only a label).
(function () {
    if (typeof Chart === 'undefined') {
        return;
    }
    var style = getComputedStyle(document.documentElement);

    function token(name) {
        var value = style.getPropertyValue('--' + name).trim();
        return value ? 'rgb(' + value + ')' : '#888';
    }

    var roles = { p: token('p'), ok: token('ok'), bad: token('bad'), info: token('info'), warn: token('warn'), muted: token('muted') };
    var palette = [roles.p, roles.info, roles.ok, roles.warn, roles.bad, roles.muted];

    function formatter(config) {
        if (config.format === 'money') {
            var money = new Intl.NumberFormat('ru-RU', { style: 'currency', currency: config.currency || 'RUB', maximumFractionDigits: 0 });
            return function (value) { return money.format(value / 100); };
        }
        var int = new Intl.NumberFormat('ru-RU');
        return function (value) { return int.format(value); };
    }

    function table(config, format) {
        var details = document.createElement('details');
        details.className = 'mt-3 text-sm';
        var summary = document.createElement('summary');
        summary.className = 'cursor-pointer text-muted';
        summary.textContent = 'Данные таблицей';
        details.appendChild(summary);
        var wrap = document.createElement('div');
        wrap.className = 'mt-2 max-h-72 overflow-auto';
        var t = document.createElement('table');
        t.className = 'tbl';
        var head = t.createTHead().insertRow();
        var corner = document.createElement('th');
        corner.scope = 'col';
        corner.textContent = config.type === 'doughnut' ? 'Значение' : 'Период';
        head.appendChild(corner);
        config.datasets.forEach(function (set) {
            var th = document.createElement('th');
            th.scope = 'col';
            th.textContent = set.label;
            head.appendChild(th);
        });
        var body = t.createTBody();
        config.labels.forEach(function (label, i) {
            var row = body.insertRow();
            var th = document.createElement('th');
            th.scope = 'row';
            th.textContent = label;
            row.appendChild(th);
            config.datasets.forEach(function (set) {
                row.insertCell().textContent = format(set.data[i] || 0);
            });
        });
        wrap.appendChild(t);
        details.appendChild(wrap);
        return details;
    }

    document.querySelectorAll('canvas[data-chart]').forEach(function (canvas) {
        var config;
        try {
            config = JSON.parse(canvas.getAttribute('data-chart'));
        } catch (e) {
            return;
        }
        var format = formatter(config);
        var doughnut = config.type === 'doughnut';
        var datasets = config.datasets.map(function (set, i) {
            var color = roles[set.role] || palette[i % palette.length];
            if (doughnut) {
                return { label: set.label, data: set.data, backgroundColor: config.labels.map(function (_, j) { return palette[j % palette.length]; }), borderColor: token('surface') };
            }
            return {
                label: set.label,
                data: set.data,
                backgroundColor: config.type === 'line' ? color : color,
                borderColor: color,
                borderWidth: config.type === 'line' ? 2 : 0,
                pointRadius: 0,
                tension: 0.25,
                fill: false,
                stack: config.stacked ? 'a' : undefined
            };
        });
        var grid = token('line');
        var text = token('muted');
        new Chart(canvas, {
            type: config.type,
            data: { labels: config.labels, datasets: datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'bottom', labels: { color: text, boxWidth: 12 } },
                    tooltip: { callbacks: { label: function (ctx) { return ctx.dataset.label + ': ' + format(ctx.parsed.y !== undefined ? ctx.parsed.y : ctx.parsed); } } }
                },
                scales: doughnut ? {} : {
                    x: { stacked: !!config.stacked, ticks: { color: text, maxRotation: 0, autoSkip: true }, grid: { display: false } },
                    y: { stacked: !!config.stacked, beginAtZero: true, ticks: { color: text, callback: function (v) { return format(v); } }, grid: { color: grid } }
                }
            }
        });
        var figure = canvas.closest('figure');
        if (figure) {
            figure.appendChild(table(config, format));
        }
    });
})();
