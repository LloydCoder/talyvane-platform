<?php
declare(strict_types=1);

$brandName=is_string($brandName??null)?$brandName:'Talyvane';
$mode=is_string($mode??null)?$mode:'list';
$testimonials=is_array($testimonials??null)?$testimonials:[];
$testimonial=is_array($testimonial??null)?$testimonial:[];
$csrf=is_string($csrf??null)?$csrf:'';
$error=is_string($error??null)?$error:'';
$id=is_string($testimonial['id']??null)?$testimonial['id']:'';
$editing=$mode==='edit';
$version=(int)($testimonial['version']??1);
$published=($testimonial['published_version']??null)===null?null:(int)$testimonial['published_version'];
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title><?= $editing?'Edit testimonial':'Testimonials' ?> · <?= $escape($brandName) ?></title>
<style>
:root{color-scheme:light;--ink:#182638;--muted:#667487;--paper:#f4f2ed;--panel:#fffefa;--line:#e6e2d9;--navy:#142235;--gold:#bd9560;--danger:#8b3535}
*{box-sizing:border-box}body{margin:0;background:var(--paper);color:var(--ink);font:14px/1.6 Inter,ui-sans-serif,system-ui,sans-serif}.top{height:68px;background:var(--navy);color:#f5f1e8;display:flex;align-items:center;justify-content:space-between;padding:0 clamp(20px,5vw,72px)}.brand{font:23px Georgia,serif;letter-spacing:.12em}.top a{color:#e7d6bc;text-decoration:none;font-size:11px;letter-spacing:.08em}.wrap{max-width:1100px;margin:auto;padding:clamp(28px,5vw,64px) 22px}.heading{display:flex;align-items:end;justify-content:space-between;gap:20px;margin-bottom:28px}.eyebrow{color:#9a774e;font-size:10px;font-weight:700;letter-spacing:.18em;text-transform:uppercase}.heading h1{font:400 clamp(34px,5vw,48px)/1.08 Georgia,serif;margin:10px 0}.panel{background:var(--panel);border:1px solid var(--line);padding:clamp(18px,3vw,30px)}.muted{color:var(--muted);font-size:12px;line-height:1.7}.button{display:inline-flex;align-items:center;justify-content:center;border:0;background:var(--navy);color:white;text-decoration:none;padding:12px 16px;font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;cursor:pointer}.secondary{background:transparent;color:var(--ink);border:1px solid #cfc8bb}.error{padding:12px 14px;background:#fff4f2;border:1px solid #e9c9c4;color:var(--danger);margin-bottom:18px}.table-wrap{overflow-x:auto}table{border-collapse:collapse;width:100%;min-width:760px}th{text-align:left;color:var(--muted);font-size:10px;letter-spacing:.1em;text-transform:uppercase;padding:12px;border-bottom:1px solid var(--line)}td{padding:14px 12px;border-bottom:1px solid var(--line);vertical-align:top}.status{font-size:10px;text-transform:uppercase;letter-spacing:.08em}.field{display:grid;gap:7px;margin:15px 0}.field label{font-size:11px;font-weight:700;letter-spacing:.05em}.field input,.field textarea{width:100%;padding:12px 13px;border:1px solid #d9d5cc;background:white;color:var(--ink);font:14px/1.5 inherit}.field textarea{min-height:160px;resize:vertical}.grid{display:grid;grid-template-columns:1fr 1fr;gap:0 18px}.actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:22px}.actions form{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.actions input{padding:11px 12px;border:1px solid #d9d5cc;min-width:min(300px,100%)}.notice{margin-top:18px;padding:13px 15px;border-left:3px solid var(--gold);background:#f7f3eb;color:var(--muted);font-size:12px;line-height:1.7}.back{display:inline-block;margin-bottom:18px;color:var(--muted);text-decoration:none;font-size:12px}
@media(max-width:640px){.top{height:62px}.heading{align-items:start;flex-direction:column}.grid{grid-template-columns:1fr}.wrap{padding:28px 15px}.actions form{width:100%}.actions input{width:100%}.actions .button{width:100%}}
</style></head><body>
<header class="top"><div class="brand"><?= $escape($brandName) ?><span style="color:#d4b183">.</span></div><a href="/admin">CONTROL ROOM</a></header>
<main class="wrap"><a class="back" href="/admin">← Back to control room</a>
<div class="heading"><div><div class="eyebrow">Publishing / Social proof</div><h1><?= $editing?($id===''?'Create testimonial':'Edit testimonial'):'Testimonials' ?></h1></div>
<?php if(!$editing):?><a class="button" href="/admin/testimonials/new">Create testimonial</a><?php endif;?></div>
<?php if($error!==''):?><div class="error" role="alert"><?= $escape($error) ?></div><?php endif;?>
<?php if(!$editing):?><section class="panel"><p class="muted">Only publish a quote when the agency holds a documented, current permission reference for the exact wording and public display name. The private reference is never sent to public pages.</p>
<?php if($testimonials===[]):?><p class="notice">No testimonials have been created yet.</p><?php else:?><div class="table-wrap"><table><thead><tr><th>Display name</th><th>Locale</th><th>Consent reference</th><th>Status</th><th>Version</th><th></th></tr></thead><tbody>
<?php foreach($testimonials as $item):?><tr><td><strong><?= $escape($item['display_name']??'') ?></strong></td><td><?= $escape($item['locale']??'') ?></td><td><?= $escape($item['consent_reference']??'') ?></td><td><span class="status"><?= $escape($item['status']??'draft') ?></span></td><td><?= $escape($item['version']??1) ?><?php if(($item['published_version']??null)!==null):?><small>Public v<?= $escape($item['published_version']) ?></small><?php endif;?></td><td><a class="button secondary" href="/admin/testimonials/<?= $escape($item['id']??'') ?>">Edit</a></td></tr><?php endforeach;?>
</tbody></table></div><?php endif;?></section>
<?php else:?><section class="panel"><p class="muted">Keep the quote faithful to the permissioned statement. Do not include health, financial, booking or other sensitive personal details. The consent reference is an internal evidence pointer, not the consent document itself.</p>
<form method="post" action="<?= $escape($id===''?'/admin/testimonials':'/admin/testimonials/'.rawurlencode($id)) ?>">
<input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
<div class="field"><label for="display_name">Public display name</label><input id="display_name" name="display_name" maxlength="120" required value="<?= $escape($testimonial['display_name']??'') ?>"></div>
<div class="field"><label for="quote_text">Approved quote</label><textarea id="quote_text" name="quote_text" maxlength="5000" required><?= $escape($testimonial['quote_text']??'') ?></textarea></div>
<div class="grid"><div class="field"><label for="locale">Locale</label><input id="locale" name="locale" maxlength="35" required value="<?= $escape($testimonial['locale']??'en') ?>"></div>
<div class="field"><label for="sort_order">Display order</label><input id="sort_order" name="sort_order" type="number" min="-10000" max="10000" value="<?= $escape($testimonial['sort_order']??0) ?>"></div>
<div class="field"><label for="consent_reference">Private consent reference</label><input id="consent_reference" name="consent_reference" maxlength="190" minlength="4" required value="<?= $escape($testimonial['consent_reference']??'') ?>"></div>
<div class="field"><label for="reason">Change reason</label><input id="reason" name="reason" minlength="8" maxlength="500" required placeholder="Why is this testimonial being changed?"></div></div>
<div class="actions"><button class="button" type="submit">Save draft</button><a class="button secondary" href="/admin/testimonials">Cancel</a></div></form>
<?php if($id!==''):?><div class="notice">Draft version <?= $escape($version) ?>. <?php if($published===null):?>This testimonial has not been published.<?php elseif($published<$version):?>Public readers still see version <?= $escape($published) ?>.<?php else:?>Public readers are viewing version <?= $escape($published) ?>.<?php endif;?></div>
<?php if($published===null||$published<$version):?><form method="post" action="/admin/testimonials/<?= $escape(rawurlencode($id)) ?>/publish" class="actions"><input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>"><label for="publish-reason" class="muted">Publication reason</label><input id="publish-reason" name="reason" minlength="8" maxlength="500" required placeholder="Why is this testimonial approved?"><button class="button" type="submit">Publish version <?= $escape($version) ?></button></form><?php endif;?>
<?php endif;?></section><?php endif;?>
<p class="muted" style="margin-top:20px">Every save and publication is audited. Public snapshots contain only the approved display name and quote; consent references remain private.</p>
</main></body></html>
