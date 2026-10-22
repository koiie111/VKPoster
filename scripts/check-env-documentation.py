#!/usr/bin/env python3
"""Check the documented env inventory without ever reading a local .env file."""

from pathlib import Path
import re
import sys

ROOT = Path(__file__).resolve().parents[1]
pattern = re.compile(
    r"->(?:string|int|bool|list|required|has)\(['\"]([A-Z][A-Z0-9_]+)['\"]"
)
names = {}
for base in ("config", "src", "scripts", "bin"):
    for file in (ROOT / base).rglob("*.php"):
        for name in pattern.findall(file.read_text()):
            names.setdefault(name, set()).add(str(file.relative_to(ROOT)))
for example in (".env.example", ".env.production.example"):
    for name in re.findall(r"^([A-Z][A-Z0-9_]+)=", (ROOT / example).read_text(), re.M):
        names.setdefault(name, set()).add(example)

doc = ROOT / "docs/architecture/configuration.md"
start, end = "<!-- ENV_INVENTORY_START -->", "<!-- ENV_INVENTORY_END -->"
if "--write" in sys.argv:
    text = doc.read_text()
    section = start + "\n\n## Checked environment inventory\n\n"
    section += "Generated from literal Env access and public examples only. No local `.env` is read.\n"
    section += "The source links define defaults, validation and whether a value is required.\n"
    section += "Secrets are provisioned externally; inventory presence never means a credential is configured.\n\n"
    section += "| Variable | Definition / access |\n|---|---|\n"
    for name, files in sorted(names.items()):
        links = ", ".join(
            "[" + file + "](../../" + file + ")" for file in sorted(files)
        )
        section += "| `" + name + "` | " + links + " |\n"
    section += "\n" + end
    if start in text:
        text = text[: text.index(start)] + section + text[text.index(end) + len(end) :]
    else:
        text += "\n\n" + section + "\n"
    doc.write_text(text)
missing = sorted(name for name in names if "`" + name + "`" not in doc.read_text())
if missing:
    raise SystemExit("Undocumented env variable names: " + ", ".join(missing))
print(
    f"PASS: {len(names)} public/literal env variables documented; local .env not read"
)
