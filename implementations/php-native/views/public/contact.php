<?php
declare(strict_types=1);
$brandName = is_string($brandName ?? null) && $brandName !== '' ? $brandName : 'Talyvane';
$csrf = is_string($csrf ?? null) ? $csrf : '';
$error = is_string($error ?? null) ? $error : '';
$sent = ($sent ?? false) === true;
$privacyAvailable = ($privacyAvailable ?? false) === true;
$values = is_array($values ?? null) ? $values : [];
$value = static fn(string $key): string => is_scalar($values[$key] ?? null) ? (string) $values[$key] : '';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">

<title>Contact · <?= $escape($brandName) ?></title>
<link rel="canonical" href="<?= $escape($canonicalUrl ?? '') ?>">
<style>
:root{color-scheme:light;--ink:#182638;--muted:#667487;--paper:#f4f2ed;--panel:#fffefa;--line:#e6e2d9;--gold:#bd9560;--navy:#142235}*{box-sizing:border-box}body{margin:0;background:var(--paper);color:var(--ink);font-family:Inter,ui-sans-serif,system-ui,sans-serif}.top{height:74px;background:var(--navy);color:#f5f1e8;display:flex;align-items:center;justify-content:space-between;padding:0 clamp(20px,5vw,72px)}.brand{font:23px Georgia,serif;letter-spacing:.12em}.brand span{color:#d4b183}.top a{color:#e7d6bc;text-decoration:none;font-size:12px;letter-spacing:.08em}.wrap{max-width:860px;margin:0 auto;padding:clamp(36px,7vw,84px) 22px}.eyebrow{text-transform:uppercase;letter-spacing:.2em;font-size:10px;color:#9a774e;font-weight:700}.intro h1{font:clamp(40px,6vw,64px)/1.02 Georgia,serif;font-weight:400;margin:14px 0}.intro p{color:var(--muted);line-height:1.8;max-width:580px}.panel{margin-top:34px;background:var(--panel);border:1px solid var(--line);padding:clamp(20px,4vw,38px);box-shadow:0 16px 50px rgba(23,35,51,.05)}.grid{display:grid;grid-template-columns:1fr 1fr;gap:0 18px}.field{display:grid;gap:8px;margin:12px 0}.field.wide{grid-column:span 2}.field label{font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase}.field input,.field textarea{width:100%;border:1px solid #d9d5cc;background:white;border-radius:2px;padding:13px 14px;color:var(--ink);font:14px ui-sans-serif,system-ui,sans-serif}.field textarea{min-height:150px;resize:vertical;line-height:1.6}.field input:focus,.field textarea:focus{outline:3px solid rgba(189,149,96,.22);border-color:var(--gold)}.privacy{display:flex;align-items:flex-start;gap:11px;color:var(--muted);font-size:12px;line-height:1.7;margin:20px 0}.privacy input{width:17px;height:17px;accent-color:var(--navy);margin-top:2px}.button{border:0;background:var(--navy);color:white;padding:15px 22px;font-size:11px;letter-spacing:.12em;text-transform:uppercase;font-weight:700;cursor:pointer}.button:focus-visible,a:focus-visible{outline:3px solid var(--gold);outline-offset:3px}.alert{padding:14px 16px;margin-bottom:18px;font-size:13px;line-height:1.6}.error{background:#fff4f2;border:1px solid #e9c9c4;color:#8b3535}.success{background:#f0f5ef;border:1px solid #d6e2d3;color:#345b39}.trap{position:absolute!important;width:1px!important;height:1px!important;padding:0!important;margin:-1px!important;overflow:hidden!important;clip:rect(0,0,0,0)!important;white-space:nowrap!important;border:0!important}.foot{margin-top:30px;color:var(--muted);font-size:11px;line-height:1.7}@media(max-width:620px){.top{height:62px}.grid{grid-template-columns:1fr}.field.wide{grid-column:auto}.panel{padding:18px}.button{width:100%}}@media(prefers-reduced-motion:reduce){*,*:before,*:after{scroll-behavior:auto!important;transition:none!important}}
</style>
</head>
<body>
<header class="top"><a class="brand" href="/"><?= $escape($brandName) ?><span>.</span></a><a href="/">RETURN TO SITE</a></header>
<main class="wrap">
<section class="intro"><div class="eyebrow">A considered conversation</div><h1>How may we help?</h1><p>Send a private enquiry to our team. Share only the information needed for us to understand your request. We will use your details to respond to this enquiry.</p></section>
<section class="panel">
<?php if ($sent): ?><div class="alert success" role="status">Your enquiry has been received. Thank you for contacting <?= $escape($brandName) ?>.</div><?php endif; ?>
<?php if ($error !== ''): ?><div class="alert error" role="alert"><?= $escape($error) ?></div><?php endif; ?>
<?php if ($privacyAvailable): ?>
<form method="post" action="/enquiries">
<input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
<div class="trap" aria-hidden="true"><label for="website">Leave this field empty</label><input id="website" name="website" tabindex="-1" autocomplete="off"></div>
<div class="grid">
<div class="field"><label for="name">Your name</label><input id="name" name="name" autocomplete="name" minlength="2" maxlength="100" required value="<?= $escape($value('name')) ?>"></div>
<div class="field"><label for="email">Email address</label><input id="email" name="email" type="email" autocomplete="email" maxlength="254" required value="<?= $escape($value('email')) ?>"></div>
<div class="field"><label for="phone">Phone (optional)</label><input id="phone" name="phone" type="tel" autocomplete="tel" maxlength="40" value="<?= $escape($value('phone')) ?>"></div>
<div class="field"><label for="city">City (optional)</label><input id="city" name="city" maxlength="120" value="<?= $escape($value('city')) ?>"></div>
<div class="field wide"><label for="subject">Subject</label><input id="subject" name="subject" minlength="2" maxlength="160" required value="<?= $escape($value('subject')) ?>"></div>
<div class="field wide"><label for="message">Your message</label><textarea id="message" name="message" minlength="10" maxlength="5000" required><?= $escape($value('message')) ?></textarea></div>
</div>
<label class="privacy"><input type="checkbox" name="privacy_acknowledged" value="1" required><span>I have read the <a href="/privacy">Privacy Notice</a> and acknowledge that my information will be used to respond to this enquiry.</span></label>
<button class="button" type="submit">Send private enquiry</button>
</form>
<?php else: ?><div class="alert error" role="status">The enquiry form is temporarily unavailable. A Privacy Notice must be reviewed and published before enquiries can be accepted.</div><?php endif; ?>
</section>
<p class="foot">Please do not submit identity documents, payment card details, passwords or sensitive personal information through this form.</p>
</main>
</body>
</html>
