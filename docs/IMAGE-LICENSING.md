# Talyvane image licensing and editorial media register

Last reviewed: 19 November 2026

## Media policy

Talyvane uses curated hospitality and destination photography for brand atmosphere and illustrative destination presentation. These images are not companion profile images and do not claim that a photograph depicts a named destination unless the source confirms that location.

Companion profile photographs must be supplied by the actual adult profile owner and covered by documented, specific publication consent and a separate rights/likeness review. Do not use stock models, generated people, scraped social-media images or another person's photographs to populate companion profiles.

For operator-supplied destination imagery, the operator must confirm that it has the rights and permissions needed for commercial use. Avoid identifiable people, visible third-party brands/logos, protected artworks and private-property imagery where releases or permissions are unclear. Remove location metadata before upload. The current upload flow validates image type, dimensions and size; it does not perform EXIF stripping or independently verify copyright, releases or property permissions.

## Approved starter photography — Unsplash

The following images are listed as free to use under the Unsplash License. The Unsplash License permits commercial use and does not require attribution, although photographer credit is included here as a courtesy. The selected assets are hospitality interiors, a resort landscape and a cityscape; they are not used to depict people or imply endorsement.

| Use | Photographer | Unsplash source | CDN asset |
|---|---|---|---|
| Homepage hero — hotel suite lounge | Antonio Araujo | https://unsplash.com/photos/a-well-lit-hotel-room-with-seating-and-a-desk-xQbmc2FnK3Y | https://images.unsplash.com/photo-1776763018821-8feeaeeee0a5 |
| Homepage gallery — resort and pool | Antonio Araujo | https://unsplash.com/photos/luxury-resort-with-infinity-pool-and-lounge-chairs-on-hillside-AFd25jow7mw | https://images.unsplash.com/photo-1776761731066-c89caa8d25e6 |
| Homepage gallery — city skyline | Henry Lai | https://unsplash.com/photos/a-city-skyline-at-night-with-lights-reflecting-off-the-water-cjVFcnp9_fU | https://images.unsplash.com/photo-1681912609128-3a05a7bdce64 |
| Homepage gallery — private dining room | Andy Wang | https://unsplash.com/photos/elegant-dining-room-with-a-round-table-and-chairs-DKxbjOa4hd0 | https://images.unsplash.com/photo-1768397003905-a202ea6325f5 |

## Rejected source — Pexels

Pexels is not an approved source for Talyvane's adult-agency deployments. Its current Terms of Service section 5, prohibited use 84, expressly bars use of Pexels content on or in conjunction with adult entertainment venues and escort services. Earlier Pexels candidate assets were therefore rejected and removed from the website configuration. Do not reintroduce them.

## License and legal references

- Unsplash License: https://unsplash.com/license
- Unsplash Terms and Conditions: https://unsplash.com/terms
- Unsplash guidance on releases and trademarks: https://help.unsplash.com/en/articles/2612329-releases-and-trademarks
- Pexels Terms of Service — rejected for this use case: https://www.pexels.com/terms-of-service/

A stock-site copyright licence does not automatically clear every trademark, privacy, publicity, property, moral or other third-party right that may appear in an image. Unsplash itself warns that recognizable people, private property, trademarks and artworks can require additional permission, and that it cannot guarantee every release. Re-check the individual asset and intended context before use. Retain a copy of the source page and licence review with each deployment's asset register.

## White-label deployment review

Each white-label deployment must review image relevance, brand fit, local advertising rules and market-specific restrictions before launch. A platform-wide asset register is a provenance aid, not a substitute for the deploying operator's own legal review. Operators may replace stock imagery through destination administration; retain their own proof of rights for every replacement.

## Operational recommendations

1. Keep the stock image registry centralized in config/stock_photography.php.
2. Prefer locally optimized WebP/AVIF copies for production, with source attribution and licence evidence retained. External Unsplash CDN URLs are currently used as a lightweight starter and depend on third-party availability.
3. Replace generic destination fallbacks with real, licensed location photography as operators expand into new markets.
4. Do not place stock images in companion profile cards or detail pages.
5. Require a documented rights declaration for operator-uploaded destination images. Add EXIF removal, image moderation, retention and takedown workflows before accepting companion media.
6. Review all third-party media before each commercial white-label deployment. Stock images are non-exclusive and must not be represented as proprietary or exclusive brand assets.
