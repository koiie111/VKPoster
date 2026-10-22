# Stage 19A: individual remediation of the 12 ASVS 4.0.3 L2 failures

Date: 2026-10-21. **IN_PROGRESS — not a security sign-off.** No blanket risk
acceptance, no finding is waived by this document. A means local remediation; B
means only production activation/evidence is deferred, after local work is verified.
A local implementation with incomplete validation stays PARTIAL, not PASS/deferred.

| Requirement | Finding / severity / applicability | Local remediation and affected boundary | Evidence so far | Current result / production owner step |
|---|---|---|---|---|
| V1.6.2 | Raw storage/signing keys in PHP; High; applicable A then B | Purpose-isolated `Crypto`/`VaultTransit`; strict production config; separate app/webhook/PDF/links/password/clicks keys; no raw active key or local fallback | Real TLS OpenBao encrypt/decrypt/rewrap/rotation/retirement/recovery and ACL tests; actual Chromium PDF with verify-only capability; offline migration/CAS/restart tests; production readiness | IMPLEMENTED / PRODUCTION_VERIFICATION_19_8. Code gap closed. Owner must provision patched backend, isolated TLS/HA, least-privilege renewable tokens, audit and separate Raft/unseal recovery, then verify custody/migration/restore. ADR0025 inventories all consumers and the distinct offline backup/migration roles. |
| V2.1.1 | Minimum 10 instead of 12; Medium; applicable A | `PasswordPolicy`: minimum 12 after repeated ASCII spaces combined for length only; hash input unchanged; form hints updated | `PasswordPolicyTest`, `RegistrationTest`; full PHP regression | PASS (local/server boundary). Existing passwords are not silently changed. |
| V2.1.8 | No strength meter; Medium; applicable A | Shared `forms.input` + local `passwordField` Alpine component, advisory strength, no value transmission/storage | Registration response test; real Chromium reveal/strength interaction and 4-screen mobile/desktop light/dark axe passed | PASS (local browser and server boundary). |
| V2.1.12 | No reveal control; Low; applicable A | Shared password control: keyboard button, aria-pressed/controls, local reveal/hide | Registration response test; real Chromium reveal/strength interaction and 4-screen mobile/desktop light/dark axe passed | PASS (local browser and server boundary). |
| V2.3.1 | Generated bootstrap password could remain indefinitely; High; applicable A | `CreateAdminCommand`: passwordless initial account, existing single-use reset capability, one-hour activation TTL; explicit stdin password remains user-chosen | `ConsoleCommandsTest`: no initial hash, hashed capability, consume once; existing reset expiry tests | PASS (local). Operator must deliver locally printed capability securely; do not put it into CI logs. |
| V2.3.2 | No FIDO enrollment/use; Medium; applicable A then B | `WebAuthnService`, account/login controllers, migration 46, shared UI; maintained `lbuchs/webauthn` 2.2.0; MFA enrollment proof, exact origin, 2-minute durable session/user-bound one-use challenge, UV/UP, conditional signature counter, revoke on MFA reset | `WebAuthnTest`: actual synthetic P-256 none attestation and signature, replay, wrong session/account, expiry, origin, revoke, anonymous/password-step guard | DEFERRED_TO_STAGE_19_8 (A local implementation + synthetic P-256/Chromium virtual-authenticator E2E and fail-closed throttle passed; B real device/origin only): use real device at final HTTPS RP origin, verify enrollment/login/revoke. No real device created locally. |
| V2.4.5 | No verifier-only password pepper; High; applicable A then B | Dedicated remote password Transit HMAC before Argon2id, server key version in hash; local-only file provider remains for tests/legacy fixtures | Real OpenBao pepper hash/verify/rotation; old version verifies and needsRehash upgrades; production config rejects local pepper files | DEFERRED_TO_STAGE_19_8. Local implementation is verified; owner must provision password-purpose key/token/custody and audited recovery, inventory legacy hashes, verify rollout/reset and old-key retirement. No active pepper is returned to PHP. |
| V2.6.2 | Short unsalted recovery verifiers; High; applicable A | New 128-bit CSPRNG codes; migration 45 wraps old SHA256 in individually salted Argon2id (old cheap hash removed) | `TwoFactorTest`: new format, old-code upgrade and one-use, rollback guard; schema fresh/rollback/replay | PASS local. Existing short codes remain usable once with expensive salted verifier. Measure production conversion in maintenance window; no lossy rollback with populated table. |
| V2.6.3 | Same offline-attack weakness; High; applicable A | Same remediation, bounded lookup max 10 legacy verifiers per user | Same tests and full PHP regression | PASS local; no claim that SHA256 alone strengthens an old short code. |
| V6.4.2 | Key execution was local/env-based; High; applicable A then B | Crypto operations execute in non-exporting Transit, not raw keys fetched from a vault; PDF verification and password pepper also remote; normal production rejects raw key config | Real OpenBao rejects app key export and renderer signing; rotated ciphertext/signatures/passwords remain usable; retired version blocks old ciphertext and reviewed recovery restores it; production runtime/ZAP | IMPLEMENTED / PRODUCTION_VERIFICATION_19_8. No remaining identified local APP_KEY consumer gap. Real production backend/key custody, HA/latency, audited token lifecycle and disaster recovery are19.8; local backend is not an HSM/conformance claim. |
| V12.4.2 | MIME/re-encoding was not antivirus; High; applicable A then B | `MalwareScanner`, `ClamdScanner`, `MediaService`, `ImageFiles`, `SafeDownloads`: private INSTREAM, bounded bytes/deadline/response, clean only exact OK; known malware 422, unavailable 503; production refuses Fake | `ClamdScannerTest` and HTTP upload rejection: clean/infected/error/unavailable/oversize; actual production runtime daemon clean/signature-hit/503 passed; official 1.5.4 image Trivy 0 HIGH/CRITICAL | DEFERRED_TO_STAGE_19_8 (A engine/protocol/runtime tested with explicitly synthetic signature database; B actual production signature/update deployment): initialize official signatures, test freshness/update failure, EICAR rejection and scanner outage; inventory/quarantine old unscanned assets. Never claim synthetic-only fixture database sufficient for production. |
| V14.4.2 | JSON attachment header absent; Low; applicable A | `Response::json`: fixed `Content-Disposition: attachment; filename="api.json"`, no user-controlled filename | `ResponseTest`, full PHP feature/API suite | PASS local. Unavailable metrics/content semantics unchanged. |

