<?php
declare(strict_types=1);

namespace Talyvane\Application\Clients;

use PDO;
use PDOException;

/** Deployment-specific approval gate for activating the private gallery feature. */
final class MembershipGalleryPolicyReadiness
{
    public static function approved(PDO $pdo,string $agencyName): bool
    {
        try {
            $query=$pdo->prepare(
                'SELECT config_json FROM feature_states
                 WHERE feature_key="memberships.private_gallery" AND enabled=1 LIMIT 1'
            );
            $query->execute();
            $json=$query->fetchColumn();
            if (!is_string($json)) { return false; }
            $policy=json_decode($json,true);
            if (!is_array($policy) || ($policy['private_gallery_policy_approved']??false)!==true ||
                ($policy['private_gallery_policy_version']??null)!==MembershipEntitlementCatalog::PRIVATE_GALLERY_POLICY_VERSION ||
                !is_string($policy['legal_approval_reference']??null) || strlen(trim($policy['legal_approval_reference']))<8 ||
                !is_string($policy['private_gallery_statement_sha256']??null) ||
                !hash_equals(MembershipEntitlementCatalog::privateGalleryConsentStatementHash($agencyName),
                    $policy['private_gallery_statement_sha256'])) {
                return false;
            }
            return $pdo->query(
                'SELECT 1 FROM consent_grants
                 WHERE subject_type="companion_profile" AND purpose="profile.publication"
                   AND audience="public" AND granted=1 AND withdrawn_at IS NULL LIMIT 1'
            )->fetchColumn()!==false;
        } catch (PDOException) {
            return false;
        }
    }

    public static function marketApproved(PDO $pdo): bool
    {
        try {
            return $pdo->query(
                'SELECT 1 FROM market_policies m JOIN countries c ON c.alpha2=m.country_code
                 WHERE m.enabled=1 AND m.decision="approved" AND m.expires_at>UTC_TIMESTAMP()
                   AND c.enabled=1 LIMIT 1'
            )->fetchColumn()!==false;
        } catch (PDOException) {
            return false;
        }
    }
}
