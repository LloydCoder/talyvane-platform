#!/usr/bin/env bash
set -euo pipefail

archive="${1:?usage: cpanel-single-zip-bootstrap.sh <customer-archive.zip>}"; repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
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
document_root="$account/public_html/addon-domain/public_html/site"
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
HOME="$account/public_html" php -S "127.0.0.1:$port" -t "$document_root" > "$tmp/php-server.log" 2>&1 &
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

echo "Single-ZIP bootstrap fixture completed over PHP HTTP."
kill "$server_pid" 2>/dev/null || true
wait "$server_pid" 2>/dev/null || true
server_pid=""


assert_malicious_archive_rejected() {
  local attack="$1"
  local expected="$2"
  local attack_account="$tmp/attack-$attack"
  local attack_root="$attack_account/public_html"
  mkdir -p "$attack_root"
  python3 - "$attack_root/.talyvane-payload.zip" "$attack" <<'PY'
import stat
import sys
import zipfile

path, attack = sys.argv[1], sys.argv[2]
with zipfile.ZipFile(path, "w", compression=zipfile.ZIP_DEFLATED) as archive:
    archive.writestr("talyvane/", "")
    archive.writestr("talyvane/public/index.php", '<?php echo "SHOULD_NOT_RUN";')
    archive.writestr("talyvane/vendor/autoload.php", "<?php")
    if attack == "symlink":
        entry = zipfile.ZipInfo("talyvane/public/escape-link")
        entry.create_system = 3
        entry.external_attr = (stat.S_IFLNK | 0o777) << 16
        archive.writestr(entry, "../../outside.txt")
    else:
        archive.writestr("talyvane/../../outside.txt", "outside")
PY
  chmod 0600 "$attack_root/.talyvane-payload.zip"
  cp "$repo_root/cpanel-installer/index.php" "$attack_root/index.php"
  cp "$repo_root/cpanel-installer/.htaccess" "$attack_root/.htaccess"
  local attack_port="$((20000 + RANDOM % 20000))"
  php -S "127.0.0.1:$attack_port" -t "$attack_root" > "$tmp/$attack-server.log" 2>&1 &
  server_pid=$!
  local status=""
  for _ in $(seq 1 40); do
    status="$(curl --silent --show-error -o "$tmp/$attack-response.html" -w '%{http_code}' "http://127.0.0.1:$attack_port/" || true)"
    if [[ "$status" == "500" ]]; then break; fi
    sleep 0.25
  done
  if [[ "$status" != "500" ]]; then
    cat "$tmp/$attack-server.log" >&2
    echo "Expected the $attack archive to be rejected with HTTP 500; got $status." >&2
    exit 1
  fi
  grep -Fq "$expected" "$tmp/$attack-response.html"
  test ! -e "$attack_account/talyvane-app"
  kill "$server_pid" 2>/dev/null || true
  wait "$server_pid" 2>/dev/null || true
  server_pid=""
  echo "Unsafe $attack archive rejected before extraction."
}

assert_malicious_archive_rejected "traversal" "unsafe path"
assert_malicious_archive_rejected "symlink" "unsafe path"

# Exercise the complete packaged Laravel browser installer, not just a fixture.
actual_account="$tmp/actual-account"
actual_document_root="$actual_account/public_html"
mkdir -p "$actual_document_root"
unzip -q "$1" -d "$actual_document_root"

cat > "$tmp/router.php" <<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = '443';
if ($path !== '/' && is_file($_SERVER['DOCUMENT_ROOT'].$path)) {
    return false;
}
require $_SERVER['DOCUMENT_ROOT'].'/index.php';
PHP

actual_port="$((20000 + RANDOM % 20000))"
php -S "127.0.0.1:$actual_port" -t "$actual_document_root" "$tmp/router.php" > "$tmp/actual-php-server.log" 2>&1 &
actual_server_pid=$!
server_pid="$actual_server_pid"

actual_ready=0
for _ in $(seq 1 60); do
  if curl --silent --show-error --fail -H 'Host: localhost' "http://127.0.0.1:$actual_port/install" > "$tmp/actual-installer.html"; then
    actual_ready=1
    break
  fi
  sleep 0.25
done

if [[ "$actual_ready" -ne 1 ]]; then
  cat "$tmp/actual-php-server.log" >&2
  echo "Packaged Laravel installer did not render over HTTP." >&2
  exit 1
fi

grep -Fq 'FIRST-TIME SETUP' "$tmp/actual-installer.html"
grep -Fq 'Hosting preflight' "$tmp/actual-installer.html"
grep -Fq 'PHP 8.3 or later' "$tmp/actual-installer.html"
test -f "$actual_account/talyvane-app/vendor/autoload.php"
test -f "$actual_account/talyvane-app/.env"
test ! -e "$actual_document_root/.talyvane-payload.zip"
test ! -e "$actual_document_root/vendor"
test ! -e "$actual_document_root/.env"
test "$(stat -c '%a' "$actual_account/talyvane-app/.env")" = '600'

asset_code="$(curl --silent --show-error -H 'Host: localhost' -o "$tmp/asset.css" -w '%{http_code}' "http://127.0.0.1:$actual_port/css/app.css")"
test "$asset_code" = '200'
grep -Fq 'color-scheme: dark' "$tmp/asset.css"

kill "$actual_server_pid" 2>/dev/null || true
wait "$actual_server_pid" 2>/dev/null || true
server_pid=""

echo "Packaged Laravel installer and public asset delivery passed over PHP HTTP."

