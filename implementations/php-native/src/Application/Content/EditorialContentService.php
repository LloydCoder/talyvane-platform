<?php
declare(strict_types=1);

namespace Talyvane\Application\Content;

use PDO;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Security\Authorization;
use Talyvane\Security\Principal;

/** Versioned editorial content whose public projection is pinned to an approved snapshot. */
final class EditorialContentService
{
    public function __construct(private PDO $pdo,private AuditLogger $audit) {}

    public function saveFaq(
        Principal $actor,?string $faqId,string $question,string $answer,string $locale,
        string $categoryKey,int $sortOrder,string $reason,string $requestId
    ): string {
        Authorization::require($actor,'site.content.manage',true);
        $question=trim($question);
        $answer=trim($answer);
        $locale=trim($locale);
        $categoryKey=strtolower(trim($categoryKey));
        $reason=trim($reason);
        if ($question==='' || strlen($question)>500 || $answer==='' || strlen($answer)>50000 ||
            !preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/D',$locale) ||
            !preg_match('/^[a-z][a-z0-9_-]{1,79}$/D',$categoryKey) ||
            $sortOrder < -10000 || $sortOrder > 10000 ||
            strlen($reason)<8 || strlen($reason)>500 ||
            strlen($requestId)<16 || strlen($requestId)>64 ||
            ($faqId!==null && !self::validUuid($faqId))) {
            throw new \InvalidArgumentException('FAQ content, locale, category, ordering or audit context is invalid.');
        }

        $this->pdo->beginTransaction();
        try {
            $before=null;
            if ($faqId===null) {
                $id=self::uuid();
                $version=1;
                $this->pdo->prepare(
                    'INSERT INTO site_faqs
                     (id,question,answer,locale,category_key,sort_order,status,version,published_version,created_by,updated_by,created_at,updated_at)
                     VALUES (:id,:question,:answer,:locale,:category,:sort,"draft",1,NULL,:actor,:actor,UTC_TIMESTAMP(),UTC_TIMESTAMP())'
                )->execute([
                    'id'=>$id,'question'=>$question,'answer'=>$answer,'locale'=>$locale,
                    'category'=>$categoryKey,'sort'=>$sortOrder,'actor'=>$actor->id,
                ]);
            } else {
                $id=$faqId;
                $query=$this->pdo->prepare(
                    'SELECT question,answer,locale,category_key,sort_order,status,version,published_version
                     FROM site_faqs WHERE id=:id FOR UPDATE'
                );
                $query->execute(['id'=>$id]);
                $current=$query->fetch();
                if (!is_array($current)) {
                    throw new \DomainException('FAQ does not exist.');
                }
                $before=[
                    'question'=>$current['question'],'locale'=>$current['locale'],
                    'category_key'=>$current['category_key'],'sort_order'=>(int)$current['sort_order'],
                    'status'=>$current['status'],'version'=>(int)$current['version'],
                    'published_version'=>$current['published_version']===null?null:(int)$current['published_version'],
                ];
                $version=(int)$current['version']+1;
                $this->pdo->prepare(
                    'UPDATE site_faqs SET question=:question,answer=:answer,locale=:locale,
                     category_key=:category,sort_order=:sort,version=:version,updated_by=:actor,updated_at=UTC_TIMESTAMP()
                     WHERE id=:id'
                )->execute([
                    'question'=>$question,'answer'=>$answer,'locale'=>$locale,'category'=>$categoryKey,
                    'sort'=>$sortOrder,'version'=>$version,'actor'=>$actor->id,'id'=>$id,
                ]);
            }
            $this->pdo->prepare(
                'INSERT INTO site_faq_versions
                 (id,faq_id,version,question,answer,locale,category_key,sort_order,changed_by,change_reason,created_at)
                 VALUES (:version_id,:faq,:version,:question,:answer,:locale,:category,:sort,:actor,:reason,UTC_TIMESTAMP())'
            )->execute([
                'version_id'=>self::uuid(),'faq'=>$id,'version'=>$version,'question'=>$question,'answer'=>$answer,
                'locale'=>$locale,'category'=>$categoryKey,'sort'=>$sortOrder,'actor'=>$actor->id,'reason'=>$reason,
            ]);
            $this->audit->record($actor->id,'cms.faq.saved','site_faq',$id,$reason,$before,
                ['question'=>$question,'locale'=>$locale,'category_key'=>$categoryKey,'sort_order'=>$sortOrder,'version'=>$version],
                $requestId,'cms-faq-v1');
            $this->pdo->commit();
            return $id;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function publishFaq(Principal $actor,string $faqId,string $reason,string $requestId): void
    {
        Authorization::require($actor,'site.content.manage',true);
        $reason=trim($reason);
        if (!self::validUuid($faqId) || strlen($reason)<8 || strlen($reason)>500 ||
            strlen($requestId)<16 || strlen($requestId)>64) {
            throw new \InvalidArgumentException('FAQ publication context is invalid.');
        }
        $this->pdo->beginTransaction();
        try {
            $query=$this->pdo->prepare('SELECT status,version,published_version FROM site_faqs WHERE id=:id FOR UPDATE');
            $query->execute(['id'=>$faqId]);
            $row=$query->fetch();
            if (!is_array($row)) throw new \DomainException('FAQ does not exist.');
            $version=(int)$row['version'];
            $published=$row['published_version']===null?null:(int)$row['published_version'];
            if ($published===$version && $row['status']==='published') {
                throw new \DomainException('There are no unpublished FAQ changes to promote.');
            }
            $this->pdo->prepare(
                'UPDATE site_faqs SET status="published",published_version=:version,updated_by=:actor,updated_at=UTC_TIMESTAMP() WHERE id=:id'
            )->execute(['version'=>$version,'actor'=>$actor->id,'id'=>$faqId]);
            $this->audit->record($actor->id,'cms.faq.published','site_faq',$faqId,$reason,
                ['status'=>$row['status'],'version'=>$version,'published_version'=>$published],
                ['status'=>'published','version'=>$version,'published_version'=>$version],
                $requestId,'cms-faq-v1');
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    /** @return list<array<string,mixed>> */
    public function publicFaqs(string $locale): array
    {
        if (!preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/D',$locale)) {
            throw new \InvalidArgumentException('FAQ locale is invalid.');
        }
        $query=$this->pdo->prepare(
            'SELECT v.question,v.answer,v.locale,v.category_key,v.sort_order,v.version
             FROM site_faqs f
             INNER JOIN site_faq_versions v ON v.faq_id=f.id AND v.version=f.published_version
             WHERE f.status="published" AND f.published_version IS NOT NULL AND v.locale=:locale
             ORDER BY v.category_key,v.sort_order,v.question LIMIT 500'
        );
        $query->execute(['locale'=>$locale]);
        return $query->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function adminFaqs(): array
    {
        return $this->pdo->query(
            'SELECT id,question,locale,category_key,sort_order,status,version,published_version,updated_at
             FROM site_faqs ORDER BY updated_at DESC,id DESC LIMIT 200'
        )->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function faq(string $faqId): ?array
    {
        if (!self::validUuid($faqId)) return null;
        $query=$this->pdo->prepare(
            'SELECT id,question,answer,locale,category_key,sort_order,status,version,published_version,updated_at
             FROM site_faqs WHERE id=:id LIMIT 1'
        );
        $query->execute(['id'=>$faqId]);
        $row=$query->fetch();
        return is_array($row)?$row:null;
    }

    private static function validUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD',$value)===1;
    }

    private static function uuid(): string
    {
        $bytes=random_bytes(16);
        $bytes[6]=chr((ord($bytes[6])&0x0f)|0x40);
        $bytes[8]=chr((ord($bytes[8])&0x3f)|0x80);
        $hex=bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }
}
