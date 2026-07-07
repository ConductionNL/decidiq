# app-foundation — delta: licence metadata matches the repository licence

## ADDED Requirements

### Requirement: App-Store licence metadata matches the repository licence
The `appinfo/info.xml` `<licence>` element SHALL declare the same licence the app is actually
shipped under, expressed as the SPDX identifier `EUPL-1.2`. It SHALL agree with the `LICENSE` file,
`REUSE.toml`, `publiccode.yml`, the `SPDX-License-Identifier` in every `lib/**` file docblock, and
the `<description>` licence sentence. The `<licence>` element SHALL NOT declare a licence
(e.g. `agpl`) that contradicts those sources.

#### Scenario: info.xml licence equals the repository licence
- **GIVEN** the repository ships under EUPL-1.2 (`LICENSE`, `REUSE.toml`, `publiccode.yml`, SPDX
  headers)
- **WHEN** `appinfo/info.xml` is read
- **THEN** its `<licence>` element is `EUPL-1.2`
- **AND** it does not declare `agpl` or any other licence that contradicts the repository.

#### Scenario: description and metadata agree
- **GIVEN** the `info.xml` `<description>` states the app is EUPL-1.2 licensed
- **WHEN** the `<licence>` element is compared to that statement
- **THEN** the two agree (both EUPL-1.2).
