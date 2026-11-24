#!/usr/bin/env bash
set -euxo pipefail

archive="${1:?usage: cpanel-single-zip-openlitespeed.sh <customer-archive.zip>}"
tmp="$(mktemp -d)"
chmod 755 "$tmp"
container=""
cleanup() {
  if [[ -n "$container" ]]; then docker rm -f "$container" >/dev/null 2>&1 || true; fi
  sudo chown -R "$(id -u):$(id -g)" "$tmp" 2>/dev/null || true
  rm -rf "$tmp"
}
trap cleanup EXIT

command -v docker >/dev/null
command -v curl >/dev/null
account="$tmp/account"
webroot="$account/html"
mkdir -p "$webroot"
chmod 755 "$account" "$webroot"
unzip -q "$archive" -d "$webroot"
printf '%s\\n' 'APP_KEY=OLS_PRIVATE_ROOT_SENTINEL' > "$webroot/.env"
chmod 0644 "$webroot/.env"

port="$((20000 + RANDOM % 20000))"
container_name="talyvane-single-zip-ols-$RANDOM"
docker run -d --name "$container_name" \
  -p "127.0.0.1:$port:443" \
  -e HOME=/var/www/vhosts/localhost \
  -v "$account:/var/www/vhosts/localhost" \
  litespeedtech/openlitespeed:1.8.2-lsphp83 >/dev/null
container="$container_name"

# The official OpenLiteSpeed image serves /var/www/vhosts/localhost/html
# and runs PHP through LSPHP. Give that account ownership of the mounted
# test tree, matching a per-account shared-hosting deployment.
docker exec "$container" sh -lc 'chown -R nobody:nogroup /var/www/vhosts/localhost'

ready=0
for _ in $(seq 1 120); do
  asset_code="$(curl --insecure --silent --show-error --max-time 5 -H 'Host: localhost' -o "$tmp/asset.css" -w '%{http_code}' "https://127.0.0.1:$port/css/app.css" 2>/dev/null || true)"
  if [[ "$asset_code" == "200" ]]; then ready=1; break; fi
  sleep 1
done
[[ "$ready" -eq 1 ]] || { docker logs "$container" >&2; echo "OpenLiteSpeed/LSPHP container did not serve the packaged public asset." >&2; exit 1; }

payload_code="$(curl --insecure --silent --show-error -H 'Host: localhost' -o "$tmp/payload-response.txt" -w '%{http_code}' "https://127.0.0.1:$port/.talyvane-payload.zip")"
[[ "$payload_code" == "403" ]] || { docker logs "$container" >&2; echo "OpenLiteSpeed should deny direct private payload access; got HTTP $payload_code." >&2; cat "$tmp/payload-response.txt" >&2; exit 1; }

env_code="$(curl --insecure --silent --show-error -H 'Host: localhost' -o "$tmp/env-response.txt" -w '%{http_code}' "https://127.0.0.1:$port/.env")"
[[ "$env_code" == "403" ]] || { docker logs "$container" >&2; echo "OpenLiteSpeed should deny direct .env access; got HTTP $env_code." >&2; cat "$tmp/env-response.txt" >&2; exit 1; }
if grep -Fq 'OLS_PRIVATE_ROOT_SENTINEL' "$tmp/env-response.txt"; then
  echo "OpenLiteSpeed exposed the public-root environment sentinel." >&2
  exit 1
fi

set +e
install_code="$(curl --insecure --silent --show-error --max-time 45 -H 'Host: localhost' -o "$tmp/installer.html" -w '%{http_code}' "https://127.0.0.1:$port/install" 2>"$tmp/install-curl.stderr")"
curl_status=$?
set -e
if [[ "$curl_status" -ne 0 ]]; then
  echo "curl failed while requesting the OpenLiteSpeed installer (exit $curl_status); HTTP status captured: $install_code" >&2
  cat "$tmp/install-curl.stderr" >&2 || true
  docker logs "$container" >&2 || true
  exit 1
fi
[[ "$install_code" == "200" ]] || { docker logs "$container" >&2; echo "Expected OpenLiteSpeed installer HTTP 200, got $install_code." >&2; cat "$tmp/installer.html" >&2; exit 1; }

grep -Fq 'FIRST-TIME SETUP' "$tmp/installer.html"
grep -Fq 'Hosting preflight' "$tmp/installer.html"
if grep -Fq 'BLOCKED' "$tmp/installer.html"; then
  echo "OpenLiteSpeed/LSPHP hosting preflight reported blocked requirements:" >&2
  grep -F -B1 -A1 'BLOCKED' "$tmp/installer.html" >&2
  docker logs "$container" >&2 || true
  exit 1
fi

docker exec "$container" sh -lc '
  set -eu
  app=/var/www/vhosts/localhost/talyvane-app
  test -f "$app/vendor/autoload.php"
  test -f "$app/.env"
  test -f "$app/storage/app/private/INSTALLER-ACCESS-KEY.txt"
  test "$(stat -c "%a" "$app/.env")" = 600
  test "$(stat -c "%a" "$app/storage/app/private/INSTALLER-ACCESS-KEY.txt")" = 600
'
for forbidden in "$webroot/.talyvane-payload.zip" "$webroot/vendor" "$webroot/.env"; do
  if [[ -e "$forbidden" ]]; then
    echo "Private file remained in OpenLiteSpeed public document root: $forbidden" >&2
    exit 1
  fi
done

echo "Single-ZIP package passed OpenLiteSpeed/LSPHP HTTPS installer preflight and private-file isolation."
