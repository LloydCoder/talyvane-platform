<?php
declare(strict_types=1);

namespace Talyvane\Application\CRM;

use PDO;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Infrastructure\Security\RateLimiter;
use Talyvane\Security\Authorization;
use Talyvane\Security\Principal;
use Talyvane\Security\SecretBox;

final class EnquiryService
{
    private const STATUSES = ['new','in_progress','responded','closed','spam'];

    public function __construct(
        private PDO $pdo,
        private RateLimiter $limiter,
        private AuditLogger $audit,
        private ?SecretBox $notesCipher = null
    ) {}

    /** @param array<string,mixed> $input */
    public function submit(array $input,string $ipAddress,string $requestId,?string $attribution,string $privacyPolicyVersion): ?string
    {
        // A filled honeypot is treated as a successful submission without persistence.
        if (isset($input['website']) && is_string($input['website']) && trim($input['website']) !== '') {
            return null;
        }
        if ($ipAddress === '' || strlen($ipAddress) > 64 || filter_var($ipAddress,FILTER_VALIDATE_IP) === false ||
            $requestId === '' || strlen($requestId) > 180 || !preg_match('/^[A-Za-z0-9._-]{1,64}$/D',$privacyPolicyVersion)) {
            throw new \InvalidArgumentException('Enquiry request is invalid.');
        }

        $name = trim(is_string($input['name'] ?? null) ? $input['name'] : '');
        $email = strtolower(trim(is_string($input['email'] ?? null) ? $input['email'] : ''));
        $phone = trim(is_string($input['phone'] ?? null) ? $input['phone'] : '');
        $subject = trim(is_string($input['subject'] ?? null) ? $input['subject'] : '');
        $message = trim(is_string($input['message'] ?? null) ? $input['message'] : '');
        $city = trim(is_string($input['city'] ?? null) ? $input['city'] : '');
        $country = strtoupper(trim(is_string($input['country_code'] ?? null) ? $input['country_code'] : ''));
        $destinationId = trim(is_string($input['destination_id'] ?? null) ? $input['destination_id'] : '');
        $acknowledged = in_array($input['privacy_acknowledged'] ?? null,[true,1,'1','on','yes'],true);

        if (mb_strlen($name,'UTF-8') < 2 || mb_strlen($name,'UTF-8') > 100 ||
            !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email) > 254 ||
            ($phone !== '' && (mb_strlen($phone,'UTF-8') > 40 || preg_match('/[\r\n\x00-\x1F]/',$phone) === 1)) ||
            mb_strlen($subject,'UTF-8') < 2 || mb_strlen($subject,'UTF-8') > 160 ||
            mb_strlen($message,'UTF-8') < 10 || mb_strlen($message,'UTF-8') > 5000 ||
            mb_strlen($city,'UTF-8') > 120 ||
            ($country !== '' && preg_match('/^[A-Z]{2}$/D',$country) !== 1) ||
            !$acknowledged ||
            ($destinationId !== '' && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD',$destinationId) !== 1)) {
            throw new \InvalidArgumentException('Please check the required enquiry details and privacy acknowledgement.');
        }
        if (!$this->limiter->consume('enquiry.submit',$ipAddress,5,3600,3600)) {
            throw new \DomainException('Enquiry submission is temporarily unavailable. Please try again later.');
        }

        if ($destinationId !== '') {
            $destination = $this->pdo->prepare('SELECT 1 FROM destinations WHERE id = :id AND status = "active" LIMIT 1');
            $destination->execute(['id'=>$destinationId]);
            if ($destination->fetchColumn() === false) {
                throw new \InvalidArgumentException('The selected destination is not available.');
            }
        }
        if ($country !== '') {
            $countryExists = $this->pdo->prepare('SELECT 1 FROM countries WHERE alpha2 = :code LIMIT 1');
            $countryExists->execute(['code'=>$country]);
            if ($countryExists->fetchColumn() === false) {
                throw new \InvalidArgumentException('The selected country is not available.');
            }
        }

        $id = self::uuid();
        $reference = 'TV-' . strtoupper(bin2hex(random_bytes(5)));
        $attributionValue = self::safeAttribution($attribution);
        $insert = $this->pdo->prepare(
            'INSERT INTO enquiries
             (id,reference,name,email,phone,country_code,destination_id,city,subject,message,privacy_acknowledged_at,privacy_policy_version,
              status,assigned_to,attribution_json,internal_notes,created_at,updated_at)
             VALUES (:id,:reference,:name,:email,:phone,:country,:destination,:city,:subject,:message,UTC_TIMESTAMP(),:privacy_policy_version,
              "new",NULL,:attribution,NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())'
        );
        $this->pdo->beginTransaction();
        try {
            $insert->execute([
                'id'=>$id,'reference'=>$reference,'name'=>$name,'email'=>$email,'phone'=>$phone === '' ? null : $phone,
                'country'=>$country === '' ? null : $country,'destination'=>$destinationId === '' ? null : $destinationId,
                'city'=>$city === '' ? null : $city,'subject'=>$subject,'message'=>$message,
                'attribution'=>$attributionValue === null ? null : json_encode(['source'=>$attributionValue],JSON_THROW_ON_ERROR),
                'privacy_policy_version'=>$privacyPolicyVersion,
            ]);
            $this->audit->record(null,'crm.enquiry.created','enquiry',$id,'Public enquiry submission',null,
                ['reference'=>$reference,'status'=>'new'],'enquiry-'.$requestId,'crm-enquiry-v1');
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        return $reference;
    }

    public function updateStatus(Principal $actor,string $reference,string $status,string $reason,string $requestId): void
    {
        Authorization::require($actor,'enquiries.manage',true);
        $reason = trim($reason);
        if (!preg_match('/^TV-[A-F0-9]{10}$/D',$reference) || !in_array($status,self::STATUSES,true) ||
            mb_strlen($reason,'UTF-8') < 8 || mb_strlen($reason,'UTF-8') > 500 ||
            strlen($requestId) < 16 || strlen($requestId) > 190) {
            throw new \InvalidArgumentException('Enquiry update is invalid.');
        }
        $this->pdo->beginTransaction();
        try {
            $query = $this->pdo->prepare('SELECT id,status,responded_at,closed_at FROM enquiries WHERE reference = :reference FOR UPDATE');
            $query->execute(['reference'=>$reference]);
            $before = $query->fetch();
            if (!is_array($before)) {
                throw new \DomainException('Enquiry does not exist.');
            }
            $respondedAt = $before['responded_at'];
            $closedAt = $before['closed_at'];
            if ($status === 'responded' && $respondedAt === null) {
                $respondedAt = gmdate('Y-m-d H:i:s');
            }
            $closedAt = $status === 'closed' ? ($closedAt ?? gmdate('Y-m-d H:i:s')) : null;
            $update = $this->pdo->prepare(
                'UPDATE enquiries SET status = :status,responded_at = :responded,closed_at = :closed,updated_at = UTC_TIMESTAMP() WHERE id = :id'
            );
            $update->execute(['status'=>$status,'responded'=>$respondedAt,'closed'=>$closedAt,'id'=>$before['id']]);
            $this->audit->record($actor->id,'crm.enquiry.status_changed','enquiry',(string) $before['id'],$reason,
                ['status'=>$before['status']],
                ['status'=>$status,'responded_at'=>$respondedAt,'closed_at'=>$closedAt],
                $requestId,'crm-enquiry-v1');
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function assign(Principal $actor,string $reference,?string $assigneeId,string $reason,string $requestId): void
    {
        Authorization::require($actor,'enquiries.manage',true);
        $assigneeId = $assigneeId === null || trim($assigneeId) === '' ? null : trim($assigneeId);
        $reason = trim($reason);
        if (!preg_match('/^TV-[A-F0-9]{10}$/D',$reference) ||
            ($assigneeId !== null && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD',$assigneeId)) ||
            mb_strlen($reason,'UTF-8') < 8 || mb_strlen($reason,'UTF-8') > 500 ||
            strlen($requestId) < 16 || strlen($requestId) > 190) {
            throw new \InvalidArgumentException('Enquiry assignment is invalid.');
        }
        $this->pdo->beginTransaction();
        try {
            $query=$this->pdo->prepare('SELECT id,assigned_to FROM enquiries WHERE reference=:reference FOR UPDATE');
            $query->execute(['reference'=>$reference]);
            $before=$query->fetch();
            if (!is_array($before)) {
                throw new \DomainException('Enquiry does not exist.');
            }
            if ($assigneeId !== null) {
                $eligible=$this->pdo->prepare(
                    "SELECT u.id FROM users u INNER JOIN user_roles ur ON ur.user_id=u.id
                     WHERE u.id=:id AND u.status='active' AND ur.role_key IN ('super_admin','owner','admin')
                     LIMIT 1"
                );
                $eligible->execute(['id'=>$assigneeId]);
                if ($eligible->fetchColumn() === false) {
                    throw new \InvalidArgumentException('Choose an active administrator or owner.');
                }
            }
            $update=$this->pdo->prepare('UPDATE enquiries SET assigned_to=:assignee,updated_at=UTC_TIMESTAMP() WHERE id=:id');
            $update->execute(['assignee'=>$assigneeId,'id'=>$before['id']]);
            $this->audit->record($actor->id,'crm.enquiry.assigned','enquiry',(string)$before['id'],$reason,
                ['assigned_to'=>$before['assigned_to']],['assigned_to'=>$assigneeId],$requestId,'crm-enquiry-v1');
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function addInternalNote(Principal $actor,string $reference,string $note,string $requestId): void
    {
        Authorization::require($actor,'enquiries.manage',true);
        $note=trim($note);
        if(!preg_match('/^TV-[A-F0-9]{10}$/D',$reference) || mb_strlen($note,'UTF-8')<10 ||
            mb_strlen($note,'UTF-8')>3000 || strlen($requestId)<16 || strlen($requestId)>190 ||
            $this->notesCipher===null) {
            throw new \InvalidArgumentException('Internal note details are invalid or encryption is unavailable.');
        }
        $this->pdo->beginTransaction();
        try {
            $query=$this->pdo->prepare('SELECT id FROM enquiries WHERE reference=:reference FOR UPDATE');
            $query->execute(['reference'=>$reference]);
            $enquiryId=$query->fetchColumn();
            if(!is_string($enquiryId)) throw new \DomainException('Enquiry does not exist.');
            $id=self::uuid();
            $ciphertext=$this->notesCipher->encrypt($note,'crm-enquiry-note-v1:'.$enquiryId);
            $this->pdo->prepare('INSERT INTO crm_enquiry_notes (id,enquiry_id,author_user_id,ciphertext,created_at) VALUES (:id,:enquiry,:author,:ciphertext,UTC_TIMESTAMP())')
                ->execute(['id'=>$id,'enquiry'=>$enquiryId,'author'=>$actor->id,'ciphertext'=>$ciphertext]);
            $this->audit->record($actor->id,'crm.enquiry.note_added','enquiry',$enquiryId,
                'Private internal note appended to enquiry',null,['note_id'=>$id,'character_count'=>mb_strlen($note,'UTF-8')],
                $requestId,'crm-enquiry-v1');
            $this->pdo->commit();
        } catch(\Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function scheduleReminder(Principal $actor,string $reference,string $dueAt,string $reason,string $requestId): string
    {
        Authorization::require($actor,'enquiries.manage',true);
        $reason=trim($reason);
        $due=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$dueAt,new \DateTimeZone('UTC'));
        $errors=\DateTimeImmutable::getLastErrors();
        if(!preg_match('/^TV-[A-F0-9]{10}$/D',$reference) || $due===false ||
            ($errors!==false && ($errors['warning_count']>0 || $errors['error_count']>0)) ||
            $due->format('Y-m-d H:i:s')!==$dueAt || $due<=new \DateTimeImmutable('now',new \DateTimeZone('UTC')) ||
            $due>new \DateTimeImmutable('+365 days',new \DateTimeZone('UTC')) ||
            mb_strlen($reason,'UTF-8')<8 || mb_strlen($reason,'UTF-8')>500 ||
            strlen($requestId)<16 || strlen($requestId)>190) {
            throw new \InvalidArgumentException('CRM reminder details are invalid.');
        }
        $this->pdo->beginTransaction();
        try {
            $query=$this->pdo->prepare('SELECT id,status FROM enquiries WHERE reference=:reference FOR UPDATE');
            $query->execute(['reference'=>$reference]);
            $enquiry=$query->fetch();
            if(!is_array($enquiry) || in_array($enquiry['status'],['closed','spam'],true)) {
                throw new \DomainException('A reminder requires an open enquiry.');
            }
            $id=self::uuid();
            $this->pdo->prepare('INSERT INTO crm_enquiry_reminders (id,enquiry_id,created_by,due_at,created_at) VALUES (:id,:enquiry,:actor,:due,UTC_TIMESTAMP())')
                ->execute(['id'=>$id,'enquiry'=>$enquiry['id'],'actor'=>$actor->id,'due'=>$dueAt]);
            $this->audit->record($actor->id,'crm.enquiry.reminder_scheduled','enquiry',(string)$enquiry['id'],$reason,
                null,['reminder_id'=>$id,'due_at'=>$dueAt],$requestId,'crm-enquiry-reminder-v1');
            $this->pdo->commit();
            return $id;
        } catch(\Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function completeReminder(Principal $actor,string $reminderId,string $reason,string $requestId): void
    {
        Authorization::require($actor,'enquiries.manage',true);
        $reason=trim($reason);
        if(!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD',$reminderId) ||
            mb_strlen($reason,'UTF-8')<8 || mb_strlen($reason,'UTF-8')>500 ||
            strlen($requestId)<16 || strlen($requestId)>190) {
            throw new \InvalidArgumentException('CRM reminder completion is invalid.');
        }
        $this->pdo->beginTransaction();
        try {
            $query=$this->pdo->prepare('SELECT r.id,r.enquiry_id,r.completed_at FROM crm_enquiry_reminders r WHERE r.id=:id FOR UPDATE');
            $query->execute(['id'=>$reminderId]);
            $reminder=$query->fetch();
            if(!is_array($reminder) || $reminder['completed_at']!==null) {
                throw new \DomainException('Reminder does not exist or is already complete.');
            }
            $this->pdo->prepare('UPDATE crm_enquiry_reminders SET completed_at=UTC_TIMESTAMP(),completed_by=:actor WHERE id=:id')
                ->execute(['actor'=>$actor->id,'id'=>$reminderId]);
            $this->audit->record($actor->id,'crm.enquiry.reminder_completed','enquiry',(string)$reminder['enquiry_id'],$reason,
                ['completed'=>false],['completed'=>true,'reminder_id'=>$reminderId],$requestId,'crm-enquiry-reminder-v1');
            $this->pdo->commit();
        } catch(\Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    /** @return list<array{id:string,email:string,role_key:string}> */
    public function eligibleAssignees(Principal $actor): array
    {
        Authorization::require($actor,'enquiries.manage',true);
        $query=$this->pdo->query(
            "SELECT u.id,u.email,MIN(ur.role_key) AS role_key
             FROM users u INNER JOIN user_roles ur ON ur.user_id=u.id
             WHERE u.status='active' AND ur.role_key IN ('super_admin','owner','admin')
             GROUP BY u.id,u.email ORDER BY u.email"
        );
        return $query->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function list(Principal $actor,int $limit = 50,int $offset = 0): array
    {
        Authorization::require($actor,'enquiries.manage',true);
        $limit = max(1,min(100,$limit));
        $offset = max(0,min(100000,$offset));
        $query = $this->pdo->prepare(
            'SELECT e.reference,e.name,e.email,e.phone,e.city,e.subject,e.message,e.status,e.created_at,e.responded_at,e.closed_at,e.assigned_to,u.email AS assignee_email
             FROM enquiries e LEFT JOIN users u ON u.id=e.assigned_to ORDER BY e.created_at DESC,e.id DESC LIMIT :limit OFFSET :offset'
        );
        $query->bindValue(':limit',$limit,PDO::PARAM_INT);
        $query->bindValue(':offset',$offset,PDO::PARAM_INT);
        $query->execute();
        $items=$query->fetchAll();
        if($items!==[] && $this->notesCipher!==null) {
            $ids=array_column($items,'reference');
            $placeholders=[];
            $params=[];
            foreach($ids as $index=>$reference){$key='ref'.$index;$placeholders[]=':'.$key;$params[$key]=$reference;}
            $notesQuery=$this->pdo->prepare(
                'SELECT n.id,n.enquiry_id,e.reference,n.author_user_id,u.email AS author_email,n.ciphertext,n.created_at
                 FROM crm_enquiry_notes n INNER JOIN enquiries e ON e.id=n.enquiry_id
                 INNER JOIN users u ON u.id=n.author_user_id
                 WHERE e.reference IN ('.implode(',',$placeholders).')
                 ORDER BY n.created_at DESC,n.id DESC'
            );
            $notesQuery->execute($params);
            $byReference=[];
            foreach($notesQuery->fetchAll() as $noteRow) {
                $reference=(string)$noteRow['reference'];
                $byReference[$reference]??=[];
                if(count($byReference[$reference])>=10) continue;
                $noteRow['body']=$this->notesCipher->decrypt((string)$noteRow['ciphertext'],'crm-enquiry-note-v1:'.$noteRow['enquiry_id']);
                unset($noteRow['ciphertext'],$noteRow['enquiry_id']);
                $byReference[$reference][]=$noteRow;
            }
            foreach($items as &$item) $item['internal_notes']=$byReference[$item['reference']]??[];
            unset($item);
        } else {
            foreach($items as &$item) $item['internal_notes']=[];
            unset($item);
        }
        if($items!==[]) {
            $references=array_column($items,'reference');
            $placeholders=[];$params=[];
            foreach($references as $index=>$reference){$key='r'.$index;$placeholders[]=':'.$key;$params[$key]=$reference;}
            $reminderQuery=$this->pdo->prepare(
                'SELECT r.id,e.reference,r.due_at,r.completed_at,r.created_at
                 FROM crm_enquiry_reminders r INNER JOIN enquiries e ON e.id=r.enquiry_id
                 WHERE e.reference IN ('.implode(',',$placeholders).')
                 ORDER BY r.due_at ASC,r.created_at DESC'
            );
            $reminderQuery->execute($params);
            $byReference=[];
            foreach($reminderQuery->fetchAll() as $reminder) {
                $reference=(string)$reminder['reference'];
                $byReference[$reference]??=[];
                if(count($byReference[$reference])>=10) continue;
                unset($reminder['reference']);
                $byReference[$reference][]=$reminder;
            }
            foreach($items as &$item) $item['reminders']=$byReference[$item['reference']]??[];
            unset($item);
        }
        return $items;
    }

    private static function safeAttribution(?string $referer): ?string
    {
        if ($referer === null || strlen($referer) > 2048) {
            return null;
        }
        $parts = parse_url($referer);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')),['http','https'],true) ||
            !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $host = strtolower((string) $parts['host']);
        if (preg_match('/^[a-z0-9.-]+$/D',$host) !== 1) {
            return null;
        }
        $port = isset($parts['port']) && in_array((int) $parts['port'],[80,443],true) ? ':' . (int) $parts['port'] : '';
        $path = (string) ($parts['path'] ?? '/');
        if (preg_match('/[\r\n\x00-\x20]/',$path) === 1) {
            return null;
        }
        return substr(strtolower((string) $parts['scheme']) . '://' . $host . $port . $path,0,500);
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }
}
