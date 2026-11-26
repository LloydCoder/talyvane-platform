<?php
declare(strict_types=1);
$items=is_array($items??null)?$items:[];
$notice=is_string($notice??null)?$notice:'';
$error=is_string($error??null)?$error:'';
$csrf=is_string($csrf??null)?$csrf:'';
$brandName=is_string($brandName??null)?$brandName:'Talyvane';
$externalEvidence=is_array($externalEvidence??null)?$externalEvidence:[];
$canAttestExternalEvidence=($canAttestExternalEvidence??false)===true;
$escape=static fn(mixed $value):string=>htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Privacy request review · <?= $escape($brandName) ?></title>
<style>
:root{color-scheme:light;--ink:#182638;--muted:#667487;--paper:#f4f2ed;--panel:#fffefa;--line:#e6e2d9;--night:#142235;--danger:#8b3535}
*{box-sizing:border-box}body{margin:0;background:var(--paper);color:var(--ink);font:14px/1.6 Inter,ui-sans-serif,system-ui,sans-serif}.shell{width:min(1100px,calc(100% - 36px));margin:auto}.top{padding:22px 0;border-bottom:1px solid var(--line);display:flex;justify-content:space-between}.top a{color:var(--ink);text-decoration:none}.brand{font:600 20px Georgia,serif;letter-spacing:.08em}h1{font:400 clamp(32px,5vw,50px)/1.05 Georgia,serif;margin:42px 0 12px}.intro{color:var(--muted);max-width:780px}.notice,.error{padding:12px 14px;margin:18px 0}.notice{background:#f1f5ef;border:1px solid #d8e4d1;color:#36543b}.error{background:#fff4f2;border:1px solid #e9c9c4;color:var(--danger)}.queue{display:grid;gap:14px;margin:24px 0 60px}.item{background:var(--panel);border:1px solid var(--line);padding:20px}.meta{color:var(--muted);font-size:12px}.summary{white-space:pre-wrap;background:#f7f5f0;padding:14px;margin:14px 0}.actions{display:grid;grid-template-columns:1fr 1fr;gap:12px}.actions form,.correction-form{display:grid;gap:8px}.correction-form{margin:16px 0;padding:16px;background:#f7f5f0;border:1px solid var(--line)}.correction-form label{display:grid;gap:5px}.correction-form input,.correction-form textarea{width:100%;padding:10px;border:1px solid var(--line);font:inherit}.actions textarea{width:100%;min-height:75px;padding:10px;border:1px solid var(--line);font:inherit}.button{border:0;background:var(--night);color:#fff;padding:11px 14px;font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;cursor:pointer}.button.danger{background:#713333}.empty{padding:28px;background:var(--panel);border:1px dashed #d2cbbf;color:var(--muted)}
@media(max-width:680px){.actions{grid-template-columns:1fr}}
</style>
</head>
<body>
<header class="shell top"><a class="brand" href="/admin"><?= $escape($brandName) ?></a><a href="/admin">Administration</a></header>
<main class="shell">
<h1>Privacy request review.</h1>
<p class="intro">This MFA-gated workspace exposes only verified client and companion correction/erasure requests. Companion profile corrections can be applied from an independently checked set of allowlisted fields; the profile is returned to draft and must pass publication review again. Erasure remains a separate governed process requiring legal-hold, booking, safeguarding, financial-retention and backup-expiry checks.</p>
<?php if($notice!==''):?><p class="notice" role="status"><?= $escape($notice) ?></p><?php endif;?>
<?php if($error!==''):?><p class="error" role="alert"><?= $escape($error) ?></p><?php endif;?>
<section class="queue" aria-label="Privacy requests">
<?php if($items===[]):?><div class="empty">There are no verified privacy requests awaiting review.</div><?php endif;?>
<?php foreach($items as $item):?>
<article class="item">
<div class="meta"><?= $escape($item['account_type']??'') ?> · <?= $escape($item['request_type']??'') ?> · <?= $escape($item['status']??'') ?></div>
<h2>Account reference · <?= $escape(substr((string)($item['user_id']??'unknown'),0,8)) ?></h2>
<p class="meta">Request <?= $escape($item['id']??'') ?> · Submitted <?= $escape($item['created_at']??'') ?> · Verified <?= $escape($item['verified_at']??'') ?></p>
<?php if(($item['request_type']??'')==='correction'):?>
<div class="summary"><?= $escape($item['correction_summary']??'No correction summary available.') ?></div>
<?php if(($item['account_type']??'')==='companion'):?>
<form method="post" action="/admin/privacy/requests/review" class="correction-form">
<input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
<input type="hidden" name="request_id" value="<?= $escape($item['id']??'') ?>">
<input type="hidden" name="decision" value="fulfill_companion_correction">
<label>Verified display name<input name="display_name" maxlength="120" required></label>
<label>Verified biography<textarea name="biography" maxlength="8000" rows="4"></textarea></label>
<label>Verified languages (comma-separated BCP-47 tags)<input name="languages" value="en" maxlength="300" required></label>
<label>Fulfilment reason<textarea name="reason" minlength="10" maxlength="500" required></textarea></label>
<button class="button" type="submit">Apply correction and unpublish for review</button>
</form>
<?php else:?>
<form method="post" action="/admin/privacy/requests/review" class="correction-form">
<input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
<input type="hidden" name="request_id" value="<?= $escape($item['id']??'') ?>">
<input type="hidden" name="decision" value="fulfill_client_correction">
<label>Verified account name<input name="client_name" maxlength="120" required></label>
<label>Fulfilment reason<textarea name="reason" minlength="10" maxlength="500" required></textarea></label>
<button class="button" type="submit">Apply account name correction</button>
<p class="meta">Contact-email changes are not included. They require a separate verification challenge sent to the proposed new address before fulfilment.</p>
</form>
<?php endif;?>
<?php else:?>
<p>The account holder requested erasure. No account data has been erased automatically. The fulfilment action anonymizes account credentials and contact identifiers only when automated dependency checks pass. It does not erase retained financial/booking records, backups, provider copies or independently held evidence.</p>
<?php if(($item['account_type']??'')==='client' || ($item['account_type']??'')==='companion'):?>
<?php $evidenceState=$externalEvidence[(string)($item['id']??'')]??['ready'=>false,'items'=>[]]; ?>
<section class="correction-form" aria-label="External erasure evidence">
<h3>External processor and backup evidence</h3>
<p class="meta">Every gate requires an Owner/Super Admin operator attestation with an evidence reference. This records human review; it does not independently verify provider deletion or backup expiry.</p>
<?php foreach(($evidenceState['items']??[]) as $evidenceItem):?>
<div style="padding:12px 0;border-top:1px solid var(--line)">
<strong><?= $escape($evidenceItem['label']) ?></strong>
<span class="meta"> · <?= $escape($evidenceItem['decision']) ?><?= !empty($evidenceItem['evidence_reference'])?' · Ref '.$escape($evidenceItem['evidence_reference']):'' ?></span>
<?php if($canAttestExternalEvidence):?>
<form method="post" action="/admin/privacy/requests/external-evidence" class="correction-form">
<input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
<input type="hidden" name="request_id" value="<?= $escape($item['id']??'') ?>">
<input type="hidden" name="system_key" value="<?= $escape($evidenceItem['system_key']) ?>">
<label>Decision<select name="decision" required style="width:100%;padding:9px;border:1px solid var(--line);font:inherit"><option value="cleared" <?= ($evidenceItem['decision']??'')==='cleared'?'selected':'' ?>>Cleared — evidence of deletion/expiry</option><option value="retained" <?= ($evidenceItem['decision']??'')==='retained'?'selected':'' ?>>Retained — legal basis and expiry required</option><option value="not_applicable" <?= ($evidenceItem['decision']??'')==='not_applicable'?'selected':'' ?>>Not applicable — evidence required</option></select></label>
<label>Evidence reference<input name="evidence_reference" minlength="8" maxlength="190" required value="<?= $escape($evidenceItem['evidence_reference']??'') ?>" placeholder="Vault ticket, backup job, provider case ID"></label>
<label>Legal-basis reference (required for retained records)<input name="legal_basis_reference" maxlength="190" value="<?= $escape($evidenceItem['legal_basis_reference']??'') ?>"></label>
<label>Retention expiry (required for retained records)<input type="date" name="retention_until" value="<?= $escape(is_string($evidenceItem['retention_until']??null)?substr($evidenceItem['retention_until'],0,10):'') ?>"></label>
<label>Review rationale<textarea name="reason" minlength="10" maxlength="500" required></textarea></label>
<button class="button" type="submit">Record Owner attestation</button>
</form>
<?php endif;?>
</div>
<?php endforeach;?>
<?php if(($evidenceState['ready']??false)!==true):?><p class="meta">Erasure remains locked until all seven external evidence gates are cleared or explicitly marked not applicable by an Owner/Super Admin.</p><?php endif;?>
</section>
<?php if(($evidenceState['ready']??false)===true):?>
<form method="post" action="/admin/privacy/requests/review" class="correction-form">
<input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
<input type="hidden" name="request_id" value="<?= $escape($item['id']??'') ?>">
<input type="hidden" name="decision" value="<?= ($item['account_type']??'')==='client'?'fulfill_client_erasure':'fulfill_companion_erasure' ?>">
<label>Retention and dependency review rationale<textarea name="reason" minlength="20" maxlength="500" required></textarea></label>
<label style="display:flex;grid-template-columns:auto 1fr;align-items:start;gap:10px"><input type="checkbox" name="confirm_erasure" value="yes" required style="width:auto"> I have reviewed the request and authorize this account anonymization. I understand blocked dependencies cause the transaction to roll back.</label>
<button class="button danger" type="submit">Fulfil verified erasure and anonymize account</button>
</form>
<?php else:?>
<p class="error" role="status">Fulfilment is locked until every external evidence gate has been reviewed.</p>
<?php endif;?>
<?php endif;?>
<div class="actions">
<form method="post" action="/admin/privacy/requests/review">
<input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
<input type="hidden" name="request_id" value="<?= $escape($item['id']??'') ?>">
<input type="hidden" name="decision" value="deferred">
<label>Reason for deferral<textarea name="reason" minlength="10" maxlength="500" required></textarea></label>
<button class="button" type="submit">Defer for further review</button>
</form>
<form method="post" action="/admin/privacy/requests/review">
<input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
<input type="hidden" name="request_id" value="<?= $escape($item['id']??'') ?>">
<input type="hidden" name="decision" value="rejected">
<label>Reason for rejection<textarea name="reason" minlength="10" maxlength="500" required></textarea></label>
<button class="button danger" type="submit">Reject request</button>
</form>
</div>
</article>
<?php endforeach;?>
</section>
</main>
</body>
</html>
