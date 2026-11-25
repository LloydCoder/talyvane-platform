<?php
declare(strict_types=1);
$notice=is_string($notice??null)?$notice:'';
$e=static fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Client memberships</title><style>
*{box-sizing:border-box}body{margin:0;background:#f4f7f8;color:#17212b;font:15px/1.5 system-ui,sans-serif}main{max-width:1100px;margin:36px auto;padding:0 18px}.panel{background:white;border:1px solid #dce3e8;border-radius:12px;padding:20px;margin:18px 0}h1{font-size:2rem}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:12px;border-bottom:1px solid #dce3e8;vertical-align:top}th{font-size:.8rem;color:#63717d;text-transform:uppercase}.table{overflow:auto}form{padding:14px;background:#f8fafb;border:1px solid #dce3e8;border-radius:9px;margin-top:10px}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}label{display:block;font-weight:600;margin:8px 0 4px}input,textarea{width:100%;padding:9px;border:1px solid #bdc8cf;border-radius:7px;font:inherit}input[type=checkbox]{width:auto}textarea{min-height:65px}button{background:#175b63;color:white;border:0;border-radius:7px;padding:10px 14px;font-weight:650}.error{color:#8f2d2d;background:#fff2f2;padding:12px;border-left:4px solid #8f2d2d}.notice{color:#24563b;background:#eff8f1;padding:12px;border-left:4px solid #24563b}@media(max-width:600px){.grid{grid-template-columns:1fr}main{margin:20px auto}.panel{padding:14px}}
</style></head><body><main><p><?= $e($brandName) ?> · Administration</p><h1>Clients and memberships</h1><p>Membership assignment does not by itself authorize protected content. Each benefit is checked at the resource boundary against current grants, consent and market policy.</p>
<?php if($error!==''):?><p class="error" role="alert"><?= $e($error) ?></p><?php endif;?>
<?php if($notice!==''):?><p class="notice" role="status"><?= $e($notice) ?></p><?php endif;?>
<section class="panel"><div class="table"><table><thead><tr><th>Client</th><th>Verification</th><th>Membership</th><th>Actions</th></tr></thead><tbody>
<?php foreach($clients as $client):?><tr><td><?= $e($client['email']) ?></td><td><?= $client['email_verified_at']!==null?'Verified':'Unverified' ?></td><td><?php if($client['membership_id']!==null):?><?= $e($client['tier_name']) ?> · <?= $e($client['plan_key']) ?><?php else:?>None<?php endif;?></td><td><details><summary>Assign / replace</summary>
<form method="post" action="/admin/clients/<?= $e($client['id']) ?>/membership"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><div class="grid">
<div><label>Plan key</label><input name="plan_key" maxlength="80" pattern="[a-z0-9][a-z0-9._-]{1,79}" required></div><div><label>Tier name</label><input name="tier_name" maxlength="120" required></div>
<div><label>Starts (UTC)</label><input type="datetime-local" name="starts_at" required></div><div><label>Ends (UTC, optional)</label><input type="datetime-local" name="ends_at"></div></div>
<label>Benefits summary</label><textarea name="benefits_summary" maxlength="1000"></textarea>
<label><input type="checkbox" name="entitlements[]" value="media.private_gallery"> Private members-only gallery</label>
<label>Reason (20–500 characters)</label><textarea name="reason" minlength="20" maxlength="500" required></textarea><button type="submit">Save membership</button></form></details>
<?php if(($client['membership_status']??null)==='active' && is_string($client['membership_id']??null)):?>
<details><summary>Cancel active membership</summary>
<form method="post" action="/admin/memberships/<?= $e($client['membership_id']) ?>/cancel">
<input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
<label>Cancellation reason (20–500 characters)</label>
<textarea name="reason" minlength="20" maxlength="500" required></textarea>
<button type="submit">Cancel membership now</button>
</form></details>
<details><summary>Suspend active membership</summary>
<form method="post" action="/admin/memberships/<?= $e($client['membership_id']) ?>/suspend">
<input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
<label>Suspension reason (20–500 characters)</label>
<textarea name="reason" minlength="20" maxlength="500" required></textarea>
<button type="submit">Suspend membership now</button>
</form></details>
<?php endif;?>
</td></tr><?php endforeach;?>
<?php if($clients===[]):?><tr><td colspan="4">No client accounts are registered.</td></tr><?php endif;?>
</tbody></table></div></section></main></body></html>
