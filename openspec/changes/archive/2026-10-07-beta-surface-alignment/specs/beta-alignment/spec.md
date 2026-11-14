# Spec: Beta cross-surface alignment (Decidiq)

## ADDED Requirements

### Requirement: Canonical feature vocabulary is consistent across surfaces
The feature names and claims presented in `decidiq/appinfo/info.xml`, the
`src/manifest.json` navigation/menu, the public product page
(`conduction-website/src/pages/apps/decidesk.mdx` and its NL translation), and
`decidiq/docs/` SHALL use the same canonical feature vocabulary: meetings &
agendas, motions & amendments, voting (including proxy voting), eIDAS-qualified
signatures (QES), governance reports & regulator export, action items &
decisions, and the AI chat companion (MCP).

#### Scenario: info.xml description matches shipped features
- **WHEN** a reader compares `info.xml`'s "Key Features" bullets against
  `lib/Controller/` and `lib/Mcp/DecidiqToolProvider.php`
- **THEN** every bullet corresponds to a feature that is actually implemented,
  not a generic tech-stack description

#### Scenario: Product page mentions proxy voting and eIDAS QES
- **WHEN** a reader views `/apps/decidesk` (EN or NL)
- **THEN** the page names proxy voting and eIDAS-qualified electronic
  signatures explicitly, matching the depth already present in
  `docs/compliance/board-portal-compliance.md` and
  `docs/tutorials/user/05-run-vote.md`

### Requirement: Version string is consistent across surfaces
The version displayed on the product page SHALL match `info.xml`'s
`<version>` element, labelled "Beta" while the app has not reached a 1.0
release.

#### Scenario: Product page version matches info.xml
- **WHEN** `info.xml` declares `<version>0.3.9</version>`
- **THEN** both the EN and NL product pages display `version="v0.3.9"` with
  a "Beta" status badge

### Requirement: Marketing/compliance claims are verified against code
Any standard, regulation, or integration the product page or docs assert
(e.g. eIDAS, QES, regulator export, OpenCatalogi publication) SHALL correspond
to actually-implemented code at the time of a beta release. An unverified
claim is a beta blocker and must be corrected or removed.

#### Scenario: eIDAS/QES claim is backed by real delegation code
- **WHEN** the product page claims eIDAS-qualified signing
- **THEN** `lib/Service/EIDASSignatureService.php` and
  `lib/Lifecycle/QesGuard.php` implement real initiate/verify/finalize/
  validate-cert flows delegating to a configured OpenConnector or Docudesk
  signing Source (not a stub or hardcoded fixture)

### Requirement: App dependencies are declared where the schema allows
Real runtime dependencies on other Conduction apps (as declared in
`src/manifest.json`'s `dependencies` array, or observed via DI container
lookups in `lib/`) SHALL be documented in `info.xml`, using a comment inside
`<dependencies>` when the NC `info.xsd` schema has no dedicated element for
inter-app dependencies (per the `procest` convention).

#### Scenario: OpenRegister dependency is documented in info.xml
- **WHEN** `src/manifest.json` declares `"dependencies": ["openregister"]`
- **THEN** `info.xml`'s `<dependencies>` block contains a comment naming
  OpenRegister as a required data-layer dependency
