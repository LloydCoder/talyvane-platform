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

mkdir -p "$output_dir" "$work/package" "$work/payload/talyvane"
output_dir="$(cd "$output_dir" && pwd)"
for path in app artisan bootstrap config database docs install public resources routes storage vendor composer.json composer.lock .env.example README.md LICENSE COMMERCIAL-LICENSING.md; do
  if [[ ! -e "$app_dir/$path" ]]; then
    echo "Required production payload path is missing: $path" >&2
    exit 1
  fi
  cp -a "$app_dir/$path" "$work/payload/talyvane/"
done

# Never ship a developer checkout's runtime state or compiled caches.
find "$work/payload/talyvane/bootstrap/cache" -type f ! -name '.gitkeep' -delete
find "$work/payload/talyvane/storage/framework/views" -type f ! -name '.gitkeep' -delete
find "$work/payload/talyvane/storage/framework/sessions" -type f ! -name '.gitkeep' -delete
find "$work/payload/talyvane/storage/framework/cache" -type f ! -name '.gitkeep' -delete
find "$work/payload/talyvane/storage/logs" -type f ! -name '.gitkeep' -delete
find "$work/payload/talyvane/storage/app/private" -type f ! -name '.gitkeep' -delete

test -f "$work/payload/talyvane/vendor/autoload.php"
test -f "$work/payload/talyvane/public/index.php"
test -f "$work/payload/talyvane/install/bootstrap.php"
test -f "$work/payload/talyvane/.env.example"
test ! -e "$work/payload/talyvane/.env"
test ! -e "$work/payload/talyvane/.git"
test ! -d "$work/payload/talyvane/tests"

cp "$repo_root/cpanel-installer/index.php" "$work/package/index.php"
cp "$repo_root/cpanel-installer/.htaccess" "$work/package/.htaccess"
cp "$repo_root/cpanel-installer/README.txt" "$work/package/README.txt"
# Laravel's public path is the customer's document root after bootstrap. Ship
# public assets at the archive root while keeping the bootstrap index and
# hardened rewrite file authoritative.
while IFS= read -r -d '' public_entry; do
  public_name="$(basename "$public_entry")"
  case "$public_name" in
    index.php|.htaccess) continue ;;
  esac
  cp -a "$public_entry" "$work/package/"
done < <(find "$app_dir/public" -mindepth 1 -maxdepth 1 -print0)
(
  cd "$work/payload"
  zip -X -q -r "$work/package/.talyvane-payload.zip" talyvane
)
chmod 0600 "$work/package/.talyvane-payload.zip"
cat > "$work/package/RELEASE-MANIFEST.txt" <<EOF
product=Talyvane
distribution=single-zip-cpanel-owner-acceptance-candidate
release_status=NOT_FOR_PRODUCTION
scope=non-production-staging-only
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
