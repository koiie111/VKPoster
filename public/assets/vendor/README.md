# Vendored front-end libraries

Files here are committed with fixed versions; `SHA256SUMS` is verified by `tests/Unit/Kernel/VendorAssetsTest.php`.
No CDN is used at runtime.

| File | Source | Version |
|---|---|---|
| `htmx.min.js` | `htmx.org` `dist/htmx.min.js` | 2.0.4 |
| `chart.umd.min.js` | `chart.js` `dist/chart.umd.js` (fetched from the npm package via jsDelivr; used by the admin dashboard, `js/admin-charts.js`) | 4.4.7 |
| `alpine-csp.min.js` | `@alpinejs/csp` `dist/cdn.min.js` (CSP build: no `eval`, works without `unsafe-eval`) | 3.14.9 |

Update: download the new file, replace it, run `shasum -a 256 <files> > SHA256SUMS`, mention it in the PR.

Redoc CE `redoc.standalone.js` 3.0.1: official npm package https://registry.npmjs.org/redoc/-/redoc-3.0.1.tgz, MIT license in redoc.LICENSE. Self-hosted ES module, no runtime CDN.

Local Redoc adaptation: the two static Redocly branding SVG references are replaced with same-origin vendored files (`redoc-logo.svg`, `redoc-logo-mini.svg`), downloaded from the corresponding cdn.redoc.ly/redoc paths. No CDN requests are needed at runtime; upstream license and attribution are retained. The checksums cover these adapted bytes. API bootstrap explicitly disables telemetry; typography/token overrides in api-docs.css address WCAG AA contrast in both themes.
