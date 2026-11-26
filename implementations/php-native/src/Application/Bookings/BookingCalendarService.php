<?php
declare(strict_types=1);

namespace Talyvane\Application\Bookings;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Talyvane\Security\Authorization;
use Talyvane\Security\Principal;

/** Private, role-scoped calendar projection and standards-based iCalendar export. */
final class BookingCalendarService
{
    public function __construct(private PDO $pdo, private string $uidKey)
    {
        if (strlen($uidKey) < 32) {
            throw new \InvalidArgumentException('Calendar UID key must contain at least 32 bytes.');
        }
    }

    /** @return list<array<string,mixed>> */
    public function clientEvents(Principal $client): array
    {
        Authorization::require($client,'client.booking.manage',false);
        if (!$client->hasRole('client')) {
            throw new \DomainException('Client role is required.');
        }
        return $this->events('b.client_user_id = :actor',$client->id);
    }

    /** @return list<array<string,mixed>> */
    public function companionEvents(Principal $companion): array
    {
        Authorization::require($companion,'companion.self.view',false);
        if (!$companion->hasRole('companion')) {
            throw new \DomainException('Companion role is required.');
        }
        return $this->events('p.user_id = :actor',$companion->id);
    }

    /**
     * @param list<array<string,mixed>> $events
     */
    public function toIcalendar(Principal $principal,array $events,DateTimeImmutable $now): string
    {
        $lines=[
            'BEGIN:VCALENDAR',
            'PRODID:-//Talyvane//Private Booking Calendar//EN',
            'VERSION:2.0',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:Talyvane bookings',
        ];
        $utc=new DateTimeZone('UTC');
        foreach ($events as $event) {
            $id=(string) ($event['id']??'');
            if ($id==='' || !isset($event['starts_at_utc'],$event['ends_at_utc'])) {
                continue;
            }
            $start=new DateTimeImmutable((string)$event['starts_at_utc'],$utc);
            $end=new DateTimeImmutable((string)$event['ends_at_utc'],$utc);
            if ($end <= $start) {
                continue;
            }
            $status=(string)($event['status']??'');
            if (!in_array($status,['accepted','confirmed','cancelled'],true)) {
                continue;
            }
            $updated=isset($event['updated_at'])?new DateTimeImmutable((string)$event['updated_at'],$utc):$now->setTimezone($utc);
            $uid=hash_hmac('sha256',$principal->id . ':' . $id,$this->uidKey) . '@calendar.talyvane';
            $destination=(string)($event['destination_name']??'');
            $summary=$principal->hasRole('companion')
                ? 'Appointment — ' . $destination
                : 'Booking with ' . (string)($event['profile_name']??'Companion') . ' — ' . $destination;
            $lines[]='BEGIN:VEVENT';
            $lines[]='UID:' . $uid;
            $lines[]='DTSTAMP:' . $now->setTimezone($utc)->format('Ymd\THis\Z');
            $lines[]='DTSTART:' . $start->setTimezone($utc)->format('Ymd\THis\Z');
            $lines[]='DTEND:' . $end->setTimezone($utc)->format('Ymd\THis\Z');
            $lines[]='LAST-MODIFIED:' . $updated->setTimezone($utc)->format('Ymd\THis\Z');
            $lines[]='SEQUENCE:' . max(0,$updated->getTimestamp());
            $lines[]='SUMMARY:' . self::escapeText($summary);
            $lines[]='LOCATION:' . self::escapeText($destination);
            $lines[]='DESCRIPTION:' . self::escapeText('Booking status: ' . $status);
            $lines[]='STATUS:' . ($status==='cancelled'?'CANCELLED':($status==='confirmed'?'CONFIRMED':'TENTATIVE'));
            $lines[]='END:VEVENT';
        }
        $lines[]='END:VCALENDAR';
        return implode("\r\n",array_map(self::foldLine(...),$lines)) . "\r\n";
    }

    /** @return list<array<string,mixed>> */
    private function events(string $scope,string $actorId): array
    {
        $query=$this->pdo->prepare(
            'SELECT b.id,b.booking_reference,b.status,b.starts_at_utc,b.ends_at_utc,b.updated_at,
                    p.display_name AS profile_name,d.display_name AS destination_name
             FROM bookings b
             JOIN companion_profiles p ON p.id=b.profile_id
             JOIN destinations d ON d.id=b.destination_id
             WHERE ' . $scope . '
               AND b.status IN ("accepted","confirmed","cancelled")
               AND (b.starts_at_utc >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 90 DAY)
                    OR b.updated_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 90 DAY))
             ORDER BY b.starts_at_utc ASC
             LIMIT 1000'
        );
        $query->execute(['actor'=>$actorId]);
        return $query->fetchAll();
    }

    private static function escapeText(string $value): string
    {
        $value=str_replace(["\r\n","\r","\n"],'\\n',$value);
        return str_replace(['\\',';',','],['\\\\','\\;','\\,'],$value);
    }

    private static function foldLine(string $line): string
    {
        $characters=preg_split('//u',$line,-1,PREG_SPLIT_NO_EMPTY);
        if (!is_array($characters)) {
            $characters=str_split($line);
        }
        $output='';
        $bytes=0;
        foreach ($characters as $character) {
            $length=strlen($character);
            if ($bytes+$length>75) {
                $output.="\r\n ";
                $bytes=1;
            }
            $output.=$character;
            $bytes+=$length;
        }
        return $output;
    }
}
