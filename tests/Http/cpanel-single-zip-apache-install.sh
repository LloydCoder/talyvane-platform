#!/usr/bin/env bash
set -euxo pipefail

archive="${1:?usage: cpanel-single-zip-apache-install.sh <customer-archive.zip>}"
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
webroot="$account/public_html"
mkdir -p "$webroot"
unzip -q "$archive" -d "$webroot"

port="$((20000 + RANDOM % 20000))"
container_name="talyvane-single-zip-install-$RANDOM"
docker run -d --name "$container_name" \
  -p "127.0.0.1:$port:80" \
  -e HOME=/var/talyvane-account \
  -v "$account:/var/talyvane-account" \
  php:8.3-apache bash -lc '
    set -e
    apt-get update -qq
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev libonig-dev libxml2-dev libcurl4-openssl-dev
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp
    docker-php-ext-install -j2 curl gd mbstring pdo_mysql zip
    a2enmod rewrite headers env >/dev/null
    sed -ri "s/AllowOverride None/AllowOverride All/g" /etc/apache2/apache2.conf
    cat >> /etc/apache2/apache2.conf <<APACHE
<Directory /var/talyvane-account/public_html>
    Options FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
APACHE
    sed -ri "s#DocumentRoot /var/www/html#DocumentRoot /var/talyvane-account/public_html#" /etc/apache2/sites-available/000-default.conf
    printf "\nSetEnv HTTPS on\n" >> /etc/apache2/sites-available/000-default.conf
    chown -R www-data:www-data /var/talyvane-account
    exec apache2-foreground
  ' >/dev/null
container="$container_name"

ready=0
for _ in $(seq 1 90); do
  if curl --silent --output /dev/null "http://127.0.0.1:$port/css/app.css"; then ready=1; break; fi
  sleep 1
done
[[ "$ready" -eq 1 ]] || { docker logs "$container" >&2; echo "Apache/PHP container did not become ready." >&2; exit 1; }

payload_code="$(curl --silent --show-error -o "$tmp/payload-response.txt" -w '%{http_code}' "http://127.0.0.1:$port/.talyvane-payload.zip")"
[[ "$payload_code" == "403" ]] || { echo "Private payload should be denied before bootstrap; got HTTP $payload_code." >&2; cat "$tmp/payload-response.txt" >&2; exit 1; }

set +e
install_code="$(curl --silent --show-error --max-time 30 -H 'Host: localhost' -o "$tmp/installer.html" -w '%{http_code}' "http://127.0.0.1:$port/install" 2>"$tmp/install-curl.stderr")"
curl_status=$?
set -e
if [[ "$curl_status" -ne 0 ]]; then
  echo "curl failed while requesting the installer (exit $curl_status); HTTP status captured: $install_code" >&2
  cat "$tmp/install-curl.stderr" >&2 || true
  docker logs "$container" >&2 || true
  exit 1
fi
[[ "$install_code" == "200" ]] || { docker logs "$container" >&2; echo "Expected packaged installer HTTP 200, got $install_code." >&2; cat "$tmp/installer.html" >&2; exit 1; }

if ! grep -Fq 'FIRST-TIME SETUP' "$tmp/installer.html" || ! grep -Fq 'Hosting preflight' "$tmp/installer.html"; then
  echo "Apache returned HTTP 200 but the Laravel installer page was not rendered." >&2
  cat "$tmp/installer.html" >&2
  docker logs "$container" >&2 || true
  exit 1
fi
echo "Apache Laravel installer page markers passed."
if grep -Fq 'BLOCKED' "$tmp/installer.html"; then
  echo "Apache/PHP hosting preflight reported blocked requirements:" >&2
  grep -F -B1 -A1 'BLOCKED' "$tmp/installer.html" >&2
  exit 1
fi
echo "All Apache/PHP preflight checks passed."
# The private application is owned by the PHP/Apache account (www-data).
# Inspect it inside the container rather than bypassing its filesystem permissions
# from the unprivileged GitHub runner. The installer creates .env during setup;
# the package must keep .env.example private and must not publish a premature .env.
docker exec "$container" sh -lc '
  set -eu
  test -f /var/talyvane-account/talyvane-app/vendor/autoload.php
  test -f /var/talyvane-account/talyvane-app/public/index.php
  test -f /var/talyvane-account/talyvane-app/.env.example
  test ! -L /var/talyvane-account/talyvane-app
  test ! -L /var/talyvane-account/talyvane-app/vendor
  test ! -L /var/talyvane-account/talyvane-app/vendor/autoload.php
'
echo "Private application files exist outside the web root and retain expected ownership-safe access."
for forbidden in "$webroot/.talyvane-payload.zip" "$webroot/vendor" "$webroot/.env"; do
  if [[ -e "$forbidden" ]]; then
    echo "Private file remained in public document root: $forbidden" >&2
    exit 1
  fi
done
echo "Private payload and environment files are absent from the web root."

asset_code="$(curl --silent --show-error -o "$tmp/asset.css" -w '%{http_code}' "http://127.0.0.1:$port/css/app.css")"
[[ "$asset_code" == "200" ]] || { echo "Public CSS should be served from the document root; got HTTP $asset_code." >&2; exit 1; }

echo "Single-ZIP package passed Apache/PHP HTTP installation preflight and private-file isolation."
