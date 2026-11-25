# Native PHP reference-data provenance and notices

Snapshot version: 2026-11-25.1

## Country reference snapshot

countries.json contains 249 current ISO 3166-1 country/territory entries.

- Alpha-2, alpha-3 and English display names are sourced from Annexare Countries (https://github.com/annexare/Countries), commit f7857a825bb08e45604e3761e506cc8c0a93f4d2, under its MIT license.
- Numeric-3 code elements are taken only from the ISO3166-1-numeric field of datasets/country-codes (https://github.com/datasets/country-codes), commit 2d84034a0cae19ea5444b78c2131304a5c7f4bb9. That repository declares ODC-PDDL-1.0 for its maintainers' selection/arrangement and separately identifies source-specific terms. This snapshot does not copy its UNTERM, GeoNames, CLDR or other third-party columns.
- ISO explains that ISO 3166-1 includes alpha-2, alpha-3 and numeric-3 code forms and that numeric code elements are assigned by the UN. See https://www.iso.org/iso-3166-country-codes.html.

## Currency reference snapshot

currencies.json contains 155 current non-fund ISO 4217 currency codes with numeric codes, names, symbols and minor-unit precision. Fund, test, unknown and withdrawn codes are excluded.

- Alphabetic/numeric codes, currency names and minor-unit exponents are sourced from SIX Interbank Clearing Ltd (ISO 4217 Maintenance Agency), List One published 2026-09-17, via unitsml/iso4217 commit 397e056924921e48e7d9cab56b4bbad1158adf5d. Only entries marked fund=false are included.
- Display symbols are sourced from Annexare Countries (https://github.com/annexare/Countries), commit f7857a825bb08e45604e3761e506cc8c0a93f4d2, MIT license.
- The unitsml/iso4217 repository includes the CalConnect MIT license for its work and a NIST public-domain notice for NIST contributions. See https://github.com/unitsml/iso4217/blob/main/LICENSE.md.
- ISO describes ISO 4217 alphabetic/numeric codes and minor-unit relationships at https://www.iso.org/iso-4217-currency-codes.html.

## MIT notice — Annexare Countries

Copyright (c) 2014 Annexare Studio

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.

## Operational safeguards

- Importing reference data does not enable a country market for operation.
- countries.enabled and currencies.enabled are reference/configuration switches only. A separate current, evidence-backed, MFA-reviewed market_policies approval is required before a destination can be published or booked.
- Dataset updates preserve administrator enable/disable decisions. Withdrawn currency codes are not imported as active transaction currencies.
- Review this provenance and dataset version when refreshing either source.

## CalConnect MIT notice — unitsml/iso4217

Copyright 2020-2025, CalConnect

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.

## NIST public-domain notice

The unitsml/iso4217 repository includes a NIST Public Domain Notice for contributions by NIST employees. This notice is retained here as provenance; it does not change the source terms of the SIX ISO 4217 List One data.
