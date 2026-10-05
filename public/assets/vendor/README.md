# Vendored front-end libraries

Files here are committed with fixed versions; `SHA256SUMS` is verified by `tests/Unit/Kernel/VendorAssetsTest.php`.
No CDN is used at runtime.

| File | Source | Version |
|---|---|---|
| `htmx.min.js` | `htmx.org` `dist/htmx.min.js` | 2.0.4 |
| `chart.umd.min.js` | `chart.js` `dist/chart.umd.js` (fetched from the npm package via jsDelivr; used by the admin dashboard, `js/admin-charts.js`) | 4.4.7 |
| `alpine-csp.min.js` | `@alpinejs/csp` `dist/cdn.min.js` (CSP build: no `eval`, works without `unsafe-eval`) | 3.14.9 |

Update: download the new file, replace it, run `shasum -a 256 <files> > SHA256SUMS`, mention it in the PR.
