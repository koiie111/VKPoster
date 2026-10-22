# ADR 0023: Applicable ASVS authentication remediations

Date: 2026-10-20. Status: implementation/validation in progress; no production activation.
Owner explicitly requires local remediation of the 12 ASVS FAIL, not blanket risk acceptance.

Keep the existing auth/session/journal/CSRF/MFA/notification architecture. Minimum
password length becomes 12 after repeated spaces are collapsed for the length check
only; hashing never truncates or normalizes the actual secret. Shared password form
controls provide local advisory strength and keyboard-accessible reveal without
sending/storing values. Generated admin bootstrap uses the existing one-use password
reset capability, one-hour TTL, locally printed link and no initial long-term password.
Explicit `--password-stdin` is a user-chosen password, not a generated bootstrap secret.

New recovery codes have 128 CSPRNG bits. Existing short-code SHA256 verifiers are
one-way upgraded to per-record salted Argon2id without retaining the old cheap hash.
They still work once. Migration 45 supports rollback only on an empty code table;
never pretend to recover an erased preimage. Deployment requires a reviewed maintenance
window and explicit schema compatibility declaration; do not automatically reverse it.
A large legacy population requires measuring conversion time before production.

Use `lbuchs/webauthn` 2.2.x for FIDO2/WebAuthn verification, pinned in Composer lock.
Official PHP >=8 support, MIT license, no framework or transitive PHP dependency.
This additional dependency is justified by ENGINEERING_RULES §2: do not hand-roll
CBOR/COSE, attestation, signature/origin/challenge validation. Inspect official API,
run composer audit/static/security and regression checks before sign-off. Keep auth
MFA/session gates; a browser-supplied identity or success flag is never authority.
No real hardware enrollment/owner credential is created during development tests.
The real RP origin/device compatibility remains production verification in 19.8.

Password pepper and isolated storage-key operations must use explicit external secret
files / a Vault-compatible transit boundary, fail closed when configured unavailable,
keep version/rotation semantics and test legacy migration. A secret fetched from a
vault into PHP memory is not claimed as isolated crypto. Real production key custody,
non-exportable server policy, authorization, backup and old-key retirement need actual
owner evidence; no production key/secret is created in this stage.

Official references inspected:
- https://github.com/lbuchs/WebAuthn (registration/get challenge + verify workflows)
- https://packagist.org/packages/lbuchs/webauthn (stable v2.2.0 / PHP >=8)
- https://developer.hashicorp.com/vault/api-docs/secret/transit
