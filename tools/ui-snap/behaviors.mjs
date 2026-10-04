// Behavior smoke test of the design-system components in a real browser (Alpine.data components, dialogs,
// theme, editor preview). Fails on any CSP violation or console error. Usage: node behaviors.mjs
import { chromium } from 'playwright';
import { deflateSync } from 'node:zlib';

const base = process.env.UI_SNAP_BASE_URL ?? 'http://nginx';
const browser = await chromium.launch();
const failures = [];
const check = (ok, msg) => { if (!ok) { failures.push(msg); console.log('FAIL', msg); } else { console.log('ok  ', msg); } };

async function open(path, viewport = { width: 1280, height: 900 }) {
  const ctx = await browser.newContext({ viewport });
  const page = await ctx.newPage();
  page.on('console', (m) => { if (m.type() === 'error' && !m.text().includes('Cross-Origin-Opener-Policy') && !m.text().includes('status of 422')) { failures.push(`console error on ${path}: ${m.text()}`); console.log('FAIL console', m.text()); } });
  page.on('pageerror', (e) => { failures.push(`page error on ${path}: ${e.message}`); console.log('FAIL pageerror', e.message); });
  await page.goto(base + path, { waitUntil: 'networkidle' });
  return page;
}

// ---- showcase ----
let page = await open('/dev/ui');

await page.locator('[data-theme-toggle]:visible').click();
check((await page.getAttribute('html', 'data-theme')) === 'dark', 'theme toggle switches to dark');
await page.reload({ waitUntil: 'networkidle' });
check((await page.getAttribute('html', 'data-theme')) === 'dark', 'dark theme survives reload');
await page.locator('[data-theme-set="system"]:visible').click();
check((await page.getAttribute('html', 'data-theme')) === null, 'theme "system" removes the override');

const trigger = page.locator('#s-nav ~ div [data-dropdown-trigger]').first();
await trigger.click();
check(await page.locator('#demo-menu').isVisible(), 'dropdown opens on click');
await page.keyboard.press('ArrowDown');
check(await page.evaluate(() => document.activeElement?.getAttribute('role')) === 'menuitem', 'dropdown: arrow focuses a menu item');
await page.keyboard.press('Escape');
check(!(await page.locator('#demo-menu').isVisible()), 'dropdown closes on Escape');
check(await page.evaluate(() => document.activeElement?.hasAttribute('data-dropdown-trigger')), 'dropdown returns focus to the trigger');

await page.click('#demo-tab-b');
check(await page.locator('#demo-panel-b').isVisible() && !(await page.locator('#demo-panel-a').isVisible()), 'tabs: click shows the matching panel');
await page.focus('#demo-tab-b');
await page.keyboard.press('ArrowRight');
check((await page.getAttribute('#demo-tab-c', 'aria-selected')) === 'true', 'tabs: ArrowRight selects the next tab');

await page.click('[data-dialog-open="demo-modal"]');
check(await page.locator('#demo-modal').evaluate((d) => d.open), 'modal opens');
await page.keyboard.press('Escape');
check(!(await page.locator('#demo-modal').evaluate((d) => d.open)), 'modal closes on Escape');

await page.click('[data-dialog-open="demo-confirm-name"]');
const submit = page.locator('#demo-confirm-name [data-confirm-submit]');
check(await submit.isDisabled(), 'confirm dialog: submit disabled until the name is typed');
await page.fill('#demo-confirm-name-name', 'Зерно');
check(await submit.isEnabled(), 'confirm dialog: submit enabled after typing the object name');
await page.keyboard.press('Escape');

await page.click('[data-toast-trigger][data-toast-kind="success"]');
check(await page.locator('.toast:visible').count() === 1, 'toast appears');
await page.click('[data-toast-close]');
check(await page.locator('.toast').count() === 0, 'toast closes');

await page.fill('#f-text', 'a'.repeat(5000));
check((await page.locator('#f-text ~ div [data-counter-out]').textContent()).includes('5000 из 4096'), 'textarea counter updates');
await page.context().close();

// ---- editor ----
page = await open('/dev/proto/editor');
check(await page.locator('[data-preview="vk"]').isVisible(), 'editor: first selected network preview is visible');
await page.click('[data-preview-tab="tg"]');
check(await page.locator('[data-preview="tg"]').isVisible() && !(await page.locator('[data-preview="vk"]').isVisible()), 'editor: preview tab switches');
await page.fill('[data-editor-text]', 'Привет, мир');
check((await page.locator('[data-preview="tg"] [data-preview-text]').textContent()) === 'Привет, мир', 'editor: preview mirrors the text');
await page.uncheck('[data-editor-platform="tg"]', { force: true });
check(!(await page.locator('[data-preview-tab="tg"]').isVisible()), 'editor: unchecking a network hides its preview tab');
await page.check('[data-editor-platform="ig"]', { force: true });
check((await page.locator('[data-editor-counter]').textContent()).includes('из 2200'), 'editor: counter uses the strictest limit');
await page.fill('[data-editor-text]', 'x'.repeat(2300));
check(await page.locator('[data-editor-counter]').evaluate((e) => e.classList.contains('text-bad-fg')), 'editor: counter turns red over the limit');
await page.uncheck('[data-editor-platform="vk"]', { force: true });
await page.uncheck('[data-editor-platform="ig"]', { force: true });
check(await page.locator('[data-editor-empty]').isVisible(), 'editor: empty hint when no network selected');
await page.context().close();

