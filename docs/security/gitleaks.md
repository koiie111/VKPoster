# Gitleaks finding classification — 2026-10-19

Gitleaks 8.30.1, default rules retained. No global rule/path/commit allowlist.
Every historical exception in `.gitleaksignore` names exactly commit/path/rule/line.
Values are never logged. No history rewrite or external credential validation.

| Finding | Class | Evidence / treatment |
|---|---|---|
| 91f13ff… / modules/auth/auth_callback.php:6 / generic-api-key | **B: revoked historical credential** | Owner explicitly confirmed revocation/rotation on 2026-10-19. Treat as compromised; exact fingerprint only. No credential value recorded. |
| c5aebfb… / tools/telegram-reader/tests/run_ci_contract.sh:12 | **C: synthetic fixture** | Base64 decodes to deterministic bytes 0..31, identical to TestEnv::KEY; used only in testing container and test helper, never service runtime configuration. Path AND exact synthetic value exception. |
| 8ee5dc1… / tests/Support/TestEnv.php:17; current same fixture | **C: synthetic fixture** | Same deterministic bytes 0..31; helper explicitly never reads developer .env and is dev-autoloaded. Path AND exact value exception; different keys in this file remain detectable. |
| 99f86c8… / vendor/vkcom/vk-php-sdk/README.md:287 | **D: documentation example** | Fenced Long Poll example in original VKCOM SDK README, source ref 0b01a07b167549d08a3d910201cec9e8a202a0ee from legacy composer.lock. No usage outside vendor documentation in that revision. Exact historical fingerprint; vendor directory is NOT excluded. |
| 99f86c8… / vendor/vkcom/vk-php-sdk/README.md:305 | **D: documentation example** | Repetition of preceding SDK example in an explicitly labelled Example block; same source/provenance, no application usage. Separate exact fingerprint. |

Regression must detect a newly generated synthetic API key both in another file and in
TestEnv.php (different from the allowed fixture). Reports always use `--redact=100`.
Official configuration reference: https://github.com/gitleaks/gitleaks#configuration .
