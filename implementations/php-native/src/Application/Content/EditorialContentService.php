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
                     VALUES (:id,:question,:answer,:locale,:category,:sort,"draft",1,NULL,:created_by,:updated_by,UTC_TIMESTAMP(),UTC_TIMESTAMP())'
                )->execute([
                    'id'=>$id,'question'=>$question,'answer'=>$answer,'locale'=>$locale,
                    'category'=>$categoryKey,'sort'=>$sortOrder,'created_by'=>$actor->id,'updated_by'=>$actor->id,
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

    public function saveDestinationGuide(
        Principal $actor,?string $guideId,string $destinationId,string $title,string $content,string $locale,
        ?string $metaTitle,?string $metaDescription,string $reason,string $requestId
    ): string {
        Authorization::require($actor,'site.content.manage',true);
        $title=trim($title);
        $content=trim($content);
        $locale=trim($locale);
        $reason=trim($reason);
        $metaTitle=$metaTitle===null?null:trim($metaTitle);
        $metaDescription=$metaDescription===null?null:trim($metaDescription);
        if (!self::validUuid($destinationId) || ($guideId!==null && !self::validUuid($guideId)) ||
            $title==='' || strlen($title)>190 || $content==='' || strlen($content)>100000 ||
            !preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/D',$locale) ||
            ($metaTitle!==null && strlen($metaTitle)>190) ||
            ($metaDescription!==null && strlen($metaDescription)>320) ||
            strlen($reason)<8 || strlen($reason)>500 || strlen($requestId)<16 || strlen($requestId)>64) {
            throw new \InvalidArgumentException('Destination guide content, metadata or audit context is invalid.');
        }
        $destination=$this->pdo->prepare('SELECT 1 FROM destinations WHERE id=:id LIMIT 1');
        $destination->execute(['id'=>$destinationId]);
        if ($destination->fetchColumn()===false) throw new \DomainException('Destination does not exist.');

        $this->pdo->beginTransaction();
        try {
            $before=null;
            if ($guideId===null) {
                $id=self::uuid();
                $version=1;
                $this->pdo->prepare(
                    'INSERT INTO destination_guides
                     (id,destination_id,locale,title,content,status,updated_by,created_at,updated_at,version,published_version,meta_title,meta_description)
                     VALUES (:id,:destination,:locale,:title,:content,"draft",:actor,UTC_TIMESTAMP(),UTC_TIMESTAMP(),1,NULL,:meta_title,:meta_description)'
                )->execute([
                    'id'=>$id,'destination'=>$destinationId,'locale'=>$locale,'title'=>$title,'content'=>$content,
                    'actor'=>$actor->id,'meta_title'=>$metaTitle,'meta_description'=>$metaDescription,
                ]);
            } else {
                $id=$guideId;
                $query=$this->pdo->prepare(
                    'SELECT destination_id,locale,title,content,status,version,published_version,meta_title,meta_description
                     FROM destination_guides WHERE id=:id FOR UPDATE'
                );
                $query->execute(['id'=>$id]);
                $current=$query->fetch();
                if (!is_array($current)) throw new \DomainException('Destination guide does not exist.');
                $before=[
                    'destination_id'=>$current['destination_id'],'locale'=>$current['locale'],'title'=>$current['title'],
                    'status'=>$current['status'],'version'=>(int)$current['version'],
                    'published_version'=>$current['published_version']===null?null:(int)$current['published_version'],
                ];
                $version=(int)$current['version']+1;
                $this->pdo->prepare(
                    'UPDATE destination_guides SET destination_id=:destination,locale=:locale,title=:title,content=:content,
                     meta_title=:meta_title,meta_description=:meta_description,version=:version,updated_by=:actor,updated_at=UTC_TIMESTAMP()
                     WHERE id=:id'
                )->execute([
                    'destination'=>$destinationId,'locale'=>$locale,'title'=>$title,'content'=>$content,
                    'meta_title'=>$metaTitle,'meta_description'=>$metaDescription,'version'=>$version,'actor'=>$actor->id,'id'=>$id,
                ]);
            }
            $this->pdo->prepare(
                'INSERT INTO destination_guide_versions
                 (id,guide_id,version,title,content,locale,meta_title,meta_description,changed_by,change_reason,created_at)
                 VALUES (:version_id,:guide,:version,:title,:content,:locale,:meta_title,:meta_description,:actor,:reason,UTC_TIMESTAMP())'
            )->execute([
                'version_id'=>self::uuid(),'guide'=>$id,'version'=>$version,'title'=>$title,'content'=>$content,
                'locale'=>$locale,'meta_title'=>$metaTitle,'meta_description'=>$metaDescription,
                'actor'=>$actor->id,'reason'=>$reason,
            ]);
            $this->audit->record($actor->id,'cms.destination_guide.saved','destination_guide',$id,$reason,$before,
                ['destination_id'=>$destinationId,'locale'=>$locale,'title'=>$title,'version'=>$version],
                $requestId,'cms-destination-guide-v1');
            $this->pdo->commit();
            return $id;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function publishDestinationGuide(Principal $actor,string $guideId,string $reason,string $requestId): void
    {
        Authorization::require($actor,'site.content.manage',true);
        $reason=trim($reason);
        if (!self::validUuid($guideId) || strlen($reason)<8 || strlen($reason)>500 ||
            strlen($requestId)<16 || strlen($requestId)>64) {
            throw new \InvalidArgumentException('Destination guide publication context is invalid.');
        }
        $this->pdo->beginTransaction();
        try {
            $query=$this->pdo->prepare('SELECT status,version,published_version FROM destination_guides WHERE id=:id FOR UPDATE');
            $query->execute(['id'=>$guideId]);
            $row=$query->fetch();
            if (!is_array($row)) throw new \DomainException('Destination guide does not exist.');
            $version=(int)$row['version'];
            $published=$row['published_version']===null?null:(int)$row['published_version'];
            if ($published===$version && $row['status']==='published') {
                throw new \DomainException('There are no unpublished destination guide changes to promote.');
            }
            $this->pdo->prepare(
                'UPDATE destination_guides SET status="published",published_version=:version,updated_by=:actor,updated_at=UTC_TIMESTAMP() WHERE id=:id'
            )->execute(['version'=>$version,'actor'=>$actor->id,'id'=>$guideId]);
            $this->audit->record($actor->id,'cms.destination_guide.published','destination_guide',$guideId,$reason,
                ['status'=>$row['status'],'version'=>$version,'published_version'=>$published],
                ['status'=>'published','version'=>$version,'published_version'=>$version],
                $requestId,'cms-destination-guide-v1');
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    /** @return array<string,mixed>|null */
    public function publicDestinationGuide(string $destinationId,string $locale): ?array
    {
        if (!self::validUuid($destinationId) || !preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/D',$locale)) {
            throw new \InvalidArgumentException('Destination guide lookup context is invalid.');
        }
        $query=$this->pdo->prepare(
            'SELECT v.title,v.content,v.locale,v.meta_title,v.meta_description,v.version
             FROM destination_guides g
             INNER JOIN destination_guide_versions v ON v.guide_id=g.id AND v.version=g.published_version
             WHERE g.destination_id=:destination AND g.status="published"
               AND g.published_version IS NOT NULL AND v.locale=:locale
             LIMIT 1'
        );
        $query->execute(['destination'=>$destinationId,'locale'=>$locale]);
        $row=$query->fetch();
        return is_array($row)?$row:null;
    }

    /** @return list<array<string,mixed>> */
    public function adminDestinationGuides(): array
    {
        return $this->pdo->query(
            'SELECT g.id,g.destination_id,d.display_name AS destination_name,g.title,g.locale,g.status,g.version,
                    g.published_version,g.updated_at
             FROM destination_guides g JOIN destinations d ON d.id=g.destination_id
             ORDER BY g.updated_at DESC,g.id DESC LIMIT 200'
        )->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function destinationGuide(string $guideId): ?array
    {
        if (!self::validUuid($guideId)) return null;
        $query=$this->pdo->prepare(
            'SELECT id,destination_id,title,content,locale,status,version,published_version,meta_title,meta_description,updated_at
             FROM destination_guides WHERE id=:id LIMIT 1'
        );
        $query->execute(['id'=>$guideId]);
        $row=$query->fetch();
        return is_array($row)?$row:null;
    }

    public function saveTestimonial(
        Principal $actor,?string $testimonialId,string $displayName,string $quoteText,string $locale,
        string $consentReference,int $sortOrder,string $reason,string $requestId
    ): string {
        Authorization::require($actor,'site.content.manage',true);
        $displayName=trim($displayName);
        $quoteText=trim($quoteText);
        $locale=trim($locale);
        $consentReference=trim($consentReference);
        $reason=trim($reason);
        if ($displayName==='' || strlen($displayName)>120 || $quoteText==='' || strlen($quoteText)>5000 ||
            !preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/D',$locale) ||
            strlen($consentReference)<4 || strlen($consentReference)>190 ||
            preg_match('/[\r\n\x00-\x1F]/',$consentReference)===1 ||
            $sortOrder < -10000 || $sortOrder > 10000 ||
            strlen($reason)<8 || strlen($reason)>500 || strlen($requestId)<16 || strlen($requestId)>64 ||
            ($testimonialId!==null && !self::validUuid($testimonialId))) {
            throw new \InvalidArgumentException('Testimonial content, locale, consent reference or audit context is invalid.');
        }

        $this->pdo->beginTransaction();
        try {
            $before=null;
            if ($testimonialId===null) {
                $id=self::uuid();
                $version=1;
                $this->pdo->prepare(
                    'INSERT INTO testimonials
                     (id,display_name,quote_text,locale,status,consent_reference,sort_order,created_by,created_at,updated_at,
                      version,published_version,updated_by)
                     VALUES (:id,:name,:quote,:locale,"draft",:consent,:sort,:created_by,UTC_TIMESTAMP(),UTC_TIMESTAMP(),
                      1,NULL,:updated_by)'
                )->execute([
                    'id'=>$id,'name'=>$displayName,'quote'=>$quoteText,'locale'=>$locale,'consent'=>$consentReference,
                    'sort'=>$sortOrder,'created_by'=>$actor->id,'updated_by'=>$actor->id,
                ]);
            } else {
                $id=$testimonialId;
                $query=$this->pdo->prepare(
                    'SELECT display_name,quote_text,locale,status,consent_reference,sort_order,version,published_version
                     FROM testimonials WHERE id=:id FOR UPDATE'
                );
                $query->execute(['id'=>$id]);
                $current=$query->fetch();
                if (!is_array($current)) throw new \DomainException('Testimonial does not exist.');
                $before=[
                    'display_name'=>$current['display_name'],'locale'=>$current['locale'],'status'=>$current['status'],
                    'sort_order'=>(int)$current['sort_order'],'version'=>(int)$current['version'],
                    'published_version'=>$current['published_version']===null?null:(int)$current['published_version'],
                    'consent_reference_present'=>trim((string)$current['consent_reference'])!=='',
                ];
                $version=(int)$current['version']+1;
                $this->pdo->prepare(
                    'UPDATE testimonials SET display_name=:name,quote_text=:quote,locale=:locale,
                     consent_reference=:consent,sort_order=:sort,version=:version,updated_by=:actor,updated_at=UTC_TIMESTAMP()
                     WHERE id=:id'
                )->execute([
                    'name'=>$displayName,'quote'=>$quoteText,'locale'=>$locale,'consent'=>$consentReference,
                    'sort'=>$sortOrder,'version'=>$version,'actor'=>$actor->id,'id'=>$id,
                ]);
            }
            $this->pdo->prepare(
                'INSERT INTO testimonial_versions
                 (id,testimonial_id,version,display_name,quote_text,locale,consent_reference,sort_order,changed_by,change_reason,created_at)
                 VALUES (:version_id,:testimonial,:version,:name,:quote,:locale,:consent,:sort,:actor,:reason,UTC_TIMESTAMP())'
            )->execute([
                'version_id'=>self::uuid(),'testimonial'=>$id,'version'=>$version,'name'=>$displayName,
                'quote'=>$quoteText,'locale'=>$locale,'consent'=>$consentReference,'sort'=>$sortOrder,
                'actor'=>$actor->id,'reason'=>$reason,
            ]);
            $this->audit->record($actor->id,'cms.testimonial.saved','testimonial',$id,$reason,$before,
                ['display_name'=>$displayName,'locale'=>$locale,'sort_order'=>$sortOrder,'version'=>$version,
                    'consent_reference_present'=>true],
                $requestId,'cms-testimonial-v1');
            $this->pdo->commit();
            return $id;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function publishTestimonial(Principal $actor,string $testimonialId,string $reason,string $requestId): void
    {
        Authorization::require($actor,'site.content.manage',true);
        $reason=trim($reason);
        if (!self::validUuid($testimonialId) || strlen($reason)<8 || strlen($reason)>500 ||
            strlen($requestId)<16 || strlen($requestId)>64) {
            throw new \InvalidArgumentException('Testimonial publication context is invalid.');
        }
        $this->pdo->beginTransaction();
        try {
            $query=$this->pdo->prepare(
                'SELECT status,version,published_version,consent_reference FROM testimonials WHERE id=:id FOR UPDATE'
            );
            $query->execute(['id'=>$testimonialId]);
            $row=$query->fetch();
            if (!is_array($row)) throw new \DomainException('Testimonial does not exist.');
            if (trim((string)$row['consent_reference'])==='') {
                throw new \DomainException('A recorded consent reference is required before testimonial publication.');
            }
            $version=(int)$row['version'];
            $published=$row['published_version']===null?null:(int)$row['published_version'];
            if ($published===$version && $row['status']==='published') {
                throw new \DomainException('There are no unpublished testimonial changes to promote.');
            }
            $this->pdo->prepare(
                'UPDATE testimonials SET status="published",published_version=:version,updated_by=:actor,updated_at=UTC_TIMESTAMP() WHERE id=:id'
            )->execute(['version'=>$version,'actor'=>$actor->id,'id'=>$testimonialId]);
            $this->audit->record($actor->id,'cms.testimonial.published','testimonial',$testimonialId,$reason,
                ['status'=>$row['status'],'version'=>$version,'published_version'=>$published],
                ['status'=>'published','version'=>$version,'published_version'=>$version,'consent_reference_present'=>true],
                $requestId,'cms-testimonial-v1');
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    /** @return list<array<string,mixed>> */
    public function publicTestimonials(string $locale): array
    {
        if (!preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/D',$locale)) {
            throw new \InvalidArgumentException('Testimonial locale is invalid.');
        }
        $query=$this->pdo->prepare(
            'SELECT v.display_name,v.quote_text,v.locale,v.sort_order,v.version
             FROM testimonials t
             INNER JOIN testimonial_versions v ON v.testimonial_id=t.id AND v.version=t.published_version
             WHERE t.status="published" AND t.published_version IS NOT NULL AND TRIM(v.consent_reference)<>"" AND v.locale=:locale
             ORDER BY v.sort_order,v.display_name LIMIT 100'
        );
        $query->execute(['locale'=>$locale]);
        return $query->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function adminTestimonials(): array
    {
        return $this->pdo->query(
            'SELECT id,display_name,locale,status,sort_order,consent_reference,version,published_version,updated_at
             FROM testimonials ORDER BY updated_at DESC,id DESC LIMIT 200'
        )->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function testimonial(string $testimonialId): ?array
    {
        if (!self::validUuid($testimonialId)) return null;
        $query=$this->pdo->prepare(
            'SELECT id,display_name,quote_text,locale,status,consent_reference,sort_order,version,published_version,updated_at
             FROM testimonials WHERE id=:id LIMIT 1'
        );
        $query->execute(['id'=>$testimonialId]);
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
