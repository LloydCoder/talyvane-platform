import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';

const baseUrl = process.env.TALYVANE_NATIVE_E2E_BASE_URL;
const mailpitApi = process.env.TALYVANE_NATIVE_E2E_MAILPIT_API;
const home = process.env.TALYVANE_NATIVE_E2E_HOME;
const documentRoot = process.env.TALYVANE_NATIVE_E2E_DOCROOT;
assert.ok(baseUrl && mailpitApi && home && documentRoot, 'Native installer E2E environment is incomplete.');

const adminEmail = 'founder@talyvane-native-e2e.example';
const recoveryEmail = 'recovery@talyvane-native-e2e.example';
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function messagesFor(address) {
  const response = await fetch(`${mailpitApi}/api/v1/messages`);
  assert.equal(response.status, 200, 'Mailpit API must be available.');
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
    const detail = await fetch(`${mailpitApi}/api/v1/message/${encodeURIComponent(id)}`);
    if (detail.ok) details.push(await detail.json());
  }
  return details;
}

async function waitForCode(address) {
  for (let attempt = 0; attempt < 50; attempt += 1) {
    for (const message of await messagesFor(address)) {
      const body = [message.Text, message.HTML, message.text, message.html, message.Snippet].filter(Boolean).join('\n');
      const match = body.match(/\b([0-9]{6})\b/);
      if (match) return match[1];
    }
    await sleep(400);
  }
  throw new Error(`No native installer verification code arrived for ${address}.`);
}

