import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { chromium } from 'playwright';

const baseUrl = process.env.TALYVANE_E2E_BASE_URL;
const mailpitApi = process.env.TALYVANE_E2E_MAILPIT_API;
const privateAppPath = process.env.TALYVANE_E2E_PRIVATE_APP_PATH;
assert.ok(baseUrl && mailpitApi && privateAppPath, 'E2E origin, Mailpit API and extracted private app path must be configured');

const adminEmail = 'founder@talyvane-e2e.example';
const recoveryEmail = 'recovery@talyvane-e2e.example';
const changedPassword = 'Talyvane-First-Login-2026!';
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function messagesFor(address) {
  const response = await fetch(`${mailpitApi}/api/v1/messages`);
  assert.equal(response.status, 200, 'Mailpit message API must be available');
  const payload = await response.json();
  const messages = payload.messages ?? payload.Messages ?? [];
  const matching = messages.filter((message) =>
    (message.To ?? message.to ?? []).some((recipient) =>
      String(recipient.Address ?? recipient.address ?? recipient).toLowerCase() === address.toLowerCase(),
    ),
  );

  const details = [];
  for (const message of matching) {
    const id = message.ID ?? message.Id ?? message.id;
    if (!id) continue;
    const detailResponse = await fetch(`${mailpitApi}/api/v1/message/${encodeURIComponent(id)}`);
    if (detailResponse.ok) details.push(await detailResponse.json());
  }
  return details;
}

async function waitForCode(address) {
  for (let attempt = 0; attempt < 40; attempt += 1) {
    const messages = await messagesFor(address);
    for (const message of messages) {
      const content = [message.Text, message.HTML, message.text, message.html, message.Snippet]
        .filter(Boolean)
        .join('\n');
      const match = content.match(/\b([0-9]{6})\b/);
      if (match) return match[1];
    }
    await sleep(500);
  }
  throw new Error(`No verification code arrived for ${address}`);
}

function base32Decode(value) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bits = '';
  for (const character of value.toUpperCase().replace(/=+$/, '')) {
    const index = alphabet.indexOf(character);
    assert.notEqual(index, -1, 'MFA secret must be valid Base32');
    bits += index.toString(2).padStart(5, '0');
  }
  let output = '';
  for (let index = 0; index + 8 <= bits.length; index += 8) {
    output += String.fromCharCode(Number.parseInt(bits.slice(index, index + 8), 2));
  }
  return Buffer.from(output, 'binary');
}

function totp(secret, timestamp = Date.now()) {
  const counter = BigInt(Math.floor(timestamp / 30000));
  const message = Buffer.alloc(8);
  message.writeBigUInt64BE(counter);
  const digest = crypto.createHmac('sha1', base32Decode(secret)).update(message).digest();
  const offset = digest[digest.length - 1] & 0x0f;
  const binary = digest.readUInt32BE(offset) & 0x7fffffff;
  return String(binary % 1_000_000).padStart(6, '0');
}

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({ ignoreHTTPSErrors: true });
const page = await context.newPage();

