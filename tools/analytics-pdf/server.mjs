import http from 'node:http';
import { readFileSync, lstatSync } from 'node:fs';
import { createHmac, timingSafeEqual } from 'node:crypto';
import { chromium } from 'playwright';

// No navigation API: only signed HTML produced by our server. No public listener port is published.
const isolated = process.env.CRYPTO_BACKEND === 'vault';
if (!isolated && !['local', 'testing'].includes(process.env.APP_ENV)) throw new Error('Local PDF crypto requires an explicit local/testing environment');
const key = isolated ? '' : process.env.ANALYTICS_PDF_KEY || readFileSync(process.env.ANALYTICS_PDF_KEY_FILE || '/run/analytics-pdf-key/key', 'utf8').trim();
if (!isolated && !/^[a-f0-9]{64}$/.test(key)) throw new Error('Renderer key required');
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
async function verifySignature(html, signature) {
    if (!isolated) {
        const expected = createHmac('sha256', key).update(html).digest('hex');
        return typeof signature === 'string' && signature.length === expected.length && timingSafeEqual(Buffer.from(signature), Buffer.from(expected));
    }
    if (typeof signature !== 'string' || !/^vault:v[1-9][0-9]*:(?:[a-f0-9]{64}|[A-Za-z0-9+\/]{43}=)$/.test(signature)) return false;
    // Independent verify-only capability; renderer never receives an encryption/HMAC key.
    const endpoint = new URL(process.env.VAULT_PDF_VERIFY_URL);
    if (endpoint.protocol !== 'https:' || endpoint.username || endpoint.password || endpoint.search || endpoint.hash) throw new Error('Verifier configuration invalid');
    const file = process.env.VAULT_PDF_TOKEN_FILE;
    const stat = lstatSync(file);
    if (!stat.isFile() || ![0o400, 0o600].includes(stat.mode & 0o777) || stat.size < 1 || stat.size > 4096) throw new Error('Verifier capability invalid');
    const token = readFileSync(file, 'utf8').trim();
    if (/[\x00-\x20\x7f]/.test(token)) throw new Error('Verifier capability invalid');
    const response = await fetch(endpoint, {method: 'POST', redirect: 'error', signal: AbortSignal.timeout(5000),
        headers: {'Content-Type': 'application/json', 'X-Vault-Token': token},
        body: JSON.stringify({input: html.toString('base64'), hmac: signature, context: Buffer.from('vkposter:pdf').toString('base64')})});
    if (response.status !== 200) throw new Error('Verifier unavailable');
    if (!response.body) throw new Error('Verifier response missing');
    const reader = response.body.getReader();
    const chunks = []; let size = 0;
    for (;;) {
        const {value, done} = await reader.read();
        if (done) break;
        size += value.byteLength;
        if (size > 4096) { await reader.cancel(); throw new Error('Verifier response invalid'); }
        chunks.push(Buffer.from(value));
    }
    return JSON.parse(Buffer.concat(chunks).toString('utf8'))?.data?.valid === true;
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
        if (!await verifySignature(html, signature)) { res.writeHead(403).end(); return; }
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
