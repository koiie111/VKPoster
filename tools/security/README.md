# Security tooling lock

Versions: Gitleaks 8.30.1 (digest in scripts/security/gitleaks.py), Semgrep 1.181.0,
Trivy 0.76.0, ZAP 2.17.0. Tools run outside the application image.
Semgrep PHP and OWASP Top Ten rule bundles are vendored snapshots retrieved on
2026-10-19 from https://semgrep.dev/c/p/php and https://semgrep.dev/c/p/owasp-top-ten;
see semgrep.sha256. CI uses the snapshots, never mutable remote packs.
No Semgrep/Trivy/ZAP finding suppressions are currently configured.
Gitleaks exceptions and proof: docs/security/gitleaks.md.
Trivy HIGH and CRITICAL both fail, including unfixed vulnerabilities: no blanket ignore.
