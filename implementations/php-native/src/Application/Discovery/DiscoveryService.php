<?php
declare(strict_types=1);

namespace Talyvane\Application\Discovery;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Talyvane\Domain\Market\MarketPolicy;

/** Read-only public discovery; returns only approved public fields and excludes all unverified profiles. */
final class DiscoveryService
{
    public function __construct(private PDO $pdo) {}

    /** @return list<array<string,mixed>> */
    public function destinations(): array
    {
        $query = $this->pdo->query(
            'SELECT d.id,d.slug,d.country_code,d.city_name,d.display_name,d.currency_code,d.locale,d.time_zone,
                    m.enabled AS market_enabled,m.decision AS market_decision,m.reviewer_id,m.reviewed_at,
                    m.expires_at,m.evidence_reference,m.review_summary
             FROM destinations d JOIN countries c ON c.alpha2 = d.country_code
             JOIN market_policies m ON m.country_code = d.country_code
             WHERE d.status = "published" AND c.enabled = 1 AND m.enabled = 1 AND m.decision = "approved"
             ORDER BY d.display_name ASC'
        );
        $items = [];
        foreach ($query->fetchAll() as $row) {
            if (self::marketIsOperational($row)) {
                $items[] = [
                    'id'=>(string) $row['id'],
                    'slug'=>(string) $row['slug'],
                    'country_code'=>(string) $row['country_code'],
                    'city_name'=>(string) $row['city_name'],
                    'display_name'=>(string) $row['display_name'],
                    'currency_code'=>$row['currency_code'] === null ? null : (string) $row['currency_code'],
                    'locale'=>$row['locale'] === null ? null : (string) $row['locale'],
                    'time_zone'=>(string) $row['time_zone'],
            'primary_media_url'=>is_string($row['primary_media_id'] ?? null) ? '/profile-media/' . rawurlencode((string) $row['primary_media_id']) : null,
                ];
            }
        }
        return $items;
    }

    /** @return list<array<string,mixed>> */
    public function profiles(string $destinationSlug, int $limit = 24, int $offset = 0): array
    {
        $limit = max(1,min(48,$limit));
        $offset = max(0,min(10000,$offset));
        $query = $this->pdo->prepare(
            'SELECT p.id,p.slug,p.display_name,p.biography,p.languages_json,p.experience_category,d.country_code,
                    d.slug AS destination_slug,d.display_name AS destination_name,d.time_zone,
                    (SELECT pm.id FROM profile_media pm JOIN consent_grants pcg ON pcg.id=pm.consent_grant_id
                     WHERE pm.profile_id=p.id AND pm.review_status="approved" AND pm.media_scope="public"
                       AND pm.consent_version=p.publication_consent_version AND pcg.granted=1 AND pcg.withdrawn_at IS NULL
                       AND pcg.policy_version=p.publication_consent_version ORDER BY pm.created_at ASC LIMIT 1) AS primary_media_id,
                    m.enabled AS market_enabled,m.decision AS market_decision,m.reviewer_id,m.reviewed_at,
                    m.expires_at,m.evidence_reference,m.review_summary
             FROM companion_profiles p
             JOIN destinations d ON d.id = p.destination_id
             JOIN countries c ON c.alpha2 = d.country_code
             JOIN market_policies m ON m.country_code = d.country_code
             WHERE d.slug = :slug AND d.status = "published" AND c.enabled = 1
               AND p.status = "published" AND p.adult_eligibility_status = "verified"
               AND p.adult_verified_at IS NOT NULL AND p.adult_verified_by IS NOT NULL
               AND p.publication_consent_version IS NOT NULL AND p.publication_consent_withdrawn_at IS NULL
               AND EXISTS (
                   SELECT 1 FROM consent_grants cg
                   WHERE cg.subject_type = "companion_profile" AND cg.subject_id = p.id
                     AND cg.purpose = "profile.publication" AND cg.audience = "public"
                     AND cg.policy_version = p.publication_consent_version
                     AND cg.granted = 1 AND cg.withdrawn_at IS NULL
               )
               AND m.enabled = 1 AND m.decision = "approved"
             ORDER BY p.display_name ASC LIMIT :limit OFFSET :offset'
        );
        $query->bindValue(':slug',$destinationSlug,PDO::PARAM_STR);
        $query->bindValue(':limit',$limit,PDO::PARAM_INT);
        $query->bindValue(':offset',$offset,PDO::PARAM_INT);
        $query->execute();
        $items = [];
        foreach ($query->fetchAll() as $row) {
            if (!self::marketIsOperational($row)) {
                continue;
            }
            $items[] = self::publicProfileFields($row);
        }
        return $items;
    }

