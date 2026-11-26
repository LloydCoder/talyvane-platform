<?php
declare(strict_types=1);

/** Bind OTP-verified applicant portfolio withdrawal requests to one owned private asset. */
return [
    "ALTER TABLE model_applicant_rights_requests
        ADD COLUMN related_asset_id CHAR(36) NULL AFTER application_id,
        ADD KEY model_rights_asset_idx (related_asset_id,status),
        ADD CONSTRAINT model_rights_asset_fk FOREIGN KEY (related_asset_id) REFERENCES model_portfolio_assets(id)"
];
