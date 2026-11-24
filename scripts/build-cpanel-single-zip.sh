#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 3 ]]; then
  echo "Usage: $0 <private-app-directory> <output-directory> <commit-sha>" >&2
  exit 64
fi

app_dir="$1"
output_dir="$2"
commit_sha="$3"
repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

[[ -d "$app_dir/public" && -f "$app_dir/vendor/autoload.php" ]] || { echo "Private app directory is incomplete." >&2; exit 1; }
[[ "$commit_sha" =~ ^[0-9a-f]{40}$ ]] || { echo "Commit SHA must be a full 40-character SHA." >&2; exit 1; }
command -v rsync >/dev/null || { echo "rsync is required to create a clean production payload." >&2; exit 1; }

mkdir -p "$output_dir" "$work/package" "$work/payload/talyvane"
rsync -a --delete \
  --exclude='/.git/' \
  --exclude='/.github/' \
  --exclude='/tests/' \
  --exclude='/dist/' \
  --exclude='/node_modules/' \
  --exclude='/.env' \
  --exclude='/.env.*' \
  --exclude='/storage/app/private/*' \
  --exclude='/storage/logs/*' \
  --exclude='/storage/framework/cache/data/*' \
  --exclude='/storage/framework/sessions/*' \
  --exclude='/storage/framework/views/*' \
  --exclude='/bootstrap/cache/*' \
  "$app_dir/" "$work/payload/talyvane/"

test -f "$work/payload/talyvane/vendor/autoload.php"
test -f "$work/payload/talyvane/public/index.php"
test -f "$work/payload/talyvane/install/bootstrap.php"
test ! -e "$work/payload/talyvane/.env"
test ! -e "$work/payload/talyvane/.git"
test ! -d "$work/payload/talyvane/tests"

cp "$repo_root/cpanel-installer/index.php" "$work/package/index.php"
cp "$repo_root/cpanel-installer/.htaccess" "$work/package/.htaccess"
cp "$repo_root/cpanel-installer/README.txt" "$work/package/README.txt"
(
  cd "$work/payload"
  zip -X -q -r "$work/package/.talyvane-payload.zip" talyvane
)
chmod 0600 "$work/package/.talyvane-payload.zip"
cat > "$work/package/RELEASE-MANIFEST.txt" <<EOF
product=Talyvane
distribution=single-zip-cpanel-customer-installer
commit=$commit_sha
private_payload=talyvane-app (placed outside document root)
bootstrap=PHP ZipArchive; PHP 8.3+; HTTPS; writable account parent
EOF
(
  cd "$work/package"
  zip -X -q -r "$output_dir/Talyvane-cPanel-$commit_sha.zip" .
)
(
  cd "$output_dir"
  sha256sum "Talyvane-cPanel-$commit_sha.zip" > "Talyvane-cPanel-$commit_sha.sha256"
  sha256sum -c "Talyvane-cPanel-$commit_sha.sha256"
)
echo "Created $output_dir/Talyvane-cPanel-$commit_sha.zip"
