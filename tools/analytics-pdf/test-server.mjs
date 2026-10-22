// Production server, real Chromium and real process signals; only synthetic keys and local HTTP.
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { createHmac } from 'node:crypto';
import { readFileSync, writeFileSync, unlinkSync } from 'node:fs';
import { setTimeout as sleep } from 'node:timers/promises';

assert.equal(process.env.APP_KEY, undefined, 'Master key must not reach the renderer image');
const master = 'synthetic-pdf-contract';
const key = createHmac('sha256', master).update('analytics-pdf-v1').digest('hex');
function launch(overrides = {}) {
    const child = spawn(process.execPath, ['server.mjs'], {
        env: { ...process.env, APP_ENV: 'testing', ANALYTICS_PDF_KEY: key, ...overrides },
        stdio: ['ignore', 'ignore', 'pipe'],
    });
    child.stderr.resume(); // Do not log server environment, request HTML or secrets.
    child.done = new Promise(resolve => child.once('exit', (code, signal) => resolve({ code, signal })));
    return child;
}
async function stopped(child) {
    const result = await Promise.race([child.done, sleep(15000).then(() => null)]);
    if (result === null) { child.kill('SIGKILL'); throw new Error('Server did not stop'); }
    return result;
}
async function request(body, signingKey = null) {
    const headers = { 'Content-Type': 'text/html' };
    if (signingKey !== null) headers['X-Report-Signature'] = createHmac('sha256', signingKey).update(body).digest('hex');
    return fetch('http://127.0.0.1:3000/pdf', {
        method: 'POST', body, headers, signal: AbortSignal.timeout(40000),
    });
}
async function ready(child) {
    for (let i = 0; i < 100; ++i) {
        if (child.exitCode !== null) throw new Error('Server exited before ready');
        try { if ((await request('ready')).status === 403) return; } catch { /* startup socket not ready */ }
        await sleep(100);
    }
    throw new Error('Server not ready');
}
const absent = launch({ ANALYTICS_PDF_KEY: '', ANALYTICS_PDF_KEY_FILE: '/nonexistent-test-key' });
assert.notEqual((await stopped(absent)).code, 0);
const keyFile = '/tmp/analytics-pdf-test-key';
writeFileSync(keyFile, key, { mode: 0o600 });
let child = launch({ ANALYTICS_PDF_KEY: '', ANALYTICS_PDF_KEY_FILE: keyFile });
try {
    await ready(child);
    assert.equal((await request('<p>test</p>', 'wrong-key')).status, 403);
    assert.equal((await request('<p>test</p>', master)).status, 403);
    const response = await request('<h1>Derived key works</h1>', key);
    assert.equal(response.status, 200);
    assert.ok(Buffer.from(await response.arrayBuffer()).subarray(0, 5).equals(Buffer.from('%PDF-')));
    child.kill('SIGTERM');
    assert.equal((await stopped(child)).code, 0);
    child = launch();
    await ready(child);
    const children = readFileSync(`/proc/${child.pid}/task/${child.pid}/children`, 'utf8').trim().split(/\s+/);
    const chrome = children.find(pid => readFileSync(`/proc/${pid}/cmdline`, 'utf8').includes('chrome'));
    assert.ok(chrome, 'Real Chromium process is a server child');
    process.kill(Number(chrome), 'SIGKILL');
    assert.equal((await stopped(child)).code, 1, 'Unexpected browser disconnect must be restartable');
    console.log('Renderer security/lifecycle: derived key, wrong/missing key, SIGTERM=0, browser crash=1 passed');
} finally {
    if (child.exitCode === null) child.kill('SIGKILL');
    unlinkSync(keyFile);
}