// ---- mobile drawer ----
page = await open('/dev/proto/dashboard', { width: 375, height: 800 });
await page.click('[data-dialog-open="nav-drawer"]');
check(await page.locator('#nav-drawer').evaluate((d) => d.open), 'mobile: burger opens the navigation drawer');
await page.click('#nav-drawer [data-dialog-close]');
check(!(await page.locator('#nav-drawer').evaluate((d) => d.open)), 'mobile: drawer closes');
await page.context().close();

// ---- media library: upload widget, refusal of a fake image, delete dialog ----
function png(width, height, [r, g, b]) {
  const crcTable = Array.from({ length: 256 }, (_, n) => { let c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1; return c >>> 0; });
  const crc = (buf) => { let c = 0xffffffff; for (const byte of buf) c = crcTable[(c ^ byte) & 0xff] ^ (c >>> 8); return (c ^ 0xffffffff) >>> 0; };
  const chunk = (type, data) => {
    const head = Buffer.alloc(8); head.writeUInt32BE(data.length, 0); head.write(type, 4, 'ascii');
    const tail = Buffer.alloc(4); tail.writeUInt32BE(crc(Buffer.concat([head.subarray(4), data])), 0);
    return Buffer.concat([head, data, tail]);
  };
  const ihdr = Buffer.alloc(13); ihdr.writeUInt32BE(width, 0); ihdr.writeUInt32BE(height, 4); ihdr[8] = 8; ihdr[9] = 2;
  const row = Buffer.concat([Buffer.from([0]), Buffer.from(Array.from({ length: width }, () => [r, g, b]).flat())]);
  const raw = Buffer.concat(Array.from({ length: height }, () => row));
  return Buffer.concat([Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]), chunk('IHDR', ihdr), chunk('IDAT', deflateSync(raw)), chunk('IEND', Buffer.alloc(0))]);
}

{
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  page = await ctx.newPage();
  page.on('console', (m) => { if (m.type() === 'error' && !m.text().includes('Cross-Origin-Opener-Policy') && !m.text().includes('status of 422')) { failures.push(`console error on media: ${m.text()}`); console.log('FAIL console', m.text()); } });
  page.on('pageerror', (e) => { failures.push(`page error on media: ${e.message}`); console.log('FAIL pageerror', e.message); });
  await page.goto(`${base}/dev/login-as/demo@ezposter.local`, { waitUntil: 'networkidle' });
  await page.goto(`${base}/app`, { waitUntil: 'networkidle' });
  const library = `${page.url().replace(/\/$/, '')}/media`;
  await page.goto(library, { waitUntil: 'networkidle' });
  const name = `behavior-${Date.now()}.png`;
  const before = await page.locator('section[aria-label="Файлы"] ul li').count();

  await page.setInputFiles('[data-upload-input]', { name: 'fake.jpg', mimeType: 'image/jpeg', buffer: Buffer.from('<?php echo 1; ?>') });
  await page.locator('[data-upload-list] [role="alert"]').waitFor({ timeout: 10000 });
  check((await page.locator('[data-upload-list] [role="alert"]').textContent()).includes('не поддерживается'), 'media: a fake image is refused with a readable message');
  check(await page.locator('section[aria-label="Файлы"] ul li').count() === before, 'media: a refused upload adds nothing to the grid');

  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle' }),
    page.setInputFiles('[data-upload-input]', { name, mimeType: 'image/png', buffer: png(64, 48, [Math.floor(Math.random() * 255), 90, 160]) }),
  ]);
  check(await page.locator(`section[aria-label="Файлы"] ul li:has-text("${name}")`).count() === 1, 'media: uploaded file shows up in the grid after the page reloads');
  const thumb = page.locator(`section[aria-label="Файлы"] ul li:has-text("${name}") img`);
  check(await thumb.evaluate((img) => img.complete && img.naturalWidth > 0), 'media: the thumbnail loads through the protected route');

  await page.click(`section[aria-label="Файлы"] ul li:has-text("${name}") a`);
  await page.waitForLoadState('networkidle');
  check(await page.locator('main img[alt]').first().evaluate((img) => img.complete && img.naturalWidth === 64), 'media: the original opens on the file page');
  await page.click('[data-dialog-open="media-delete"]');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('#media-delete [data-confirm-submit]')]);
  check(await page.locator(`section[aria-label="Файлы"] ul li:has-text("${name}")`).count() === 0, 'media: deleting through the dialog removes the file');
  await ctx.close();
}

await browser.close();
console.log(failures.length ? `\n${failures.length} failure(s)` : '\nall behaviors ok');
process.exit(failures.length ? 1 : 0);
