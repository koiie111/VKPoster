# Trust anchors for specific integrations

`russian-trusted-root-ca.crt` is the Russian Trusted Root CA of the Ministry of Digital Development (self-signed,
valid until 2032-02-27, SHA-256 fingerprint
`D2:6D:2D:02:31:B7:C3:9F:92:CC:73:85:12:BA:54:10:35:19:E4:40:5D:68:B5:BD:70:3E:97:88:CA:8E:CF:31`).
Source: <https://gu-st.ru/content/lending/russian_trusted_root_ca_pem.crt>. `platform-api2.max.ru` serves a certificate that
chains to it (the server sends its own intermediate, so the root alone is enough).

It is used **only** by the MAX API client (`MAX_CA_FILE`, passed as the per-request `verify` option). It is deliberately not
added to the system trust store, so no other outgoing request trusts it.

The file has a `.crt` extension on purpose: `.dockerignore` drops `**/*.pem` (private-key hygiene), which silently removed the
anchor from the image in v0.1.2.
