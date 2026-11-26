<?php
declare(strict_types=1);
$brandName=is_string($brandName ?? null)?$brandName:'Talyvane';
$profile=is_array($profile ?? null)?$profile:null;
$items=is_array($items ?? null)?$items:[];
$csrf=is_string($csrf ?? null)?$csrf:'';
$error=is_string($error ?? null)?$error:'';
$notice=is_string($notice ?? null)?$notice:'';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Profile media · <?= $escape($brandName) ?></title>
<style>
:root{color-scheme:light;--ink:#182638;--muted:#667487;--paper:#f4f2ed;--panel:#fffefa;--line:#e6e2d9;--gold:#bd9560;--night:#142235;--danger:#8b3535}*{box-sizing:border-box}body{margin:0;background:var(--paper);color:var(--ink);font:15px/1.6 Inter,ui-sans-serif,system-ui,sans-serif}.shell{width:min(980px,calc(100% - 32px));margin:auto}.top{padding:22px 0;border-bottom:1px solid var(--line);display:flex;justify-content:space-between}.brand{font:600 20px Georgia,serif;letter-spacing:.08em}.top a,.links a{color:var(--ink)}main{padding:42px 0 80px}h1{font:400 clamp(34px,5vw,52px)/1.1 Georgia,serif;margin:0 0 10px}.muted{color:var(--muted)}.panel{background:var(--panel);border:1px solid var(--line);padding:24px;margin:24px 0}.field{display:grid;gap:8px;margin:16px 0}.field label{font-size:13px;font-weight:700}.field input[type=file]{width:100%;padding:12px;background:white;border:1px solid var(--line)}.check{display:flex;gap:10px;align-items:flex-start;margin:14px 0;font-size:13px}.check input{margin-top:4px}.button{border:0;background:var(--night);color:white;padding:13px 18px;font-weight:700;cursor:pointer}.danger{background:#7c2f2f}.notice,.error{padding:12px 14px;margin:16px 0}.notice{background:#edf4eb;border:1px solid #d5e3d0}.error{background:#fff3f1;border:1px solid #e7c9c3;color:var(--danger)}.item{padding:16px 0;border-top:1px solid var(--line);display:flex;justify-content:space-between;gap:20px;align-items:center}.item form{margin:0}.pill{font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:#6d583c}.links{display:flex;gap:18px;flex-wrap:wrap;margin-top:20px}
</style></head><body><header class="shell top"><span class="brand"><?= $escape($brandName) ?></span><nav class="links"><a href="/portal">Portal</a><a href="/portal/companion/availability">Availability</a></nav></header>
<main class="shell"><p class="muted">COMPANION WORKSPACE</p><h1>Profile media</h1><p class="muted">Images are private on receipt. They are never shown publicly unless an authorized reviewer approves them while your profile eligibility, market approval and publication consent remain current.</p>
<?php if ($error!==''): ?><p class="error" role="alert"><?= $escape($error) ?></p><?php endif; ?>
<?php if ($notice!==''): ?><p class="notice" role="status"><?= $escape($notice) ?></p><?php endif; ?>
<?php if ($profile===null): ?><section class="panel"><p>No companion profile is linked to this account. Contact the agency to complete account linking.</p></section>
<?php else: ?>
<section class="panel"><h2><?= $escape($profile['display_name'] ?? 'Your profile') ?></h2><p class="muted"><?= $escape($profile['destination_name'] ?? '') ?> · Status: <?= $escape($profile['status'] ?? 'unknown') ?></p>
<?php if (($profile['status'] ?? '')==='published'): ?>
<form method="post" action="/portal/companion/media" enctype="multipart/form-data">
<input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
<div class="field"><label for="media">Choose an image (JPEG, PNG or WebP; maximum 8 MiB)</label><input id="media" type="file" name="media" accept="image/jpeg,image/png,image/webp" required></div>
<label class="check"><input type="checkbox" name="rights_confirmed" value="1" required><span>I confirm that I own or have the necessary rights and permissions to submit this image, including likeness rights for every identifiable person shown.</span></label>
<label class="check"><input type="checkbox" name="depicted_adults_confirmed" value="1" required><span>I confirm every identifiable person depicted is an adult aged 18 or older and no minor is depicted.</span></label>
<label class="check"><input type="checkbox" name="publication_confirmed" value="1" required><span>I separately consent to this image being submitted for human review and, only if approved, displayed publicly on my profile.</span></label>
<p class="muted">Images are decoded and re-encoded to WebP to remove embedded metadata. Original filenames and metadata are not retained.</p>
<button class="button" type="submit">Submit image for review</button>
</form>
<?php elseif (($profile['status'] ?? '')==='unpublished'): ?>
<p class="muted">Your profile is unpublished because publication consent was withdrawn. Existing images remain withdrawn and cannot be restored automatically.</p>
<form method="post" action="/portal/companion/publication/renew">
<input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
<label class="check"><input type="checkbox" name="publication_consent_confirmed" value="1" required><span>I grant a new, versioned consent for my profile to be considered for public publication, subject to fresh human review and current market eligibility.</span></label>
<button class="button" type="submit">Renew publication consent</button>
</form>
<?php else: ?>
<p class="muted">Your profile is not currently published. Image submission is disabled until an authorized reviewer completes the publication workflow.</p>
<?php endif; ?>
<?php if (in_array(($profile['status'] ?? ''),['published','pending_review'],true)): ?>
<form method="post" action="/portal/companion/publication/withdraw" style="border-top:1px solid var(--line);padding-top:18px;margin-top:20px">
<input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
<label class="check"><input type="checkbox" name="confirm_publication_withdrawal" value="1" required><span>I understand this immediately unpublishes my profile, withdraws all public media, deletes submitted image files and disables automatic booking.</span></label>
<button class="button danger" type="submit">Withdraw all publication consent</button>
</form>
<?php endif; ?></section>
<section class="panel"><h2>Your submitted images</h2>
<?php if ($items===[]): ?><p class="muted">No images have been submitted.</p><?php endif; ?>
<?php foreach ($items as $item): ?><div class="item"><div><strong><?= $escape(strtoupper(str_replace('_',' ',(string) ($item['review_status'] ?? 'pending')))) ?></strong><div class="muted"><?= $escape((string) ($item['width'] ?? 0)) ?> × <?= $escape((string) ($item['height'] ?? 0)) ?> px · <?= $escape((string) ($item['created_at'] ?? '')) ?></div><?php if (!empty($item['review_note'])): ?><p class="muted"><?= $escape($item['review_note']) ?></p><?php endif; ?></div>
<?php if (($item['review_status'] ?? '')==='approved' && ($item['media_scope'] ?? '')==='members_only' && empty($item['members_only_consent_withdrawn_at'])): ?>
<?php if (empty($item['members_only_consent_at'])): ?>
<form method="post" action="/portal/companion/media/<?= $escape($item['id']) ?>/members-only/consent"><input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>"><label class="check"><input type="checkbox" name="members_only_consent_confirmed" value="1" required><span><?= $escape($privateGalleryConsentStatement) ?></span></label><button class="button" type="submit">Grant members-only consent</button></form>
<?php else: ?>
<form method="post" action="/portal/companion/media/<?= $escape($item['id']) ?>/members-only/withdraw"><input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>"><button class="button danger" type="submit">Withdraw members-only consent</button></form>
<?php endif; ?>
<?php endif; ?>
<?php if (!in_array((string) ($item['review_status'] ?? ''),['withdrawn','rejected'],true)): ?><form method="post" action="/portal/companion/media/<?= $escape($item['id']) ?>/withdraw"><input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>"><button class="button danger" type="submit">Withdraw image</button></form><?php endif; ?></div><?php endforeach; ?>
</section><?php endif; ?>
</main></body></html>
