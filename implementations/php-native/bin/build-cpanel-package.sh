#!/usr/bin/env bash
set -euo pipefail
if [[ $# -ne 2 ]]; then
  echo "Usage: $0 <output-directory> <commit-sha>" >&2
  exit 64
fi
output_dir="$1"
commit_sha="$2"
repo_root="$(cd "$(dirname "$0")/../../.." && pwd)"
source_dir="$repo_root/implementations/php-native"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
[[ "$commit_sha" =~ ^[0-9a-f]{40}$ ]] || { echo "Commit SHA must be a full 40-character SHA." >&2; exit 64; }
for required in bootstrap.php public/index.php src database views bin README.md ARCHITECTURE.md PORTING-TRACKER.md; do
  [[ -e "$source_dir/$required" ]] || { echo "Native runtime payload is missing: $required" >&2; exit 1; }
done
mkdir -p "$work/payload/talyvane-native-app/vendor" "$work/customer/css" "$output_dir"
output_dir="$(cd "$output_dir" && pwd)"
payload="$work/payload/talyvane-native-app"
for path in bootstrap.php public src database views bin README.md ARCHITECTURE.md PORTING-TRACKER.md; do
  cp -a "$source_dir/$path" "$payload/"
done
rm -f "$payload/bin/integration.php" "$payload/bin/test.php" "$payload/bin/build-cpanel-package.sh"
# The native bootstrap owns its autoloader. This stub satisfies the shared
# hardened cPanel wrapper's pre-handoff integrity check; it is never required.
cat > "$payload/vendor/autoload.php" <<'PHP'
<?php
declare(strict_types=1);
return true;
PHP
find "$payload" -type d \( -name .git -o -name tests -o -name cache -o -name logs \) -prune -exec rm -rf {} +
find "$payload" -type f \( -name '.env' -o -name '.env.*' -o -name '*.log' -o -name '*.sqlite' -o -name '*.db' \) -delete
python3 "$source_dir/bin/generate-sbom.py" --root "$payload" --output "$work/customer/SBOM.spdx.json" --commit "$commit_sha"
test -f "$payload/public/index.php"
test -f "$payload/bootstrap.php"
test -f "$payload/vendor/autoload.php"
test -d "$payload/database/migrations"
test ! -e "$payload/tests"
test ! -e "$payload/.env"
find "$payload" -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
(
  cd "$work/payload"
  zip -X -q -r "$work/customer/.talyvane-native-payload.zip" talyvane-native-app
)
chmod 0600 "$work/customer/.talyvane-native-payload.zip"
cp "$source_dir/package/index.php" "$work/customer/index.php"
cp "$source_dir/package/.htaccess" "$work/customer/.htaccess"
cp "$source_dir/package/README.txt" "$work/customer/README.txt"
cat > "$work/customer/css/app.css" <<'CSS'
:root{color-scheme:light;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#17212b;background:#f4f7f8}
body{margin:0;line-height:1.5}
CSS
cat > "$work/customer/RELEASE-MANIFEST.txt" <<EOF
product=Talyvane
implementation=native-php
distribution=single-zip-cpanel-native-acceptance-candidate
release_status=NOT_FOR_PRODUCTION
scope=non-production-staging-only
commit=$commit_sha
private_payload=talyvane-native-app (placed outside document root)
bootstrap=PHP ZipArchive; PHP 8.3+; HTTPS; writable account home
runtime=no-composer-no-node-no-ssh
EOF
(
  cd "$work/customer"
  zip -X -q -r "$output_dir/Talyvane-Native-cPanel-$commit_sha.zip" .
)
(
  cd "$output_dir"
  sha256sum "Talyvane-Native-cPanel-$commit_sha.zip" > "Talyvane-Native-cPanel-$commit_sha.sha256"
  sha256sum -c "Talyvane-Native-cPanel-$commit_sha.sha256"
)
echo "Created $output_dir/Talyvane-Native-cPanel-$commit_sha.zip"
