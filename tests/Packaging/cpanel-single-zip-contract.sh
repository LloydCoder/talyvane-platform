#!/usr/bin/env bash
set -euo pipefail

archive="${1:?usage: cpanel-single-zip-contract.sh <archive.zip>}"
expected_commit="${2:?usage: cpanel-single-zip-contract.sh <archive.zip> <commit-sha>}"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

test -s "$archive"
unzip -tq "$archive"
unzip -Z1 "$archive" > "$tmp/outer-list.txt"

for required in index.php .htaccess .talyvane-payload.zip README.txt RELEASE-MANIFEST.txt css/app.css; do
  grep -Fxq "$required" "$tmp/outer-list.txt" || {
    echo "Missing customer package entry: $required" >&2
    exit 1
  }
done

if grep -Eq '^(app|artisan|bootstrap|config|database|install|resources|routes|storage|vendor)/|^\.env($|\.)|^composer\.(json|lock)$' "$tmp/outer-list.txt"; then
  echo "Private application files were placed directly in the public extraction root." >&2
  exit 1
fi

unzip -q "$archive" -d "$tmp/customer"
php -l "$tmp/customer/index.php"
grep -Fq "commit=$expected_commit" "$tmp/customer/RELEASE-MANIFEST.txt"
grep -Fq 'distribution=single-zip-cpanel-customer-installer' "$tmp/customer/RELEASE-MANIFEST.txt"

test "$(stat -c '%a' "$tmp/customer/.talyvane-payload.zip")" = '600'
unzip -tq "$tmp/customer/.talyvane-payload.zip"
unzip -Z1 "$tmp/customer/.talyvane-payload.zip" > "$tmp/private-list.txt"
grep -Fxq 'talyvane/public/index.php' "$tmp/private-list.txt"
grep -Fxq 'talyvane/vendor/autoload.php' "$tmp/private-list.txt"
grep -Fxq 'talyvane/install/bootstrap.php' "$tmp/private-list.txt"
grep -Fxq 'talyvane/composer.lock' "$tmp/private-list.txt"

if grep -Eq '(^|/)\.env$|(^|/)storage/(logs|app/private)/[^.]|(^|/)bootstrap/cache/[^.]' "$tmp/private-list.txt"; then
  echo "Private payload contains an environment secret or runtime state." >&2
  exit 1
fi

grep -Fq 'Require all denied' "$tmp/customer/.htaccess"
grep -Fq 'HTTP_AUTHORIZATION' "$tmp/customer/.htaccess"
grep -Fq 'HTTP_X_XSRF_TOKEN' "$tmp/customer/.htaccess"
grep -Fq 'RewriteRule ^(?:app|bootstrap|config|database|resources|routes|storage|tests|vendor)' "$tmp/customer/.htaccess"
grep -Fq 'TALYVANE_PAYLOAD_FILE' "$tmp/customer/index.php"
grep -Fq 'ZipArchive' "$tmp/customer/index.php"
grep -Fq 'is_link' "$tmp/customer/index.php"
grep -Fq 'talyvane-app' "$tmp/customer/index.php"

echo "Single-ZIP cPanel package contract passed."
