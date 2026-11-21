# Design: publication-theme-pages

Read at decidiq development `c0b2f5bb`.

## What exists

| Piece | Where |
|---|---|
| Publishing | `lib/Service/PublicationService.php:105` `publish(sourceType, sourceId, actorId)`: `PublicationEligibilityService::assertEligible()`, `PublicationPayloadService::build()`, a `publicationDate` that makes the payload anonymously readable, and routing to the body's OpenCatalogi catalog; `:201` `withdraw()`; routes `appinfo/routes.php:49-51` (`POST /api/publications`, withdraw, rectify) |
| Eligibility | `lib/Service/PublicationEligibilityService.php:284` `assertEligible()` switches on `decision`, `agenda`, `minutes` and refuses anything else; `:77` `DENY_TYPES`, `:103` `DENIED_SCHEMAS` keep transcripts and recordings out |
| Payloads | `lib/Service/PublicationPayloadService.php:71` `build()` switches on the same three types (`:108` decision, `:177` agenda, `:225` minutes) |
| Publication record | `lib/Settings/decidesk_register.json` `PublicationRecord.sourceType` enum `decision`, `agenda`, `minutes` |
| Objects a theme gathers | `Meeting`, `AgendaItem`, `Decision` (motions are decisions, ADR-005), `Commitment` (`lib/Settings/register.d/84-commitment-in-plain-words.json`), `PlannedAgendaItem` (`lib/Settings/register.d/86-the-last-two-dutch-names.json`) |
| Nearest staff view | `src/manifest.d/organisation-goals.json:52` `GoalDetail` (`/goals/:id`) |

## Approach

### Schema

Fragment `lib/Settings/register.d/92-theme-pages.json`, `Theme` (slug `governance-theme`, Schema.org `CreativeWork`; the plain slug `theme` is avoided because slugs are global on a shared OpenRegister and thematiq owns theming):

| Property | Type | Notes |
|---|---|---|
| `title` | string | required |
| `introduction` | string (markdown) | required |
| `blocks` | array of `{ heading, body }` | extra text blocks |
| `status` | enum `active`, `concluded` | `x-openregister-lifecycle`: active to concluded and back |
| `governanceBody` | uuid, `$ref` GovernanceBody | the responsible body, used for catalog routing |
| `portfolioHolder` | uuid, `$ref` Person | |
| `meetings`, `agendaItems`, `decisions`, `commitments`, `plannedAgendaItems` | arrays of uuid with `$ref` to their schema | the links |
| `isPublic` | boolean | whether the griffie wants it published |

Read `authenticated`, write `decidiq-secretariat` and administrators. The published payload, not the theme itself, is what residents read.

### Timeline

`lib/Service/ThemeTimelineBuilder.php` turns the links into entries `{ date, kind, title, objectId }`: a meeting's `scheduledDate`, an agenda item's meeting date, a decision's `decisionDate`, a commitment's `deadline`, a planned agenda item's `plannedPeriod`; sorted oldest first. For publication it keeps only entries whose object has an active `PublicationRecord` and adds that publication's link; planned agenda items count when the long-term agenda is publicly readable.

### Publishing

- `PublicationEligibilityService::assertEligible()` gets `case 'theme'`: the theme is `isPublic` and has at least one publishable timeline entry; `schemaForType('theme')` answers `governance-theme`.
- `PublicationPayloadService::build()` gets `buildThemePayload()`: title, introduction, blocks, `timeline` (the published entries only), `governanceBody`, `status`.
- `PublicationRecord.sourceType` gains `theme` in the same fragment.
- `lib/BackgroundJob/ThemeRefreshJob.php`, daily, republishes active public themes whose timeline changed (a new version through `publish()`, which already versions payloads).

### Screens

- `Themes` index and `ThemeDetail` pages in `src/manifest.d/theme-pages.json`: the detail page has the theme's data, a custom `ThemeTimelineTab` (the timeline with every entry for staff, marking which are published), object-lists for each link kind, and a Publish action posting to `POST /api/publications` with `sourceType: theme`.
- `AgendaItemDetail` and `DecisionDetail` get an Add to theme action (a small modal in `src/modals/AddToThemeModal.vue`) that appends the object to a chosen theme's link array.
- Menu: under the publication cluster next to the existing publication pages.

## Declarative or imperative

| Behaviour | Path | Why |
|---|---|---|
| Theme data, links, status, read rules, pages | Declarative schema and manifest | Plain data |
| Timeline | Imperative builder | It reads five schemas and filters on another object's publication state |
| Publishing | Imperative, existing `PublicationService` extended by one type | The publication path is imperative today and owns eligibility |
| Nightly refresh | Imperative scheduled job | ADR-031 exception: scheduled bulk republishing |

## Seed data

Municipality example set: theme "Nieuw zwembad De Hoge Dijk", active, public, responsible body the municipal council, with the meeting of 14 October, the agenda item "Kredietaanvraag zwembad", the adopted motion "Motie duurzaam zwembad", one commitment and one planned agenda item for 2027-Q1, so the timeline shows five entries.

## Sibling half

OpenCatalogi: showing a publication's `timeline` array as a timeline block on its public publication page. Listed in this lane's hand-back; until then the payload's timeline is served as data and OpenCatalogi shows the theme's text.

## Files

- `lib/Settings/register.d/92-theme-pages.json`, `lib/Settings/profiles/municipality.json`
- `lib/Service/ThemeTimelineBuilder.php`, `lib/Service/PublicationEligibilityService.php`, `lib/Service/PublicationPayloadService.php`, `lib/BackgroundJob/ThemeRefreshJob.php`, `appinfo/info.xml`
- `src/manifest.d/theme-pages.json`, `src/components/tabs/ThemeTimelineTab.vue`, `src/modals/AddToThemeModal.vue`, `src/registry.js`, `src/manifest.json` (`AgendaItemDetail`, `DecisionDetail` actions)
- `tests/Unit/Service/ThemeTimelineBuilderTest.php`, `tests/Unit/Service/PublicationPayloadServiceTest.php`, `tests/newman/theme-pages.json`, `tests/e2e/theme-pages.spec.ts`
