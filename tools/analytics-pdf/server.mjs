import http from 'node:http';
import { readFileSync } from 'node:fs';
import { createHmac, timingSafeEqual } from 'node:crypto';
import { chromium } from 'playwright';

// No navigation API: only signed HTML produced by our server. No public listener port is published.
const key = process.env.ANALYTICS_PDF_KEY || readFileSync(process.env.ANALYTICS_PDF_KEY_FILE || '/run/analytics-pdf-key/key', 'utf8').trim();
if (!/^[a-f0-9]{64}$/.test(key)) throw new Error('Renderer key required');
const browser = await chromium.launch({ headless: true, handleSIGTERM: false, handleSIGINT: false });
let stopping = false;
browser.on('disconnected', () => { if (!stopping) process.exit(1); });
for (const signal of ['SIGTERM', 'SIGINT']) {
    process.once(signal, async () => {
        stopping = true;
        const watchdog = setTimeout(() => process.exit(0), 5000);
        watchdog.unref();
        server.close();
        try { await browser.close(); } finally { process.exit(0); }
    });
}
let active = 0;
const server = http.createServer(async (req, res) => {
    req.setTimeout(15000, () => req.destroy());
    if (req.method !== 'POST' || req.url !== '/pdf') { res.writeHead(404).end(); return; }
    if (active >= 2) { res.writeHead(429, {'Retry-After': '10'}).end(); return; }
    ++active;
    let context;
    try {
        const chunks = []; let length = 0;
        for await (const chunk of req) {
            length += chunk.length;
            if (length > 2 * 1024 * 1024) { res.writeHead(413).end(); return; }
            chunks.push(chunk);
        }
        const html = Buffer.concat(chunks);
        const signature = req.headers['x-report-signature'];
        const expected = createHmac('sha256', key).update(html).digest('hex');
        if (typeof signature !== 'string' || signature.length !== expected.length || !timingSafeEqual(Buffer.from(signature), Buffer.from(expected))) { res.writeHead(403).end(); return; }
        context = await browser.newContext({ javaScriptEnabled: false, serviceWorkers: 'block', offline: true });
        await context.route('**/*', route => route.abort());
        const page = await context.newPage();
        await page.setContent(html.toString('utf8'), {timeout: 10000, waitUntil: 'domcontentloaded'});
        const pdf = await page.pdf({format: 'A4', timeout: 20000, margin: {top: '15mm', bottom: '15mm', left: '12mm', right: '12mm'}});
        res.writeHead(200, {'Content-Type': 'application/pdf', 'Cache-Control': 'no-store'}).end(pdf);
    } catch { if (!res.headersSent) res.writeHead(503).end(); }
    finally {
        try { await context?.close(); } catch { /* Browser shutdown also closes in-flight contexts. */ }
        --active;
    }
}).listen(3000, '0.0.0.0');
