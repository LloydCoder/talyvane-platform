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
  -p "127.0.0.1:$port:443" \
  -e HOME=/var/talyvane-account \
  -v "$account:/var/talyvane-account" \
  php:8.3-apache bash -lc '
    set -e
    apt-get update -qq
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev libonig-dev libxml2-dev libcurl4-openssl-dev openssl
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp
    docker-php-ext-install -j2 curl gd mbstring pdo_mysql zip
    a2enmod rewrite headers env ssl >/dev/null
    sed -ri "s/AllowOverride None/AllowOverride All/g" /etc/apache2/apache2.conf
    mkdir -p /etc/apache2/ssl
    openssl req -x509 -nodes -days 1 -newkey rsa:2048 -keyout /etc/apache2/ssl/talyvane.key -out /etc/apache2/ssl/talyvane.crt -subj "/CN=localhost" >/dev/null 2>&1
    grep -Eq "^[[:space:]]*Listen[[:space:]]+443([[:space:]]|$)" /etc/apache2/ports.conf || printf "\\nListen 443\\n" >> /etc/apache2/ports.conf
    cat > /etc/apache2/sites-available/talyvane-ssl.conf <<APACHE
<VirtualHost *:443>
    ServerName localhost
    DocumentRoot /var/talyvane-account/public_html
    SSLEngine on
    SSLCertificateFile /etc/apache2/ssl/talyvane.crt
    SSLCertificateKeyFile /etc/apache2/ssl/talyvane.key
    <Directory /var/talyvane-account/public_html>
        Options FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
APACHE
    a2dissite 000-default >/dev/null
    a2ensite talyvane-ssl >/dev/null
    chown -R www-data:www-data /var/talyvane-account
    exec apache2-foreground
  ' >/dev/null
container="$container_name"

ready=0
for _ in $(seq 1 90); do
  if curl --insecure --silent --output /dev/null "https://127.0.0.1:$port/css/app.css"; then ready=1; break; fi
  sleep 1
done
[[ "$ready" -eq 1 ]] || { docker logs "$container" >&2; echo "Apache/PHP container did not become ready." >&2; exit 1; }

payload_code="$(curl --insecure --silent --show-error -o "$tmp/payload-response.txt" -w '%{http_code}' "https://127.0.0.1:$port/.talyvane-payload.zip")"
[[ "$payload_code" == "403" ]] || { echo "Private payload should be denied before bootstrap; got HTTP $payload_code." >&2; cat "$tmp/payload-response.txt" >&2; exit 1; }

set +e
install_code="$(curl --insecure --silent --show-error --max-time 30 -H 'Host: localhost' -o "$tmp/installer.html" -w '%{http_code}' "https://127.0.0.1:$port/install" 2>"$tmp/install-curl.stderr")"
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
# from the unprivileged GitHub runner. The first-request bootstrap creates a
# private .env and one-time installer key, both of which must be mode 0600.
docker exec "$container" sh -lc '
  set -eu
  app="$(find /var/talyvane-account/.talyvane/apps -type f -path "*/talyvane-app/vendor/autoload.php" -print -quit | sed "s#/vendor/autoload.php##")"
  test -n "$app"
  test -f "$app/public/index.php"
  test -f "$app/.env.example"
  test -f "$app/.env"
  test -f "$app/storage/app/private/INSTALLER-ACCESS-KEY.txt"
  test "$(stat -c "%a" "$app/.env")" = 600
  test "$(stat -c "%a" "$app/storage/app/private/INSTALLER-ACCESS-KEY.txt")" = 600
  test ! -L "$app"
  test ! -L "$app/vendor"
  test ! -L "$app/vendor/autoload.php"
'
echo "Private application, generated environment and installer key exist outside the web root with mode 0600 secrets."
for forbidden in "$webroot/.talyvane-payload.zip" "$webroot/vendor" "$webroot/.env"; do
  if [[ -e "$forbidden" ]]; then
    echo "Private file remained in public document root: $forbidden" >&2
    exit 1
  fi
done
echo "Private payload and environment files are absent from the web root."

asset_code="$(curl --insecure --silent --show-error -o "$tmp/asset.css" -w '%{http_code}' "https://127.0.0.1:$port/css/app.css")"
[[ "$asset_code" == "200" ]] || { echo "Public CSS should be served from the document root; got HTTP $asset_code." >&2; exit 1; }

echo "Single-ZIP package passed Apache/PHP HTTPS installation preflight and private-file isolation."
