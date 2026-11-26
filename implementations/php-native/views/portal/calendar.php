<?php
declare(strict_types=1);
$events=is_array($events??null)?$events:[];
$role=is_string($role??null)?$role:'client';
$brandName=is_string($brandName??null)?$brandName:'Talyvane';
$escape=static fn(mixed $value):string=>htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$localTime=static function(array $event,string $key):string {
    try {
        $utc=new DateTimeZone('UTC');
        $zone=new DateTimeZone((string)($event['time_zone']??'UTC'));
        return (new DateTimeImmutable((string)$event[$key],$utc))->setTimezone($zone)->format('D, d M Y · H:i');
    } catch (Throwable) {
        return (string)($event[$key]??'');
    }
};
$isCompanion=$role==='companion';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Calendar · <?= $escape($brandName) ?></title>
<style>
:root{color-scheme:light;--ink:#182638;--muted:#667487;--paper:#f4f2ed;--line:#e6e2d9;--panel:#fffefa;--navy:#142235;--gold:#bd9560}
*{box-sizing:border-box}body{margin:0;background:var(--paper);color:var(--ink);font:14px/1.6 Inter,ui-sans-serif,system-ui,sans-serif}
.shell{width:min(1000px,calc(100% - 36px));margin:auto}.top{padding:22px 0;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;gap:16px}.top a{color:var(--ink);text-decoration:none}.brand{font:600 20px Georgia,serif;letter-spacing:.08em}
h1{font:400 clamp(34px,5vw,54px)/1.05 Georgia,serif;margin:46px 0 10px}.intro{color:var(--muted);max-width:720px}
.actions{display:flex;flex-wrap:wrap;gap:10px;margin:24px 0}.button{display:inline-block;background:var(--navy);color:white;padding:11px 15px;font-size:10px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;text-decoration:none}
.list{display:grid;gap:12px;margin:24px 0 70px}.event{background:var(--panel);border:1px solid var(--line);padding:20px;display:grid;grid-template-columns:minmax(130px,190px) 1fr auto;gap:18px;align-items:center}.when{font-weight:650}.meta{color:var(--muted);font-size:12px}.status{font-size:10px;text-transform:uppercase;letter-spacing:.1em;color:var(--muted);border:1px solid var(--line);padding:5px 8px}.empty{padding:28px;background:var(--panel);border:1px dashed #d2cbbf;color:var(--muted);margin:24px 0 70px}
@media(max-width:640px){.event{grid-template-columns:1fr;gap:7px}.status{justify-self:start}}
</style>
</head>
<body>
<header class="shell top"><a class="brand" href="/"><?= $escape($brandName) ?></a><a href="/portal">Account</a></header>
<main class="shell">
<h1><?= $isCompanion?'Your schedule.':'Your booking calendar.' ?></h1>
<p class="intro"><?= $isCompanion?'A private schedule of your accepted and confirmed bookings. Client names, contact details and private notes are never included.':'A private schedule of your accepted and confirmed bookings, displayed in each destination’s local time zone.' ?> Calendar exports contain only the minimum event details needed to identify and update bookings.</p>
<div class="actions">
<a class="button" href="<?= $isCompanion?'/portal/companion/bookings':'/portal/bookings' ?>">Booking workspace</a>
<a class="button" href="<?= $isCompanion?'/portal/companion/calendar.ics':'/portal/calendar.ics' ?>">Export iCalendar (.ics)</a>
</div>
<?php if($events===[]):?>
<div class="empty">There are no active or recently changed bookings in your calendar.</div>
<?php else:?>
<section class="list" aria-label="Calendar events">
<?php foreach($events as $event):?>
<article class="event">
<div><div class="when"><?= $escape($localTime($event,'starts_at_utc')) ?></div><div class="meta">to <?= $escape($localTime($event,'ends_at_utc')) ?></div></div>
<div><strong><?= $escape($isCompanion?'Booking':($event['profile_name']??'Booking')) ?></strong><div class="meta"><?= $escape($event['destination_name']??'') ?> · <?= $escape($event['time_zone']??'UTC') ?></div></div>
<span class="status"><?= $escape($event['status']??'') ?></span>
</article>
<?php endforeach;?>
</section>
<?php endif;?>
</main>
</body>
</html>
