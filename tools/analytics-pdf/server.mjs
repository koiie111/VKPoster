import http from 'node:http';
import { createHmac, timingSafeEqual } from 'node:crypto';
import { chromium } from 'playwright';

// No navigation API: only signed HTML produced by our server. No public listener port is published.
const key = process.env.APP_KEY || '';
if (!key) throw new Error('Renderer key required');
const browser = await chromium.launch({ headless: true });
let active = 0;
http.createServer(async (req, res) => {
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
    finally { await context?.close(); --active; }
}).listen(3000, '0.0.0.0');
