#!/usr/bin/env bash
set -Eeuo pipefail

if [[ $# -ne 3 ]]; then
  echo "Usage: $0 STANDALONE_TAR_GZ MAIN_PUBLIC_ZIP ADDON_PUBLIC_ZIP" >&2
  exit 2
fi

standalone_archive="$1"
main_public_zip="$2"
addon_public_zip="$3"
for file in "$standalone_archive" "$main_public_zip" "$addon_public_zip"; do
  [[ -f "$file" ]] || { echo "Missing release artifact: $file" >&2; exit 2; }
done
command -v docker >/dev/null
command -v curl >/dev/null
command -v unzip >/dev/null
command -v tar >/dev/null

tmp="$(mktemp -d)"
container=""
cleanup() {
  if [[ -n "$container" ]]; then docker logs "$container" >&2 || true; docker rm -f "$container" >/dev/null 2>&1 || true; fi
  rm -rf "$tmp"
}
trap cleanup EXIT

tar -xzf "$standalone_archive" -C "$tmp"
app_source="$tmp/talyvane"
[[ -f "$app_source/cpanel-public-root/main-domain/index.php" ]]
[[ -f "$app_source/cpanel-public-root/addon-domain/index.php" ]]
[[ -f "$app_source/public/.htaccess" ]]

run_layout() {
  local kind="$1"
  local public_zip="$2"
  local layout_root="$tmp/$kind"
  local webroot="$layout_root/public-root"
  local private_root="$tmp/$kind/talyvane-app"
  local document_root="/var/www/html"
  local public_mount="$webroot"
  local container_name="talyvane-http-$kind-$RANDOM"
  if [[ "$kind" == "addon" ]]; then document_root="/var/www/html/addon-domain"; fi
  mkdir -p "$webroot" "$private_root/public" "$private_root/app" "$private_root/storage"
  unzip -q "$public_zip" -d "$webroot"
  if [[ "$kind" == "addon" ]]; then public_mount="$tmp/$kind/web-server-root"; mkdir -p "$public_mount/addon-domain"; cp -a "$webroot/." "$public_mount/addon-domain/"; fi
  cp -a "$app_source/." "$private_root/"
  # Keep the exact release's public rules and wrapper, but use a deterministic
  # front-controller fixture so this test isolates HTTP routing and path layout.
  cp "$app_source/public/.htaccess" "$private_root/public/.htaccess"
  printf '%s\n' '<?php header("Content-Type: text/plain; charset=UTF-8"); echo "TALYVANE_HTTP_FRONT_CONTROLLER_OK:" . ($_SERVER["REQUEST_URI"] ?? "/");' > "$private_root/public/index.php"
  # The public ZIP contains the corresponding wrapper at its root.
  [[ -f "$webroot/index.php" ]]
  [[ -f "$webroot/.htaccess" ]]
  [[ ! -e "$webroot/app" && ! -e "$webroot/vendor" && ! -e "$webroot/storage" && ! -e "$webroot/install" && ! -e "$webroot/.env" ]]

  docker run -d --name "$container_name" \
    -p 127.0.0.1:18080:80 \
    -e "TALYVANE_DOCUMENT_ROOT=$document_root" \
    -v "$public_mount:/var/www/html:ro" \
    -v "$private_root:/var/www/talyvane-app:ro" \
    php:8.3-apache bash -lc 'a2enmod rewrite headers >/dev/null; sed -ri "s/AllowOverride None/AllowOverride All/g" /etc/apache2/apache2.conf; sed -ri "s#DocumentRoot /var/www/html#DocumentRoot $TALYVANE_DOCUMENT_ROOT#" /etc/apache2/sites-available/000-default.conf; exec apache2-foreground' >/dev/null
  container="$container_name"
  local ready=0
  for _ in $(seq 1 40); do
    if curl --silent --output /dev/null http://127.0.0.1:18080/; then ready=1; break; fi
    sleep 1
  done
  [[ "$ready" -eq 1 ]] || { echo "Apache did not become ready for $kind layout" >&2; return 1; }

  assert_response() {
    local path="$1" expected_code="$2" expected_body="$3"
    local code body
    body="$(curl --silent --show-error --max-time 10 -o "$tmp/response.txt" -w '%{http_code}' "http://127.0.0.1:18080$path")"
    code="$body"
    body="$(cat "$tmp/response.txt")"
    if [[ "$code" != "$expected_code" || "$body" != *"$expected_body"* ]]; then
      echo "FAIL [$kind] $path: expected HTTP $expected_code containing '$expected_body'; got HTTP $code body: $body" >&2
      docker logs "$container" >&2 || true
      return 1
    fi
    echo "PASS [$kind] $path -> HTTP $code"
  }

  assert_response "/" "200" "TALYVANE_HTTP_FRONT_CONTROLLER_OK:/"
  assert_response "/index.php" "200" "TALYVANE_HTTP_FRONT_CONTROLLER_OK:/index.php"
  assert_response "/install/" "200" "TALYVANE_HTTP_FRONT_CONTROLLER_OK:/install/"
  assert_response "/up" "200" "TALYVANE_HTTP_FRONT_CONTROLLER_OK:/up"
  # A private-looking URL must be handled by the front controller, never served
  # as a physical private application file from the public document root.
  assert_response "/app/" "200" "TALYVANE_HTTP_FRONT_CONTROLLER_OK:/app/"

  docker rm -f "$container" >/dev/null
  container=""
}

run_layout main "$main_public_zip"
run_layout addon "$addon_public_zip"
echo "Apache HTTP release-layout acceptance passed for main-domain and add-on-domain bundles."
