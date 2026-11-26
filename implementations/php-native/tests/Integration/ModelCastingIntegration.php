<?php
declare(strict_types=1);

use Talyvane\Application\ModelAgency\ModelCastingWorkflowService;
use Talyvane\Application\ModelAgency\ClientModelAgencyDistributionService;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Infrastructure\Storage\PrivateMediaStorage;
use Talyvane\Security\SecretBox;
use Talyvane\Security\Principal;

function runModelCastingIntegration(PDO $pdo): int
{
    $assertions=0;
    $assert=static function(bool $ok,string $message) use (&$assertions): void {
        $assertions++;
        if(!$ok) throw new RuntimeException('Model casting integration failed: '.$message);
    };
    $adminId=(string)$pdo->query('SELECT id FROM users WHERE email="native-admin@example.test"')->fetchColumn();
    if($adminId==='') throw new RuntimeException('Model casting integration requires the identity integration administrator.');

    $pdo->prepare('UPDATE countries SET enabled=1 WHERE alpha2="GB"')->execute();
    $pdo->prepare('UPDATE currencies SET enabled=1 WHERE code="GBP"')->execute();
    $pdo->prepare(
        'INSERT INTO market_policies (country_code,enabled,decision,reviewer_id,reviewed_at,expires_at,evidence_reference,review_summary,policy_version,updated_at)
         VALUES ("GB",1,"approved",:actor,UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 DAY),"private/evidence/market-gb-approval","Test-only market approval fixture","market-policy-test-v1",UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE enabled=1,decision="approved",reviewer_id=VALUES(reviewer_id),reviewed_at=VALUES(reviewed_at),
         expires_at=VALUES(expires_at),evidence_reference=VALUES(evidence_reference),review_summary=VALUES(review_summary),
         policy_version=VALUES(policy_version),updated_at=UTC_TIMESTAMP()'
    )->execute(['actor'=>$adminId]);
    $pdo->prepare(
        'INSERT INTO deployment_settings (setting_key,setting_json,version,updated_by,updated_at)
         VALUES ("model_agency.casting",:settings,1,:actor,UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE setting_json=VALUES(setting_json),version=version+1,updated_by=VALUES(updated_by),updated_at=UTC_TIMESTAMP()'
    )->execute([
        'settings'=>json_encode(['enabled'=>true,'policy_approved'=>true,'policy_version'=>'casting-policy-test-v1'],JSON_THROW_ON_ERROR),
        'actor'=>$adminId,
    ]);

    foreach([
        'model_agency.portfolio'=>['enabled'=>true,'policy_approved'=>true,'policy_version'=>'model-portfolio-policy-client-test-v1'],
        'model_agency.image_licence'=>['enabled'=>true,'policy_approved'=>true,'policy_version'=>'model-image-licence-policy-client-test-v1'],
    ] as $settingKey=>$settingValue) {
        $pdo->prepare(
            'INSERT INTO deployment_settings (setting_key,setting_json,version,updated_by,updated_at)
             VALUES (:key,:settings,1,:actor,UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE setting_json=VALUES(setting_json),version=version+1,updated_by=VALUES(updated_by),updated_at=UTC_TIMESTAMP()'
        )->execute(['key'=>$settingKey,'settings'=>json_encode($settingValue,JSON_THROW_ON_ERROR),'actor'=>$adminId]);
    }

    $basePath=sys_get_temp_dir().DIRECTORY_SEPARATOR.'talyvane-model-casting-'.bin2hex(random_bytes(6));
    $publicRoot=$basePath.DIRECTORY_SEPARATOR.'public';$privateRoot=$basePath.DIRECTORY_SEPARATOR.'private';
    mkdir($publicRoot,0700,true);mkdir($privateRoot,0700,true);
    $storage=new PrivateMediaStorage($privateRoot,$publicRoot);$cipher=new SecretBox(str_repeat('C',32));
    $service=new ModelCastingWorkflowService($pdo,new AuditLogger($pdo),$storage,$cipher,static fn():bool=>true);
    $admin=new Principal($adminId,['super_admin'],true);
    $disabledService=new ModelCastingWorkflowService($pdo,new AuditLogger($pdo),$storage,$cipher,static fn():bool=>false);
    try {
        $disabledService->createDraft($admin,[],bin2hex(random_bytes(16)));
        $assert(false,'casting draft creation remains disabled when deployment readiness is false');
    } catch(DomainException) {
        $assert(true,'casting draft creation remains disabled when deployment readiness is false');
    }
    $base=new DateTimeImmutable('now',new DateTimeZone('UTC'));
    $terms=[
        'title'=>'Private editorial campaign casting',
        'client_name'=>'Verified test client',
        'client_reference'=>'client-project-private-001',
        'project_purpose'=>'editorial',
        'description'=>'A private editorial campaign brief with clear creative scope and delivery requirements.',
        'requirements'=>'Applicants must hold current adult eligibility verification and explicit distribution rights.',
        'country_code'=>'GB',
        'opens_at'=>$base->modify('+1 day')->format(DATE_ATOM),
        'closes_at'=>$base->modify('+2 days')->format(DATE_ATOM),
        'shoot_starts_at'=>$base->modify('+3 days')->format(DATE_ATOM),
        'shoot_ends_at'=>$base->modify('+4 days')->format(DATE_ATOM),
        'compensation_amount'=>'1250.00',
        'currency_code'=>'GBP',
        'max_submissions'=>25,
    ];
    $callId=$service->createDraft($admin,$terms,bin2hex(random_bytes(16)));
    $query=$pdo->prepare('SELECT status,agreement_id,casting_policy_version,client_name,compensation_amount,currency_code,max_submissions FROM model_casting_calls WHERE id=:id');
    $query->execute(['id'=>$callId]);$draft=$query->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($draft) && $draft['status']==='draft','complete casting brief is persisted as a private draft');
    $assert(is_array($draft) && $draft['agreement_id']===null,'draft creation does not fabricate a representation agreement');
    $assert(is_array($draft) && $draft['casting_policy_version']==='casting-policy-test-v1','draft snapshots the approved policy version');
    $assert(is_array($draft) && $draft['client_name']!=='Verified test client' && $draft['currency_code']==='GBP' && (int)$draft['max_submissions']===25,'casting terms and compensation are stored in typed fields');
    $listed=$service->list($admin);
    $assert(($listed[0]['client_name']??null)==='Verified test client','authorized casting register decrypts client identity only for MFA administrators');

    $service->publish($admin,$callId,bin2hex(random_bytes(16)));
    $published=$pdo->prepare('SELECT status,published_by,published_at FROM model_casting_calls WHERE id=:id');
    $published->execute(['id'=>$callId]);$publishedRow=$published->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($publishedRow) && $publishedRow['status']==='open' && $publishedRow['published_by']===$adminId && $publishedRow['published_at']!==null,'approved draft can be opened with actor and timestamp provenance');

    $clientId=(string)$pdo->query('SELECT id FROM users WHERE email="native-password-client@example.test"')->fetchColumn();
    if($clientId==='') throw new RuntimeException('Model client distribution integration requires the verified client identity fixture.');
    $distribution=new ClientModelAgencyDistributionService($pdo,$storage,new AuditLogger($pdo),$cipher);
    $eligibleClients=$distribution->eligibleClients($admin);
    $assert(count(array_filter($eligibleClients,static fn(array $client):bool=>$client['id']===$clientId))===1,
        'agency distribution picker contains only active verified client-only accounts');
    $distribution->linkCastingCall($admin,$callId,$clientId,bin2hex(random_bytes(16)));
    $clientPrincipal=new Principal($clientId,['client'],false);
    $clientCalls=$distribution->castingCalls($clientPrincipal);
    $assert(count($clientCalls)===1 && $clientCalls[0]['id']===$callId,
        'client sees only the casting call explicitly linked to its verified account');
    try {
        $distribution->candidates(new Principal('00000000-0000-4000-8000-000000000001',['client'],false),$callId);
        $assert(false,'a different client identity cannot enumerate another client casting call');
    } catch(DomainException) {
        $assert(true,'a different client identity cannot enumerate another client casting call');
    }


    $service->close($admin,$callId,'Submission window closed by agency operations.',bin2hex(random_bytes(16)));
    $closed=$pdo->prepare('SELECT status,closed_by,closed_at FROM model_casting_calls WHERE id=:id');
    $closed->execute(['id'=>$callId]);$closedRow=$closed->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($closedRow) && $closedRow['status']==='closed' && $closedRow['closed_by']===$adminId && $closedRow['closed_at']!==null,'open casting call can be closed with reason and actor provenance');

    $cancelId=$service->createDraft($admin,$terms,bin2hex(random_bytes(16)));
    $service->cancel($admin,$cancelId,'Client project withdrawn before publication.',bin2hex(random_bytes(16)));
    $cancelled=$pdo->prepare('SELECT status,cancelled_by,cancelled_at,cancellation_reason FROM model_casting_calls WHERE id=:id');
    $cancelled->execute(['id'=>$cancelId]);$cancelledRow=$cancelled->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($cancelledRow) && $cancelledRow['status']==='cancelled' && $cancelledRow['cancelled_by']===$adminId && $cancelledRow['cancellation_reason']!=='Client project withdrawn before publication.','draft casting call can be cancelled with mandatory reason and provenance');

    $events=$pdo->prepare('SELECT COUNT(*) FROM model_casting_events WHERE call_id IN (:closed,:cancelled)');
    $events->execute(['closed'=>$callId,'cancelled'=>$cancelId]);
    $assert((int)$events->fetchColumn()>=5,'casting lifecycle writes immutable event history for creation, publication, closure and cancellation');
    $audit=$pdo->prepare('SELECT COUNT(*) FROM audit_events WHERE target_type="model_casting_call" AND target_id IN (:closed,:cancelled)');
    $audit->execute(['closed'=>$callId,'cancelled'=>$cancelId]);
    $assert((int)$audit->fetchColumn()>=4,'casting lifecycle changes are written to the platform audit log');

    try {
        $service->list(new Principal($adminId,['client'],false));
        $assert(false,'client role cannot read private agency casting register');
    } catch(DomainException) {
        $assert(true,'client role cannot read private agency casting register');
    }
    try {
        $bad=$terms;$bad['max_submissions']=501;
        $service->createDraft($admin,$bad,bin2hex(random_bytes(16)));
        $assert(false,'casting submission limit above 500 is rejected');
    } catch(InvalidArgumentException) {
        $assert(true,'casting submission limit above 500 is rejected');
    }
    rmdir($privateRoot);rmdir($publicRoot);rmdir($basePath);
    return $assertions;
}
