# Local security patch for go-jose/v3

This directory is the source of `github.com/go-jose/go-jose/v3` v3.0.5, kept
with its upstream Apache 2.0 license. `docker/caddy/build/go.mod` replaces the
unpatched module with this copy when building the Caddy binary.

`jwk.go` carries the RSA JWK bounds and prime-product checks from upstream's
v4.1.6 fix for [GHSA-mgcc-cww5-fp2q](https://github.com/go-jose/go-jose/security/advisories/GHSA-mgcc-cww5-fp2q).
The advisory has no patched v3 release. `jwk_security_test.go` rejects a crafted
oversized private JWK before the expensive RSA validation. The upstream v3 test
suite passed locally during preparation. The production copy omits upstream test
fixtures containing sample private keys; its focused regression test runs during
every Caddy image build with `go test ./...`.

Remove the local replacement when the upstream dependency chain migrates to a
patched go-jose release and the production image scan remains clean.
