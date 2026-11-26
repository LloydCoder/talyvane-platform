#!/usr/bin/env bash
set -euo pipefail
archive="$1"
expected_commit="$2"
if [[ $# -ne 2 ]]; then echo "usage: native-php-cpanel-contract.sh <archive.zip> <commit-sha>" >&2; exit 64; fi
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
test -s "$archive"
unzip -tq "$archive"
unzip -Z1 "$archive" > "$tmp/outer.txt"
for required in index.php .htaccess .talyvane-native-payload.zip README.txt RELEASE-MANIFEST.txt SBOM.spdx.json css/app.css; do
  grep -Fxq "$required" "$tmp/outer.txt" || { echo "Missing native package entry: $required" >&2; exit 1; }
done
if grep -Eq '^(src|database|views|bin|vendor|bootstrap\.php|\.env($|\.)|composer\.(json|lock))' "$tmp/outer.txt"; then
  echo "Private native source was placed in the public extraction root." >&2
  exit 1
fi
unzip -q "$archive" -d "$tmp/customer"
php -l "$tmp/customer/index.php"
grep -Fq "commit=$expected_commit" "$tmp/customer/RELEASE-MANIFEST.txt"
grep -Fq 'implementation=native-php' "$tmp/customer/RELEASE-MANIFEST.txt"
grep -Fq 'release_status=NOT_FOR_PRODUCTION' "$tmp/customer/RELEASE-MANIFEST.txt"
grep -Fq 'runtime=no-composer-no-node-no-ssh' "$tmp/customer/RELEASE-MANIFEST.txt"
grep -Fq 'sbom_format=SPDX-2.3' "$tmp/customer/RELEASE-MANIFEST.txt"
python3 - "$tmp/customer/SBOM.spdx.json" "$tmp/customer/.talyvane-native-payload.zip" "$expected_commit" <<'PY'
import hashlib, json, sys, zipfile
sbom_path, archive, commit = sys.argv[1:]
sbom = json.load(open(sbom_path, encoding='utf-8'))
assert sbom['spdxVersion'] == 'SPDX-2.3'
assert sbom['documentNamespace'].endswith('/' + commit)
with zipfile.ZipFile(archive) as z:
    members = {n.removeprefix('talyvane-native-app/'): n for n in z.namelist() if n.startswith('talyvane-native-app/') and not n.endswith('/')}
    records = {f['fileName'].removeprefix('./'): f for f in sbom['files']}
    assert set(records) == set(members), 'SBOM file inventory does not match private payload'
    for rel, record in records.items():
        digest = hashlib.sha256(z.read(members[rel])).hexdigest()
        assert digest == record['checksums'][0]['checksumValue'], f'SBOM checksum mismatch: {rel}'
print(f"SPDX SBOM verified: {len(records)} private payload files")
PY
test "$(stat -c '%a' "$tmp/customer/.talyvane-native-payload.zip")" = '600'
unzip -tq "$tmp/customer/.talyvane-native-payload.zip"
unzip -Z1 "$tmp/customer/.talyvane-native-payload.zip" > "$tmp/private.txt"
for required in \
  talyvane-native-app/bootstrap.php \
  talyvane-native-app/public/index.php \
  talyvane-native-app/src/Bootstrap.php \
  talyvane-native-app/src/Installer/PrivatePathResolver.php \
  talyvane-native-app/src/Installer/SafeZipExtractor.php \
  talyvane-native-app/database/migrations/0001_core.php \
  talyvane-native-app/database/migrations/0021_membership_entitlements.php \
  talyvane-native-app/database/migrations/0022_safeguarding_restore_verification.php \
  talyvane-native-app/database/migrations/0023_safeguarding_retention_policy_events.php \
  talyvane-native-app/database/reference-data/countries.json \
  talyvane-native-app/views/install/wizard.php \
  talyvane-native-app/vendor/autoload.php \
  talyvane-native-app/bin/reveal-super-admin-recovery-key.php \
  talyvane-native-app/bin/dispatch-safeguarding-notifications.php \
  talyvane-native-app/bin/apply-safeguarding-retention.php \
  talyvane-native-app/bin/verify-safeguarding-restore.php \
  talyvane-native-app/src/Application/Safeguarding/VaultTransitSafeguardingCaseKeyProvider.php \
  talyvane-native-app/src/Application/Safeguarding/SafeguardingRetentionService.php \
  talyvane-native-app/src/Application/Safeguarding/SafeguardingRestoreVerificationService.php \
  talyvane-native-app/src/Application/Safeguarding/SafeguardingBackupPolicyService.php \
  talyvane-native-app/src/Application/Safeguarding/SafeguardingRetentionPolicyService.php \
  talyvane-native-app/src/Application/Safeguarding/SafeguardingNotificationOutbox.php \
  talyvane-native-app/src/Application/Safeguarding/SafeguardingNotificationDispatcher.php; do
  grep -Fxq "$required" "$tmp/private.txt" || { echo "Native private payload is incomplete: $required" >&2; exit 1; }
done
if grep -Eq '(^|/)tests/|(^|/)\.env($|\.)|(^|/)(integration|test)\.php$|\.log$|\.sqlite$' "$tmp/private.txt"; then
  echo "Native private payload contains tests, environment secrets or local runtime state." >&2
  exit 1
fi
grep -Fq "TALYVANE_APP_DIRECTORY = 'talyvane-native-app'" "$tmp/customer/index.php"
grep -Fq "TALYVANE_PAYLOAD_FILE = '.talyvane-native-payload.zip'" "$tmp/customer/index.php"
grep -Fq "putenv('HOME=" "$tmp/customer/index.php"
grep -Fq 'accountDirectory' "$tmp/customer/index.php"
grep -Fq 'Require all denied' "$tmp/customer/.htaccess" || grep -Fq 'RewriteRule (^|/)\.' "$tmp/customer/.htaccess"
grep -Fq 'no-composer-no-node-no-ssh' "$tmp/customer/RELEASE-MANIFEST.txt"
grep -Fq 'No SSH' "$tmp/customer/README.txt"
echo "Native PHP single-ZIP cPanel package contract passed."
