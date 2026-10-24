# Preserve the vendor scanner/entrypoint; apply the Alpine fix for CVE-2026-111497.
FROM clamav/clamav:1.5.4_base@sha256:751df8dc649cc977696f107c80db4960b6317a7cb511aebc8066f3b91db6b631
RUN apk add --no-cache 'brotli-libs>=1.2.1-r0' \
    && test "$(apk version -t "$(apk info --installed -v brotli-libs | sed 's/^brotli-libs-//')" 1.2.1-r0)" != '<'