function base32Decode(value) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bits = '';
  for (const character of value.toUpperCase().replace(/=+$/, '')) {
    const index = alphabet.indexOf(character);
    assert.notEqual(index, -1, 'MFA secret must be valid Base32.');
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

const realDocumentRoot = fs.realpathSync(documentRoot);
const fingerprint = crypto.createHash('sha256').update(realDocumentRoot).digest('hex').slice(0, 24);
const privateConfig = path.join(home, '.talyvane', 'talyvane', fingerprint, 'config');
const setupKeyPath = path.join(privateConfig, 'INSTALLER-ACCESS-KEY.txt');
const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({ ignoreHTTPSErrors: true });
const page = await context.newPage();

try {
  await page.goto(baseUrl, { waitUntil: 'networkidle' });
  await page.waitForURL('**/install');
  assert.match(await page.title(), /Secure installation/i, 'Native package must extract privately and route to its own browser installer.');
  assert.ok(fs.existsSync(setupKeyPath), 'The one-time setup key must be created outside the public root.');
  assert.ok(path.relative(realDocumentRoot, privateConfig).startsWith('..'), 'Installer secrets must remain outside the public root.');
  assert.equal((fs.statSync(setupKeyPath).mode & 0o777).toString(8), '600', 'Setup key must have owner-only permissions.');
  const setupKey = fs.readFileSync(setupKeyPath, 'utf8').trim();
  assert.match(setupKey, /^[A-Za-z0-9_-]{43}$/, 'Setup key must contain 256 bits of entropy.');
  assert.equal((await page.locator('body').innerText()).includes(setupKey), false, 'Setup key must never be rendered into the browser.');

  const fields = {
    setup_key: setupKey,
    app_name: 'Talyvane Native E2E',
    app_url: baseUrl,
    db_host: '127.0.0.1',
    db_port: '3306',
    db_name: process.env.TALYVANE_NATIVE_E2E_DB_NAME,
    db_user: process.env.TALYVANE_NATIVE_E2E_DB_USER,
    db_password: process.env.TALYVANE_NATIVE_E2E_DB_PASSWORD,
    mail_host: '127.0.0.1',
    mail_port: '1025',
    mail_encryption: 'tls',
    mail_username: 'native-e2e',
    mail_password: 'native-e2e-password',
    mail_from_email: 'no-reply@talyvane-native-e2e.example',
    mail_from_name: 'Talyvane Native E2E',
    admin_email: adminEmail,
    recovery_email: recoveryEmail,
  };
  for (const [name, value] of Object.entries(fields)) {
    const field = page.locator(`[name="${name}"]`);
    if (name === 'mail_encryption') await field.selectOption(String(value));
    else await field.fill(String(value));
  }
  await page.locator('[name="installation_confirmed"]').check();
  const setupResponsePromise = page.waitForResponse((response) =>
    response.request().method() === 'POST' && new URL(response.url()).pathname === '/install',
  );
  await page.getByRole('button', { name: /Test services and send verification codes/i }).click();
  const setupResponse = await setupResponsePromise;
  await page.waitForLoadState('networkidle');
  if (!page.url().endsWith('/install/verify')) {
    const alert = await page.locator('[role="alert"]').allInnerTexts();
    const pageText = await page.locator('body').innerText();
    throw new Error([
      'Native installer did not advance to dual-email verification.',
      `HTTP status: ${setupResponse.status()}`,
      `Current URL: ${page.url()}`,
      `Visible errors: ${alert.join(' | ')}`,
      `Page text: ${pageText}`,
    ].join('\n'));
  }

  const [primaryCode, recoveryCode] = await Promise.all([waitForCode(adminEmail), waitForCode(recoveryEmail)]);
  assert.notEqual(primaryCode, recoveryCode, 'The two independent mailboxes must receive distinct codes.');
  const invalidCode = ['000000', '000001', '000002'].find((candidate) => candidate !== primaryCode && candidate !== recoveryCode);
  await page.locator('[name="primary_code"]').fill(invalidCode);
  await page.locator('[name="recovery_code"]').fill(invalidCode);
  await page.getByRole('button', { name: /Verify and activate Super Admin/i }).click();
  await page.waitForSelector('[role="alert"]');
  assert.match(await page.locator('[role="alert"]').innerText(), /invalid/i, 'Invalid codes must not activate the administrator.');

  await page.locator('[name="primary_code"]').fill(primaryCode);
  await page.locator('[name="recovery_code"]').fill(recoveryCode);
  await Promise.all([
    page.waitForURL('**/install/success'),
    page.getByRole('button', { name: /Verify and activate Super Admin/i }).click(),
  ]);
  assert.match(await page.locator('body').innerText(), /Your platform is ready/i);
  const credentialBlocks = await page.locator('.secret').evaluateAll((blocks) =>
    blocks.map((block) => ({
      label: block.querySelector('label')?.innerText ?? '',
      value: block.querySelector('code')?.innerText ?? '',
    })),
  );
  const initialPassword = credentialBlocks.find((item) => item.label.includes('Initial Super Admin password'))?.value ?? '';
  const offlineRecoveryKey = credentialBlocks.find((item) => item.label.includes('Offline Super Admin recovery key'))?.value ?? '';
  assert.ok(initialPassword.length >= 32 && offlineRecoveryKey.length === 43, 'Both generated credentials must be shown exactly once in the browser.');
  assert.equal(await page.locator('body').innerText().then((text) => text.includes('cPanel Terminal')), false, 'Installation must not require terminal access.');
  assert.equal((await page.evaluate(() => document.querySelector('meta[name="robots"]')?.content)), 'noindex,nofollow');
  assert.match(await page.evaluate(() => document.querySelector('meta[charset]')?.outerHTML ?? ''), /charset/i);
  assert.ok((await page.evaluate(() => document.cookie)).length === 0, 'Session cookie must be HttpOnly.');
  assert.equal(fs.existsSync(setupKeyPath), false, 'Setup key must be consumed after activation.');
  assert.ok(fs.existsSync(path.join(privateConfig, 'INSTALLATION-LOCK.json')), 'Permanent installer lock must exist.');
  assert.equal(fs.existsSync(path.join(realDocumentRoot, '.talyvane-native-payload.zip')), false, 'Temporary payload must be removed from the public root.');
  const replay = await context.request.get(`${baseUrl}/install/success`);
  assert.equal(replay.status(), 410, 'One-time credentials must not be retrievable twice.');

  await page.goto(`${baseUrl}/admin/login`, { waitUntil: 'networkidle' });
  await page.locator('[name="email"]').fill(adminEmail);
  await page.locator('[name="password"]').fill(initialPassword);
  await page.getByRole('button', { name: /Continue securely/i }).click();
  await page.waitForURL('**/admin/first-login');
  const newPassword = 'Native-E2E-First-Login-2026!';
  await page.locator('[name="current_password"]').fill(initialPassword);
  await page.locator('[name="new_password"]').fill(newPassword);
  await page.locator('[name="confirm_password"]').fill(newPassword);
  await page.getByRole('button', { name: /Replace initial password/i }).click();
  await page.waitForURL('**/admin/mfa/setup');
  const secret = (await page.locator('.code').innerText()).trim();
  assert.match(secret, /^[A-Z2-7]{32}$/);
  await page.locator('[name="code"]').fill(totp(secret));
  await page.getByRole('button', { name: /Enable two-factor authentication/i }).click();
  await page.waitForURL('**/admin/recovery-codes');
  assert.equal(await page.locator('.codes code').count(), 10, 'MFA recovery codes must be handed off once.');
  await page.getByRole('link', { name: /Continue to control room/i }).click();
  await page.waitForURL('**/admin');
  assert.match(await page.locator('body').innerText(), /Control room/i);
  console.log('PASS: Native PHP one-ZIP extraction, private key, HTTPS installer, live MySQL migrations, authenticated SMTP, dual-email verification, browser-only credential handoff, lock/cleanup, first-login password replacement and MFA enrollment.');
} finally {
  await context.close();
  await browser.close();
}