    /** @return array<string,mixed>|null */
    public function profile(string $slug): ?array
    {
        $query = $this->pdo->prepare(
            'SELECT p.id,p.slug,p.display_name,p.biography,p.languages_json,p.experience_category,d.country_code,
                    d.slug AS destination_slug,d.display_name AS destination_name,d.time_zone,
                    m.enabled AS market_enabled,m.decision AS market_decision,m.reviewer_id,m.reviewed_at,
                    m.expires_at,m.evidence_reference,m.review_summary
             FROM companion_profiles p
             JOIN destinations d ON d.id = p.destination_id
             JOIN countries c ON c.alpha2 = d.country_code
             JOIN market_policies m ON m.country_code = d.country_code
             WHERE p.slug = :slug AND d.status = "published" AND c.enabled = 1
               AND p.status = "published" AND p.adult_eligibility_status = "verified"
               AND p.adult_verified_at IS NOT NULL AND p.adult_verified_by IS NOT NULL
               AND p.publication_consent_version IS NOT NULL AND p.publication_consent_withdrawn_at IS NULL
               AND EXISTS (
                   SELECT 1 FROM consent_grants cg
                   WHERE cg.subject_type = "companion_profile" AND cg.subject_id = p.id
                     AND cg.purpose = "profile.publication" AND cg.audience = "public"
                     AND cg.policy_version = p.publication_consent_version
                     AND cg.granted = 1 AND cg.withdrawn_at IS NULL
               )
               AND m.enabled = 1 AND m.decision = "approved"
             LIMIT 1'
        );
        $query->execute(['slug'=>$slug]);
        $row = $query->fetch();
        if (!is_array($row) || !self::marketIsOperational($row)) { return null; }
        $profile = self::publicProfileFields($row);
        $profile['media'] = $this->publicMediaForProfile((string) $row['id']);
        return $profile;
    }

    /** @return list<array{id:string,url:string}> */
    private function publicMediaForProfile(string $profileId): array
    {
        $query=$this->pdo->prepare(
            'SELECT pm.id FROM profile_media pm
             JOIN companion_profiles p ON p.id=pm.profile_id
             JOIN consent_grants cg ON cg.id=pm.consent_grant_id
             WHERE pm.profile_id=:profile AND pm.review_status="approved" AND pm.media_scope="public"
               AND pm.consent_version=p.publication_consent_version
               AND cg.granted=1 AND cg.withdrawn_at IS NULL
               AND cg.policy_version=p.publication_consent_version
             ORDER BY pm.created_at ASC LIMIT 12'
        );
        $query->execute(['profile'=>$profileId]);
        return array_map(static fn(array $media): array=>[
            'id'=>(string) $media['id'],'url'=>'/profile-media/' . rawurlencode((string) $media['id']),
        ],$query->fetchAll());
    }

    /** @param array<string,mixed> $row */
    private static function marketIsOperational(array $row): bool
    {
        $utc = new DateTimeZone('UTC');
        $market = new MarketPolicy(
            (string) ($row['country_code'] ?? ''),
            (bool) ($row['market_enabled'] ?? false),
            (string) ($row['market_decision'] ?? ''),
            isset($row['reviewer_id']) ? (string) $row['reviewer_id'] : null,
            empty($row['reviewed_at']) ? null : new DateTimeImmutable((string) $row['reviewed_at'],$utc),
            empty($row['expires_at']) ? null : new DateTimeImmutable((string) $row['expires_at'],$utc),
            isset($row['evidence_reference']) ? (string) $row['evidence_reference'] : null,
            isset($row['review_summary']) ? (string) $row['review_summary'] : null
        );
        return $market->operationalAt(new DateTimeImmutable('now',$utc));
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function publicProfileFields(array $row): array
    {
        $languages = [];
        if (is_string($row['languages_json'] ?? null)) {
            $decoded = json_decode($row['languages_json'],true);
            if (is_array($decoded)) {
                $languages = array_values(array_filter($decoded,static fn($value): bool => is_string($value)));
            }
        }
        return [
            'id'=>(string) $row['id'],
            'slug'=>(string) $row['slug'],
            'display_name'=>(string) $row['display_name'],
            'biography'=>$row['biography'] === null ? null : (string) $row['biography'],
            'languages'=>$languages,
            'experience_category'=>$row['experience_category'] === null ? null : (string) $row['experience_category'],
            'destination_slug'=>(string) $row['destination_slug'],
            'destination_name'=>(string) $row['destination_name'],
            'time_zone'=>(string) $row['time_zone'],
        ];
    }
}
