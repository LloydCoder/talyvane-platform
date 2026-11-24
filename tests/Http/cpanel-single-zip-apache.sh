#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
tmp="$(mktemp -d)"
chmod 755 "$tmp"
container=""
cleanup() {
  if [[ -n "$container" ]]; then docker rm -f "$container" >/dev/null 2>&1 || true; fi
  rm -rf "$tmp"
}
trap cleanup EXIT

command -v docker >/dev/null
command -v curl >/dev/null
webroot="$tmp/public_html"
mkdir -p "$webroot"
cp "$repo_root/cpanel-installer/.htaccess" "$webroot/.htaccess"
printf '%s\n' '<?php header("Content-Type: text/plain"); echo "TALYVANE_SINGLE_ZIP_APACHE_OK";' > "$webroot/index.php"
printf '%s\n' 'private payload must not be served' > "$webroot/.talyvane-payload.zip"
printf '%s\n' 'APP_KEY=must-not-be-served' > "$webroot/.env"
printf '%s\n' 'body { color: black; }' > "$webroot/app.css"

port="$((20000 + RANDOM % 20000))"
container_name="talyvane-single-zip-apache-$RANDOM"
docker run -d --name "$container_name" -p "127.0.0.1:$port:80" -v "$webroot:/var/www/html:ro" php:8.3-apache bash -lc 'a2enmod rewrite headers >/dev/null; sed -ri "s/AllowOverride None/AllowOverride All/g" /etc/apache2/apache2.conf; exec apache2-foreground' >/dev/null
container="$container_name"

ready=0
for _ in $(seq 1 40); do
  if curl --silent --output /dev/null "http://127.0.0.1:$port/"; then ready=1; break; fi
  sleep 1
done
[[ "$ready" -eq 1 ]] || { docker logs "$container" >&2; echo "Apache did not become ready." >&2; exit 1; }

assert_code() {
  local path="$1" expected="$2"
  local actual
  actual="$(curl --silent --show-error -o "$tmp/response.txt" -w '%{http_code}' "http://127.0.0.1:$port$path")"
  if [[ "$actual" != "$expected" ]]; then
    echo "Expected HTTP $expected for $path, received $actual." >&2
    cat "$tmp/response.txt" >&2
    docker logs "$container" >&2 || true
    exit 1
  fi
}
assert_code "/" 200
assert_code "/.talyvane-payload.zip" 403
assert_code "/.env" 403
echo "Apache denied direct access to the private payload and environment file."
