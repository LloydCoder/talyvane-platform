#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
tmp="$(mktemp -d)"
server_pid=""
cleanup() {
  if [[ -n "$server_pid" ]]; then
    kill "$server_pid" 2>/dev/null || true
    wait "$server_pid" 2>/dev/null || true
  fi
  rm -rf "$tmp"
}
trap cleanup EXIT

command -v curl >/dev/null
php -r 'exit(class_exists(ZipArchive::class) ? 0 : 1);'

account="$tmp/account"
document_root="$account/public_html"
mkdir -p "$document_root" "$tmp/payload/talyvane/public" "$tmp/payload/talyvane/vendor"
printf '%s\n' '<?php echo "TALYVANE_BOOTSTRAP_HTTP_OK";' > "$tmp/payload/talyvane/public/index.php"
printf '%s\n' '<?php // isolated autoload fixture' > "$tmp/payload/talyvane/vendor/autoload.php"
(
  cd "$tmp/payload"
  zip -X -q -r "$document_root/.talyvane-payload.zip" talyvane
)
chmod 0600 "$document_root/.talyvane-payload.zip"
cp "$repo_root/cpanel-installer/index.php" "$document_root/index.php"
cp "$repo_root/cpanel-installer/.htaccess" "$document_root/.htaccess"

port="$((20000 + RANDOM % 20000))"
php -S "127.0.0.1:$port" -t "$document_root" > "$tmp/php-server.log" 2>&1 &
server_pid=$!

ready=0
for _ in $(seq 1 40); do
  if curl --silent --fail "http://127.0.0.1:$port/" > "$tmp/response.txt"; then
    ready=1
    break
  fi
  sleep 0.25
done

if [[ "$ready" -ne 1 ]]; then
  cat "$tmp/php-server.log" >&2
  echo "PHP HTTP server did not become ready." >&2
  exit 1
fi

grep -Fq 'TALYVANE_BOOTSTRAP_HTTP_OK' "$tmp/response.txt"
test -f "$account/talyvane-app/public/index.php"
test -f "$account/talyvane-app/vendor/autoload.php"
test ! -e "$document_root/.talyvane-payload.zip"
test ! -e "$account/talyvane-app/.env"

echo "Single-ZIP bootstrap completed over real PHP HTTP."