No item is classified Not Applicable or accepted risk merely to close this list.
Production-only steps above are candidates for `DEFERRED_TO_STAGE_19_8`; they do
not represent completed production custody/device/signature verification.

References: [Vault Transit API](https://developer.hashicorp.com/vault/api-docs/secret/transit),
[WebAuthn verifier](https://github.com/lbuchs/WebAuthn),
[ClamAV INSTREAM/scanning](https://docs.clamav.net/manual/Usage/Scanning.html),
[ClamAV Docker requirements](https://docs.clamav.net/manual/Installing/Docker.html).
ClamAV recommends 4 GiB RAM including signature reload; it is a capacity change,
not a free addition to the previous production memory budget.

## Local code gaps closed; production evidence remains explicit

The two original key-isolation gaps are IMPLEMENTED / PRODUCTION_VERIFICATION_19_8,
not blanket risk acceptance or a live-production PASS. ADR0025 provides the inventory,
key policies, offline legacy boundary, migration/rewrap/recovery and exact owner steps.
The real TLS/Raft fixture verifies the provider protocol; the actual hardened production
app Compose stand verifies readiness/worker scaling and outage/image recovery. The
renderer has verify-only capability and no shared HMAC key. Source/queue encrypted
values are migrated with CAS, including referral details and queued/dead-letter mail.
Historical v1 keyring is available only to an explicitly selected offline non-production
maintenance command; production config forbids it. No automatic production migration
or key retirement was performed against owner data.
