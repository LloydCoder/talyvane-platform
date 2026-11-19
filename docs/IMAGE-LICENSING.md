# Talyvane image licensing and editorial media register

Last reviewed: 19 November 2026

## Media policy

Talyvane uses curated stock photography for hospitality atmosphere and illustrative destination presentation. Stock imagery is not used as a substitute for a real companion's photograph, age verification, identity, consent or availability. Do not imply that a stock photograph depicts the destination named beside it unless the source confirms that location.

Companion profile photographs must be supplied by the actual adult profile owner and must be covered by documented, specific publication consent and a separate rights/likeness review. Do not use stock models, generated people, scraped social-media images or another person's photographs to populate companion profiles.

For operator-supplied destination imagery, the operator must confirm that it has the rights and permissions needed for commercial use. Avoid identifiable people, visible third-party brands/logos, private property restrictions and images with unclear releases. Remove location metadata before upload. The current upload flow validates image type, dimensions and size; it does not perform EXIF stripping or independently verify copyright, releases or property permissions.

## Curated Pexels assets

These assets are used as illustrative hospitality imagery. Pexels permits free commercial use under its Pexels License, without mandatory attribution, subject to prohibited-use restrictions and third-party rights. Attribution is included here as a courtesy and provenance record.

| Use | Photographer | Pexels source | CDN asset |
|---|---|---|---|
| Homepage hero — hotel lounge | Quang Nguyen Vinh | https://www.pexels.com/photo/interior-of-a-modern-room-in-a-luxury-hotel-26729545/ | https://images.pexels.com/photos/26729545/pexels-photo-26729545.jpeg |
| Homepage gallery — tropical resort | Quang Nguyen Vinh | https://www.pexels.com/photo/resort-by-sea-12389384/ | https://images.pexels.com/photos/12389384/pexels-photo-12389384.jpeg |
| Homepage gallery — infinity pool | Rachel Claire | https://www.pexels.com/photo/view-of-a-sea-from-an-infinity-pool-6774097/ | https://images.pexels.com/photos/6774097/pexels-photo-6774097.jpeg |
| Homepage gallery — fine dining interior | Moussa Idrissi | https://www.pexels.com/photo/luxury-fine-dining-restaurant-9143471/ | https://images.pexels.com/photos/9143471/pexels-photo-9143471.jpeg |
| Generic destination fallback — city skyline | Nguyễn Thanh Tùng | https://www.pexels.com/photo/city-skyline-across-body-of-water-during-night-time-9164056/ | https://images.pexels.com/photos/9164056/pexels-photo-9164056.jpeg |

## License and legal references

- Pexels License: https://www.pexels.com/legal-pages/license
- Pexels commercial-use guidance: https://help.pexels.com/hc/en-us/articles/360042295214-Can-I-use-the-photos-and-videos-for-a-commercial-project
- Pexels usage restrictions: https://help.pexels.com/hc/en-us/articles/360042332714-What-are-the-rules-for-using-Pexels-photos-or-videos
- Pexels terms: https://www.pexels.com/terms-of-service/

A stock-site license grants rights in the photograph under that site's terms; it does not automatically clear every trademark, privacy, publicity, property, moral or other third-party right that may appear in an image. Re-check the license and the individual asset before material changes to use or context. Retain a copy of the source page and license review with each deployment's asset register.

## White-label deployment review

Each white-label deployment must review image relevance, brand fit, local advertising rules and any market-specific restrictions before launch. A platform-wide asset register is a provenance aid, not a substitute for the deploying operator's own legal review. Operators may replace stock imagery through their deployment branding and destination administration; retain their own proof of rights for every replacement.

## Operational recommendations

1. Keep the stock image registry centralized in config/stock_photography.php.
2. Prefer local optimized WebP/AVIF copies for production, with source attribution and license evidence retained. External Pexels CDN URLs are currently used as a lightweight starter and depend on third-party availability.
3. Replace generic destination fallbacks with real, licensed location photography as operators expand into new markets.
4. Do not place stock images in companion profile cards or detail pages.
5. Require a documented rights declaration for operator-uploaded destination images. Add EXIF removal, image moderation, retention and takedown workflows before accepting companion media.
6. Review all third-party media before each commercial white-label deployment. The client must not assume the stock license grants exclusivity or ownership of the image.
