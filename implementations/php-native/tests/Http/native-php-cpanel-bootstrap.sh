#!/usr/bin/env bash
set -euo pipefail
archive="$1"
repo_root="$(cd "$(dirname "$0")/../../.." && pwd)"
tmp="$(mktemp -d)"
server_pid=""
cleanup(){ if [[ -n "$server_pid" ]]; then kill "$server_pid" 2>/dev/null || true; wait "$server_pid" 2>/dev/null || true; fi; rm -rf "$tmp"; }
trap cleanup EXIT
command -v curl >/dev/null
php -r 'exit(class_exists(ZipArchive::class)?0:1);'

account="$tmp/account"
document_root="$account/public_html"
mkdir -p "$document_root" "$tmp/payload/talyvane-native-app/public" "$tmp/payload/talyvane-native-app/vendor"
printf '%s\n' '<?php echo "TALYVANE_NATIVE_BOOTSTRAP_OK";' > "$tmp/payload/talyvane-native-app/public/index.php"
printf '%s\n' '<?php return true;' > "$tmp/payload/talyvane-native-app/vendor/autoload.php"
(
  cd "$tmp/payload"
  zip -X -q -r "$document_root/.talyvane-native-payload.zip" talyvane-native-app
)
chmod 0600 "$document_root/.talyvane-native-payload.zip"
repo_package="$repo_root/implementations/php-native/package"
cp "$repo_package/index.php" "$document_root/index.php"
cp "$repo_package/.htaccess" "$document_root/.htaccess"
port="$((20000 + RANDOM % 20000))"
HOME="$account" php -S "127.0.0.1:$port" -t "$document_root" > "$tmp/server.log" 2>&1 &
server_pid=$!
ready=0
for _ in $(seq 1 40); do
  if curl --silent --fail "http://127.0.0.1:$port/" > "$tmp/response.txt"; then ready=1; break; fi
  sleep 0.25
done
if [[ "$ready" -ne 1 ]]; then cat "$tmp/server.log" >&2; echo "Native bootstrap server did not become ready." >&2; exit 1; fi
grep -Fq 'TALYVANE_NATIVE_BOOTSTRAP_OK' "$tmp/response.txt"
private_app="$(find "$account/.talyvane/apps" -type f -path "*/talyvane-native-app/public/index.php" -print -quit | sed 's#/public/index.php##')"
test -n "$private_app"
test "$(stat -c '%a' "$private_app")" = '700'
test -f "$private_app/vendor/autoload.php"
test ! -e "$document_root/.talyvane-native-payload.zip"
test ! -e "$document_root/src"
kill "$server_pid" 2>/dev/null || true
wait "$server_pid" 2>/dev/null || true
server_pid=""
echo "Native one-ZIP bootstrap extracted the application outside public_html."

assert_rejected(){
  local kind="$1"
  local root="$tmp/attack-$kind"
  mkdir -p "$root"
  python3 - "$root/.talyvane-native-payload.zip" "$kind" <<'PY'
import stat,sys,zipfile
path,kind=sys.argv[1],sys.argv[2]
with zipfile.ZipFile(path,"w",compression=zipfile.ZIP_DEFLATED) as z:
    z.writestr("talyvane-native-app/","")
    if kind=="incomplete":
        z.writestr("talyvane-native-app/vendor/autoload.php","<?php")
    else:
        z.writestr("talyvane-native-app/public/index.php",'<?php echo "SHOULD_NOT_RUN";')
        z.writestr("talyvane-native-app/vendor/autoload.php","<?php")
        if kind=="traversal":
            z.writestr("talyvane-native-app/../../outside.txt","outside")
        else:
            info=zipfile.ZipInfo("talyvane-native-app/public/link")
            info.create_system=3
            info.external_attr=(stat.S_IFLNK|0o777)<<16
            z.writestr(info,"../../outside.txt")
PY
  chmod 0600 "$root/.talyvane-native-payload.zip"
  cp "$repo_package/index.php" "$root/index.php"
  cp "$repo_package/.htaccess" "$root/.htaccess"
  local p="$((20000 + RANDOM % 20000))"
  HOME="$root" php -S "127.0.0.1:$p" -t "$root" > "$tmp/$kind.log" 2>&1 &
  server_pid=$!
  local code=""
  for _ in $(seq 1 40); do
    code="$(curl --silent --show-error -o "$tmp/$kind.html" -w '%{http_code}' "http://127.0.0.1:$p/" || true)"
    [[ "$code" == "500" ]] && break
    sleep 0.25
  done
  if [[ "$code" != "500" ]]; then cat "$tmp/$kind.log" >&2; echo "Unsafe $kind archive was not rejected (HTTP $code)." >&2; exit 1; fi
  test -z "$(find "$root/.talyvane/apps" -type f -path "*/talyvane-native-app/public/index.php" -print -quit 2>/dev/null)"
  kill "$server_pid" 2>/dev/null || true
  wait "$server_pid" 2>/dev/null || true
  server_pid=""
  echo "Unsafe $kind native archive rejected."
}
assert_rejected traversal
assert_rejected symlink
assert_rejected incomplete