try {
  // The first request must extract the exact customer ZIP into the private
  // account directory before Laravel's browser installer can be displayed.
  const installResponse = await page.goto(`${baseUrl}/install/`, { waitUntil: 'networkidle' });
  assert.equal(installResponse.status(), 200, 'fresh customer ZIP must bootstrap and serve installer over HTTPS');
  assert.match(await page.title(), /Install Talyvane/i);

  const sessionDirectory = path.join(privateAppPath, 'storage/framework/sessions');
  const sessionFilesBeforeSubmit = () => fs.readdirSync(sessionDirectory).map((name) => {
    const stat = fs.statSync(path.join(sessionDirectory, name));
    return { name, size: stat.size, mode: (stat.mode & 0o777).toString(8) };
  });
  const browserCookies = await context.cookies(baseUrl);
  const initialCookies = browserCookies.map(({ name, domain, path: cookiePath, secure, sameSite, value }) => ({
    name, domain, path: cookiePath, secure, sameSite, valueLength: value.length,
  }));
  const sessionCookie = browserCookies.find((cookie) => cookie.name === 'talyvane-session');
  assert.ok(sessionCookie, 'installer must issue its session cookie');
  let sessionCookiePayload = { valid: false };
  try {
    const parsed = JSON.parse(Buffer.from(decodeURIComponent(sessionCookie.value), 'base64').toString('utf8'));
    sessionCookiePayload = {
      valid: true,
      keys: Object.keys(parsed),
      ivLength: typeof parsed.iv === 'string' ? parsed.iv.length : 0,
      encryptedValueLength: typeof parsed.value === 'string' ? parsed.value.length : 0,
      macLength: typeof parsed.mac === 'string' ? parsed.mac.length : 0,
    };
  } catch {}
  const initialSessionFiles = sessionFilesBeforeSubmit();
  const sessionFile = initialSessionFiles.find((file) => file.name !== '.gitkeep');
  assert.ok(sessionFile, 'installer must persist its file-backed session');
  const phpSessionInspector = [
    'require "vendor/autoload.php";',
    '$app = require "bootstrap/app.php";',
    '$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();',
    '$decrypted = app("encrypter")->decryptString(rawurldecode(getenv("TALYVANE_E2E_SESSION_COOKIE")));',
    '$sessionId = substr($decrypted, strrpos($decrypted, "|") + 1);',
    '$store = app("session")->driver();',
    '$store->setId($sessionId);',
    '$store->start();',
    'echo json_encode(["cookie_session_file_exists" => is_file(storage_path("framework/sessions/".$sessionId)), "cookie_session_matches_listed_file" => hash_equals($sessionId, getenv("TALYVANE_E2E_SESSION_ID")), "cookie_session_id_hash" => hash("sha256", $sessionId), "has_started_at" => $store->has("installer.started_at"), "keys" => array_keys($store->all())]);',
  ].join('\n');
  const initialSessionInspection = JSON.parse(execFileSync('php', ['-r', phpSessionInspector], {
    cwd: privateAppPath,
    env: { ...process.env, TALYVANE_E2E_SESSION_ID: sessionFile.name, TALYVANE_E2E_SESSION_COOKIE: sessionCookie.value },
    encoding: 'utf8',
  }));

  const installerKeyPath = path.join(privateAppPath, 'storage/app/private/INSTALLER-ACCESS-KEY.txt');
  const customerDocroot = process.env.TALYVANE_E2E_DOCROOT;
  assert.ok(customerDocroot, 'the exact extracted customer document root must be configured');
  assert.ok(path.relative(customerDocroot, privateAppPath).startsWith('..'), 'private Laravel application must be outside the public document root');
  assert.equal(fs.existsSync(path.join(customerDocroot, '.talyvane-payload.zip')), false, 'first request must remove the temporary private payload');
  assert.equal(fs.existsSync(path.join(customerDocroot, 'vendor')), false, 'Composer dependencies must never be exposed in the document root');
  assert.equal(fs.existsSync(path.join(customerDocroot, 'composer.json')), false, 'Composer manifest must never be exposed in the document root');
  const installerKey = fs.readFileSync(installerKeyPath, 'utf8').trim();
  assert.match(installerKey, /^[A-Za-z0-9_-]{43}$/);

  const blockedChecks = await page.locator('.install-checks li').evaluateAll((items) =>
    items.filter((item) => item.innerText.includes('BLOCKED')).map((item) => item.innerText),
  );
  assert.deepEqual(blockedChecks, [], `all hosting preflight checks must pass: ${blockedChecks.join('; ')}`);

  const fields = {
    installer_token: installerKey,
    app_name: 'Talyvane E2E',
    app_url: baseUrl,
    db_host: process.env.TALYVANE_E2E_DB_HOST,
    db_port: process.env.TALYVANE_E2E_DB_PORT,
    db_database: process.env.TALYVANE_E2E_DB_NAME,
    db_username: process.env.TALYVANE_E2E_DB_USER,
    db_password: process.env.TALYVANE_E2E_DB_PASSWORD,
    mail_scheme: 'smtp',
    mail_host: '127.0.0.1',
    mail_port: '1025',
    mail_username: 'e2e-user',
    mail_password: 'e2e-password',
    mail_from_address: 'no-reply@talyvane-e2e.example',
    mail_from_name: 'Talyvane E2E',
    admin_name: 'Talyvane E2E Founder',
    admin_email: adminEmail,
    recovery_email: recoveryEmail,
  };

  for (const [name, value] of Object.entries(fields)) {
    const field = page.locator(`[name="${name}"]`);
    if (name === 'mail_scheme') {
      await field.selectOption(String(value));
    } else {
      await field.fill(String(value));
    }
  }

  const submitResponsePromise = page.waitForResponse((response) =>
    response.request().method() === 'POST' && new URL(response.url()).pathname === '/install',
  );
  await page.getByRole('button', { name: /Verify configuration and continue/i }).click();
  const submitResponse = await submitResponsePromise;
  await page.waitForLoadState('networkidle');

  if (!page.url().endsWith('/install/verify')) {
    const body = await page.locator('body').innerText();
    const alert = await page.locator('[role="alert"]').allInnerTexts();
    let applicationLog = '';
    try {
      applicationLog = fs.readFileSync(path.join(privateAppPath, 'storage/logs/laravel.log'), 'utf8').slice(-10000);
    } catch {}
    throw new Error([
      'Installer submission did not advance to email verification.',
      `HTTP status: ${submitResponse.status()}`,
      `Current URL: ${page.url()}`,
      `Visible errors: ${alert.join(' | ')}`,
      `Page text: ${body}`,
      `Initial session cookies: ${JSON.stringify(initialCookies)}`,
      `Session cookie payload shape: ${JSON.stringify(sessionCookiePayload)}`,
      `Initial session files: ${JSON.stringify(initialSessionFiles)}`,
      `Initial session inspection: ${JSON.stringify(initialSessionInspection)}`,
      `POST cookie names: ${String((await submitResponse.request().allHeaders()).cookie ?? '').split(';').filter(Boolean).map((part) => part.trim().split('=')[0]).join(',')}`,
      `POST session cookie matches initial: ${String((await submitResponse.request().allHeaders()).cookie ?? '').split(';').map((part) => part.trim()).some((part) => part.startsWith('talyvane-session=') && decodeURIComponent(part.slice('talyvane-session='.length)) === decodeURIComponent(sessionCookie.value))}`,
      `Session settings: ${fs.readFileSync(path.join(privateAppPath, '.env'), 'utf8').split('\n').filter((line) => /^SESSION_(DRIVER|ENCRYPT|SECURE_COOKIE|HTTP_ONLY|SAME_SITE)=/.test(line)).join('; ')}`,
      `Sanitized application log tail: ${applicationLog}`,
    ].join('\n'));
  }

  const postSubmitCookies = await context.cookies(baseUrl);
  assert.ok(postSubmitCookies.some((cookie) => cookie.name === 'talyvane-session'), 'installer session cookie must remain stable after APP_NAME changes');
  assert.equal(postSubmitCookies.some((cookie) => cookie.name === 'talyvane-e2e-session'), false, 'deployment branding must not rename the installer session cookie');
  assert.match(await page.locator('body').innerText(), /Verify\s+ownership/i);

  const [primaryCode, recoveryCode] = await Promise.all([
    waitForCode(adminEmail),
    waitForCode(recoveryEmail),
  ]);
  assert.notEqual(primaryCode, recoveryCode, 'two independent addresses must receive distinct codes');

  // A failed verification attempt must not complete provisioning.
  const invalidCode = ['000000', '000001', '000002'].find((candidate) =>
    candidate !== primaryCode && candidate !== recoveryCode,
  );
  await page.locator('[name="primary_code"]').fill(invalidCode);
  await page.locator('[name="recovery_code"]').fill(invalidCode);
  await page.getByRole('button', { name: /Verify and complete installation/i }).click();
  await page.waitForSelector('[role="alert"]');
  assert.match(await page.locator('[role="alert"]').innerText(), /invalid/i);

  await page.locator('[name="primary_code"]').fill(primaryCode);
  await page.locator('[name="recovery_code"]').fill(recoveryCode);
  await Promise.all([
    page.waitForURL('**/install/success'),
    page.getByRole('button', { name: /Verify and complete installation/i }).click(),
  ]);

  assert.match(await page.locator('body').innerText(), /INSTALLATION VERIFIED/i);
  assert.match(await page.locator('body').innerText(), /automatically removed/i);
  const password = (await page.locator('.install-secret code').innerText()).trim();
  assert.match(password, /^[A-Za-z0-9_-]{60,}$/);

  assert.equal(fs.existsSync(installerKeyPath), false, 'one-time installer access key must be consumed');
  assert.equal(fs.existsSync(path.join(privateAppPath, 'install')), false, 'private installer directory must be removed');
  assert.equal(fs.existsSync(path.join(privateAppPath, 'storage/app/private/talyvane.install.lock')), true, 'durable signed install lock must exist');
  assert.equal((fs.statSync(path.join(privateAppPath, '.env')).mode & 0o777).toString(8), '600', 'environment file must remain private');

  const lockedInstaller = await context.request.get(`${baseUrl}/install/`);
  assert.equal(lockedInstaller.status(), 404, 'installer routes must be inaccessible after completion');
  const replayedHandoff = await context.request.get(`${baseUrl}/install/success`);
  assert.equal(replayedHandoff.status(), 404, 'initial password handoff must be one-time');

  await page.goto(`${baseUrl}/admin/login`, { waitUntil: 'networkidle' });
  await page.locator('[name="email"]').fill(adminEmail);
  await page.locator('[name="password"]').fill(password);
  await page.getByRole('button', { name: /Sign in/i }).click();
  await page.waitForURL('**/admin/first-login/password');

  await page.locator('[name="password"]').fill(changedPassword);
  await page.locator('[name="password_confirmation"]').fill(changedPassword);
  await page.getByRole('button', { name: /Replace password and continue/i }).click();
  await page.waitForURL('**/admin/mfa/setup');
  assert.match(await page.locator('body').innerText(), /authenticator app/i);

  const secret = (await page.locator('.mfa-secret').innerText()).trim();
  assert.match(secret, /^[A-Z2-7]{32}$/);
  await page.locator('[name="code"]').fill(totp(secret));
  await page.getByRole('button', { name: /Enable MFA/i }).click();
  await page.waitForURL('**/admin/mfa/recovery-codes');

  const recoveryCodes = await page.locator('.mfa-recovery-codes li').allInnerTexts();
  assert.equal(recoveryCodes.length, 10, 'ten one-time MFA recovery codes must be handed off');
  assert.match(await page.locator('body').innerText(), /Offline deployment recovery key/i);
  assert.match(await page.locator('.install-secret code').innerText(), /^[a-f0-9]{64}$/i);

  const dashboardResponse = await page.goto(`${baseUrl}/admin`, { waitUntil: 'networkidle' });
  assert.equal(dashboardResponse.status(), 200, 'MFA-enrolled Super Admin must reach administration');

  console.log('PASS: HTTPS preflight, MySQL migrations, SMTP delivery to two addresses, invalid-code rejection, Super Admin provisioning, one-time password handoff, installer lock/removal, first-login password replacement, TOTP MFA enrollment and recovery handoff.');
} finally {
  await context.close();
  await browser.close();
}
