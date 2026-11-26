#!/usr/bin/env python3
"""Create a deterministic SPDX 2.3 file-level SBOM for the staged native PHP payload."""
import argparse
import hashlib
import json
from pathlib import Path

parser = argparse.ArgumentParser()
parser.add_argument("--root", required=True, type=Path)
parser.add_argument("--output", required=True, type=Path)
parser.add_argument("--commit", required=True)
args = parser.parse_args()
if len(args.commit) != 40 or any(c not in "0123456789abcdef" for c in args.commit):
    raise SystemExit("commit must be a full lowercase Git SHA-1")
root = args.root.resolve(strict=True)
files = sorted(p for p in root.rglob("*") if p.is_file())
entries = []
relationships = []
for path in files:
    rel = path.relative_to(root).as_posix()
    digest = hashlib.sha256(path.read_bytes()).hexdigest()
    file_id = "SPDXRef-File-" + hashlib.sha256(rel.encode()).hexdigest()[:20]
    entries.append({
        "fileName": "./" + rel,
        "SPDXID": file_id,
        "checksums": [{"algorithm": "SHA256", "checksumValue": digest}],
        "licenseConcluded": "NOASSERTION",
        "licenseInfoInFiles": ["NOASSERTION"],
        "copyrightText": "NOASSERTION",
        "fileTypes": ["SOURCE"] if path.suffix.lower() in {".php", ".sql", ".json", ".md", ".txt"} else ["BINARY"]
    })
    relationships.append({
        "spdxElementId": "SPDXRef-Package",
        "relationshipType": "CONTAINS",
        "relatedSpdxElement": file_id
    })
document = {
    "spdxVersion": "SPDX-2.3",
    "dataLicense": "CC0-1.0",
    "SPDXID": "SPDXRef-DOCUMENT",
    "name": "Talyvane Native PHP Payload",
    "documentNamespace": f"https://talyvane.invalid/spdx/native-php/{args.commit}",
    "creationInfo": {
        "creators": ["Tool: talyvane-native-sbom/1.0"],
        "created": "2026-01-01T00:00:00Z",
        "comment": f"Deterministic source-file inventory for commit {args.commit}; generated at package build time."
    },
    "packages": [{
        "name": "talyvane-native-app",
        "SPDXID": "SPDXRef-Package",
        "versionInfo": args.commit,
        "downloadLocation": "NOASSERTION",
        "filesAnalyzed": True,
        "licenseConcluded": "NOASSERTION",
        "licenseDeclared": "NOASSERTION",
        "copyrightText": "NOASSERTION"
    }],
    "files": entries,
    "relationships": relationships
}
args.output.parent.mkdir(parents=True, exist_ok=True)
args.output.write_text(json.dumps(document, sort_keys=True, separators=(",", ":")) + "\n", encoding="utf-8")
print(f"Generated SPDX 2.3 SBOM with {len(entries)} file records.")
