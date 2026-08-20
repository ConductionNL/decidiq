# Beta surface alignment — Decidesk

## Why

Decidesk is a Workspace app nearing beta release. Its code metadata (`appinfo/info.xml`),
code features (`src/manifest.json` nav/menu), the public product page
(`conduction-website/src/pages/apps/decidesk.mdx` + NL translation), and the
Docusaurus docs (`decidesk/docs/`) had drifted apart:

- `info.xml`'s "Key Features" bullets described the tech stack (Vue 2 + Pinia,
  ESLint, PHPStan…) instead of the app's actual governance features.
- The public product page framed Decidesk almost entirely as a municipal
  council tool (Wet open overheid, "council dossiers") and made no mention of
  two of its most substantive shipped capabilities: proxy voting and
  eIDAS-qualified electronic signatures (QES). It also omitted governance
  reports / regulator export.
- The product page version badge (`v0.5`) did not match `info.xml`'s
  `<version>0.3.9</version>`.
- The NL product page linked docs at `https://docs.conduction.nl/decidesk`
  instead of the app's actual docs subdomain `https://decidesk.conduction.nl`.
- `info.xml` had no record of Decidesk's real dependency on OpenRegister (only
  expressible as a comment, per NC's `info.xsd`, following the `procest`
  convention) or its optional runtime dependencies on OpenConnector, Docudesk,
  and OpenCatalogi.
- The Dutch `info.xml` summary/description were checked and are genuine,
  idiomatic Dutch (not a machine-literal copy of the English) — no change
  needed there.

## Canonical feature vocabulary (source of truth: `src/manifest.json` nav +
`lib/Controller/` + `lib/Mcp/DecideskToolProvider.php`)

1. **Meetings & agendas** — scheduling, agenda items, per-item dossiers (OpenRegister objects)
2. **Motions & amendments** — submit, link to an agenda item, track to a decision
3. **Voting, including proxy voting** — in person / electronic / proxy for an absent member
4. **eIDAS-qualified signatures (QES)** — minutes + written-procedure resolutions, Article 25(2)
5. **Governance reports & regulator export** — compliance reporting for supervisory bodies
6. **Action items & decisions** — tracked to completion, tamper-evident audit trail
7. **AI chat companion (MCP)** — 5 stable tools (`decidesk.listOpenActionItems`,
   `decidesk.listRecentMeetings`, `decidesk.getMeetingDetails`,
   `decidesk.startMeeting`, `decidesk.addActionItem`)
8. Serves **5 governance domains**: legislative/democratic bodies,
   associations/NGOs, corporate governance, corporate operations, citizen
   participation (`organisatie_modus` mode-adaptation of universal entities,
   ADR-006 in this app's own openspec)

## Claims verified against `lib/` (HEAD)

| Claim | Verdict | Evidence |
|---|---|---|
| eIDAS / QES signing | **REAL** | `lib/Service/EIDASSignatureService.php` (687 lines) delegates to OpenConnector's `e-sign` Source (`CallService`) or, preferentially, a Docudesk `docudesk-signing` Source; `lib/Lifecycle/QesGuard.php` enforces `qualified=true`; `lib/Controller/EIDASSignatureController.php` exposes initiate/verify/finalize/validate-cert. Docs (`docs/compliance/board-portal-compliance.md`) already document this accurately, including the fail-closed Docudesk path and the AdES-QC rejection rule. |
| Proxy voting | **REAL** | `lib/Controller/ProxyVoteController.php` (register/list/suspend/revoke), `docs/tutorials/user/05-run-vote.md` documents the flow including rejection rules (quorum-eligible present participant, proxy caps). |
| Governance reports / regulator export | **REAL** | `lib/Controller/GovernanceReportController.php` + `lib/Service/GovernanceReportingService.php` (515 lines); `lib/Controller/RegulatorExportController.php` + `lib/Service/RegulatorExportService.php` (722 lines, one `TODO Cycle 2` marker on an AuditTrail integration refinement — the export path itself is implemented, not stubbed). |
| Decision publication via OpenCatalogi | **REAL** | `lib/Service/OpenCatalogiPublisher.php`, `lib/Controller/PublicationController.php`, `lib/Service/PublicationPayloadService.php` / `PublicationEligibilityService.php` / `PublicationConfigService.php`. |
| Decisions from DocuDesk | **REAL** | `lib/Service/DecisionIntegrationService.php`, `lib/Service/MinutesDocumentService.php` reference Docudesk generation. |
| AI chat / MCP tools | **REAL** | `lib/Mcp/DecideskToolProvider.php` implements all 5 tools listed on the product page (`McpToolShelf`), matching IDs and descriptions. |
| OpenRegister dependency | **REAL, now declared** | `src/manifest.json` `"dependencies": ["openregister"]`; every controller/service above resolves `OCA\OpenRegister\Service\ObjectService` via DI. `info.xml` previously omitted this — added as a comment (NC's `info.xsd` has no app-dependency element; matches `procest`'s convention). |

No claim was found to be fabricated or required removal; the previous
under-statement was the primary issue (real shipped features absent from the
public page), not over-claiming.

## Reconciliation (edits made)

1. `decidesk/appinfo/info.xml`
   - Replaced the tech-stack "Key Features" bullets (EN + NL) with the real
     canonical feature list above.
   - Documented the OpenRegister dependency + optional OpenConnector/Docudesk/
     OpenCatalogi runtime dependencies as an `info.xsd`-safe comment.
2. `conduction-website/src/pages/apps/decidesk.mdx` (EN)
   - `version="v0.5"` → `version="v0.3.9"` (matches `info.xml`, labelled Beta).
   - Tagline/intro broadened from "municipal councils, executive boards,
     supervisory and management boards" to explicitly cover proxy voting,
     eIDAS QES, and the 5 governance domains, matching `info.xml`'s summary.
   - Added 2 `FeatureItem`s (proxy voting folded into the existing voting
     item; new eIDAS QES item; decisions item extended to mention governance
     reports / regulator export).
   - `CtaBanner` lede updated to mention proxy voting, eIDAS signing, and
     governance reporting.
3. `conduction-website/i18n/nl/docusaurus-plugin-content-pages/apps/decidesk.mdx` (NL)
   - Same reconciliation, real Dutch (not machine-translated English).
   - Fixed the docs link from the wrong `https://docs.conduction.nl/decidesk`
     to the correct `https://decidesk.conduction.nl` (matches the EN page and
     this app's actual docs deploy subdomain).
4. `decidesk/docs/` — no edits needed. `docs/compliance/board-portal-compliance.md`,
   `docs/Features/board-portal.md`, and the `docs/tutorials/user/05-run-vote.md`
   tutorial already describe eIDAS QES and proxy voting accurately and in
   more depth than the product page did; the product page was the surface
   lagging behind, not the docs.

## Still misaligned / needs a decision

- The product page's `Showcase` integration items link to
  `https://decidesk.conduction.nl/calendar-contacts-notes`,
  `/deck`, and `/mail-files` — none of these doc pages exist in
  `decidesk/docs/` today (closest real content: `docs/Features/board-portal.md`,
  `docs/tutorials/user/*`). Left as-is (out of scope to fabricate new docs
  pages); flagging for a follow-up decision — either write those 3 doc pages
  or repoint the CTAs at existing docs paths.
- `img/app.svg` already satisfies the brand convention (white fill, `viewBox="0 0 24 24"`) — no icon change needed.
