// Behavior smoke test of the design-system components in a real browser (Alpine.data components, dialogs,
// theme, editor preview). Fails on any CSP violation or console error. Usage: node behaviors.mjs
import { chromium } from 'playwright';

const base = process.env.UI_SNAP_BASE_URL ?? 'http://nginx';
const browser = await chromium.launch();
const failures = [];
const check = (ok, msg) => { if (!ok) { failures.push(msg); console.log('FAIL', msg); } else { console.log('ok  ', msg); } };

async function open(path, viewport = { width: 1280, height: 900 }) {
  const ctx = await browser.newContext({ viewport });
  const page = await ctx.newPage();
  page.on('console', (m) => { if (m.type() === 'error' && !m.text().includes('Cross-Origin-Opener-Policy')) { failures.push(`console error on ${path}: ${m.text()}`); console.log('FAIL console', m.text()); } });
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

await browser.close();
console.log(failures.length ? `\n${failures.length} failure(s)` : '\nall behaviors ok');
process.exit(failures.length ? 1 : 0);
