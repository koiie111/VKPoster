// Screenshots (375/768/1440 px x light/dark) and an axe-core audit for the URLs of one stage.
// Usage: node snap.mjs <stage> [--a11y-only]   (URL list: tools/ui-snap/urls/stage-<stage>.json;
// an item may have `login_as` (user id or email) and `actions` to reach a state; `{ws}` in the url is replaced
// by the id of the signed-in user's workspace, found by following `/app`)
import { chromium } from 'playwright';
import AxeBuilder from '@axe-core/playwright';
import { readFileSync, mkdirSync, writeFileSync } from 'node:fs';

const stage = process.argv[2];
const a11yOnly = process.argv.includes('--a11y-only');
if (!stage) {
  console.error('usage: snap.mjs <stage> [--a11y-only]');
  process.exit(2);
}

const base = process.env.UI_SNAP_BASE_URL ?? 'http://nginx';
const list = JSON.parse(readFileSync(`/work/tools/ui-snap/urls/stage-${stage}.json`, 'utf8'));
const out = `/work/storage/ui-review/stage-${stage}`;
const widths = [360, 375, 768, 1440];
const shotWidths = [375, 768, 1440];
const themes = ['light', 'dark'];
const blocking = new Set(['serious', 'critical']);

mkdirSync(out, { recursive: true });
const browser = await chromium.launch();
const report = [];
let failed = false;

for (const item of list) {
  const template = item.url.startsWith('file:') || item.url.startsWith('http') ? item.url : base + item.url;
  for (const theme of themes) {
    for (const width of widths) {
      const ctx = await browser.newContext({
        viewport: { width, height: 900 },
        colorScheme: theme,
        reducedMotion: 'reduce',
      });
      const page = await ctx.newPage();
      if (item.login_as) {
        // Signed-in screens: local-only dev login (a user id or an email), see DevLoginController.
        await page.goto(`${base}/dev/login-as/${encodeURIComponent(item.login_as)}`, { waitUntil: 'networkidle' });
      }
      let url = template;
      if (url.includes('{ws}')) {
        await page.goto(`${base}/app`, { waitUntil: 'networkidle' });
        const found = page.url().match(/\/w\/([0-9A-Z]{26})/);
        if (!found) throw new Error(`no workspace after /app for ${item.name}`);
        url = url.replace('{ws}', found[1]);
      }
      await page.goto(url, { waitUntil: 'networkidle' });
      // Optional scripted steps to reach a state: [{fill: [selector, value]}, {click: selector}].
      for (const step of item.actions ?? []) {
        if (step.fill) {
          await page.fill(step.fill[0], step.fill[1]);
        } else if (step.click) {
          await Promise.all([page.waitForLoadState('networkidle'), page.click(step.click)]);
        }
      }
      await page.evaluate((t) => document.documentElement.setAttribute('data-theme', t), theme);
      await page.waitForTimeout(150);

      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
      if (width <= 375 && overflow > 0) {
        report.push(`FAIL ${item.name} ${theme} ${width}px: horizontal scroll (${overflow}px)`);
        failed = true;
      }
      if (!a11yOnly && shotWidths.includes(width)) {
        await page.screenshot({ path: `${out}/${item.name}-${width}-${theme}.png`, fullPage: true });
      }
      if (width === 1440 || width === 360) {
        const res = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
        for (const v of res.violations.filter((x) => blocking.has(x.impact))) {
          failed = true;
          report.push(`FAIL ${item.name} ${theme} ${width}px: axe ${v.id} (${v.impact}) x${v.nodes.length}: ${v.nodes[0].target.join(' ')}`);
        }
      }
      await ctx.close();
    }
  }
}
await browser.close();
writeFileSync(`${out}/report.txt`, report.join('\n') + (report.length ? '\n' : ''));
console.log(report.length ? report.join('\n') : `ui-snap stage ${stage}: ${list.length} pages, no blocking issues`);
process.exit(failed ? 1 : 0);
