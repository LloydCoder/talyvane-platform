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
  const requiredChecks = await page.locator('.check').evaluateAll((items) =>
    items.filter((item) => item.querySelector('strong')?.innerText === 'Required').map((item) => item.innerText),
  );
  assert.deepEqual(requiredChecks, [], `Native installer preflight must be green: ${requiredChecks.join('; ')}`);
  assert.equal(await page.getByRole('button', { name: /Test services and send verification codes/i }).isDisabled(), false,
    'The installer submit button must be enabled after preflight.');

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
  const invalidCodeMessage = await page.locator('[role="alert"]').innerText();
  assert.match(invalidCodeMessage, /Both verification codes must be correct and current/i,
    'Invalid codes receive the generic dual-verification failure message.');
  assert.equal(new URL(page.url()).pathname, '/install/verify',
    'Invalid codes must leave the installer on the verification step without activating the administrator.');
  assert.ok(!invalidCodeMessage.includes(adminEmail) && !invalidCodeMessage.includes(recoveryEmail),
    'Invalid-code feedback must not disclose either verification address.');

  await page.locator('[name="primary_code"]').fill(primaryCode);
  await page.locator('[name="recovery_code"]').fill(recoveryCode);
  await Promise.all([
    page.waitForURL('**/install/success'),
    page.getByRole('button', { name: /Verify and activate Super Admin/i }).click(),
  ]);
  assert.match(await page.locator('body').innerText(), /Your platform is ready/i);
  const credentialBlocks = await page.locator('.secret').evaluateAll((blocks) =>
    blocks.map((block) => ({
      label: block.querySelector('label')?.textContent?.trim() ?? '',
      value: block.querySelector('code')?.textContent?.trim() ?? '',
    })),
  );
  const initialPassword = credentialBlocks.find((item) => item.label.includes('Initial Super Admin password'))?.value ?? '';
  const offlineRecoveryKey = credentialBlocks.find((item) => item.label.includes('Offline Super Admin recovery key'))?.value ?? '';
  assert.ok(initialPassword.length >= 32 && offlineRecoveryKey.length === 43, `Credential handoff shape mismatch (password length ${initialPassword.length}, recovery-key length ${offlineRecoveryKey.length}, labels ${JSON.stringify(credentialBlocks.map((item) => item.label))}).`);
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
  async function registerAndLogin(email, accountType) {
    const portalPassword = 'Native-Portal-E2E-Password-2026!';
    await page.goto(baseUrl + '/portal/register', { waitUntil: 'networkidle' });
    await page.locator('[name="account_type"]').selectOption(accountType);
    await page.locator('[name="email"]').fill(email);
    await page.locator('[name="password"]').fill(portalPassword);
    const registrationResponsePromise = page.waitForResponse((response) =>
      response.request().method() === 'POST' && new URL(response.url()).pathname === '/portal/register',
    );
    await page.getByRole('button', { name: /Create account/i }).click();
    const registrationResponse = await registrationResponsePromise;
    await page.waitForLoadState('networkidle');
    const verificationField = page.locator('form[action="/portal/verify"] [name="code"]');
    if (!(await verificationField.isVisible().catch(() => false))) {
      throw new Error([
        'Portal registration did not advance to email verification.',
        `HTTP status: ${registrationResponse.status()}`,
        `Current URL: ${page.url()}`,
        `Visible errors: ${(await page.locator('[role="alert"]').allInnerTexts()).join(' | ')}`,
        `Page text: ${await page.locator('body').innerText()}`,
      ].join('\\n'));
    }
    const verificationCode = await waitForCode(email);
    await page.locator('form[action="/portal/verify"] [name="code"]').fill(verificationCode);
    await page.getByRole('button', { name: /Verify email/i }).click();
    await page.waitForSelector('form[action="/portal/login"]');
    await page.goto(baseUrl + '/portal/login', { waitUntil: 'networkidle' });
    await page.locator('[name="email"]').fill(email);
    await page.locator('[name="password"]').fill(portalPassword);
    await page.getByRole('button', { name: /Sign in securely/i }).click();
    await page.waitForURL('**/portal');
    return portalPassword;
  }

  async function signOutPortal() {
    await page.goto(baseUrl + '/portal', { waitUntil: 'networkidle' });
    await page.getByRole('button', { name: /Sign out securely/i }).click();
    await page.waitForURL('**/portal/login');
  }

  const clientEmail = 'client@talyvane-native-e2e.example';
  await registerAndLogin(clientEmail, 'client');
  await page.goto(baseUrl + '/portal/calendar', { waitUntil: 'networkidle' });
  assert.equal(await page.locator('h1').innerText(), 'Your booking calendar.');
  assert.match(await page.locator('body').innerText(), /There are no active or recently changed bookings/);
  const clientCalendarResponse = await context.request.get(baseUrl + '/portal/calendar.ics');
  assert.equal(clientCalendarResponse.status(), 200, 'Authenticated clients can export their private calendar.');
  assert.match(clientCalendarResponse.headers()['content-type'] ?? '', /text\/calendar/i);
  assert.match(clientCalendarResponse.headers()['cache-control'] ?? '', /private.*no-store|no-store.*private/i);
  const clientIcs = await clientCalendarResponse.text();
  assert.match(clientIcs, /BEGIN:VCALENDAR[\s\S]*END:VCALENDAR/);
  assert.ok(!clientIcs.includes(clientEmail), 'Client calendar export must not include the client email address.');
  await page.goto(baseUrl + '/portal/companion/calendar', { waitUntil: 'networkidle' });
  assert.equal(new URL(page.url()).pathname, '/portal/login', 'Client sessions cannot open the companion calendar.');
  await signOutPortal();

  const companionEmail = 'companion@talyvane-native-e2e.example';
  await registerAndLogin(companionEmail, 'companion');
  await page.goto(baseUrl + '/portal/companion/calendar', { waitUntil: 'networkidle' });
  assert.equal(await page.locator('h1').innerText(), 'Your schedule.');
  assert.match(await page.locator('body').innerText(), /There are no active or recently changed bookings/);
  const companionCalendarResponse = await context.request.get(baseUrl + '/portal/companion/calendar.ics');
  assert.equal(companionCalendarResponse.status(), 200, 'Authenticated companions can export their private calendar.');
  assert.match(companionCalendarResponse.headers()['content-type'] ?? '', /text\/calendar/i);
  assert.match(companionCalendarResponse.headers()['cache-control'] ?? '', /private.*no-store|no-store.*private/i);
  const companionIcs = await companionCalendarResponse.text();
  assert.match(companionIcs, /BEGIN:VCALENDAR[\s\S]*END:VCALENDAR/);
  assert.ok(!companionIcs.includes(clientEmail) && !companionIcs.includes(companionEmail),
    'Companion calendar export must not expose either participant email address.');
  await page.goto(baseUrl + '/portal/calendar', { waitUntil: 'networkidle' });
  assert.equal(new URL(page.url()).pathname, '/portal/login', 'Companion sessions cannot open the client calendar.');

  console.log('PASS: Native PHP one-ZIP extraction, private key, HTTPS installer, live MySQL migrations, authenticated SMTP, dual-email verification, browser-only credential handoff, lock/cleanup, first-login password replacement and MFA enrollment; client and companion registration, email verification, login, role-isolated private calendar pages and no-store iCalendar exports.');
} finally {
  await context.close();
  await browser.close();
}
