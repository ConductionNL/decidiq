# Tasks — Beta surface alignment (Decidiq)

- [x] 1. Read `appinfo/info.xml`, `src/manifest.json` nav/menu, and skim
      `lib/Controller/` + `lib/Mcp/` to derive the real shipped feature list.
- [x] 2. Verify eIDAS/QES signing is real (not a stub) — read
      `lib/Service/EIDASSignatureService.php`, `lib/Lifecycle/QesGuard.php`,
      `lib/Controller/EIDASSignatureController.php`.
- [x] 3. Verify proxy voting is real — `lib/Controller/ProxyVoteController.php`.
- [x] 4. Verify governance reports / regulator export is real (not a stub) —
      `lib/Controller/GovernanceReportController.php` + `GovernanceReportingService.php`,
      `lib/Controller/RegulatorExportController.php` + `RegulatorExportService.php`.
- [x] 5. Verify OpenCatalogi publication + Docudesk decision-generation
      integrations are real — `OpenCatalogiPublisher.php`, `PublicationController.php`,
      `DecisionIntegrationService.php`.
- [x] 6. Verify the 5 MCP tools advertised on the product page match
      `lib/Mcp/DecidiqToolProvider.php` (id, description, behaviour).
- [x] 7. Confirm `info.xml` Dutch summary/description are genuine Dutch (ADR-007) — yes, no change.
- [x] 8. Rewrite `info.xml` EN + NL "Key Features" bullets to the canonical
      feature vocabulary (was: tech-stack bullets).
- [x] 9. Add OpenRegister dependency (+ optional OpenConnector/Docudesk/
      OpenCatalogi) as an `info.xsd`-safe comment in `<dependencies>`,
      matching the `procest` convention.
- [x] 10. Reconcile `conduction-website/src/pages/apps/decidesk.mdx` (EN):
      version string, tagline/intro, feature bullets, CTA banner.
- [x] 11. Reconcile the NL translation at
      `conduction-website/i18n/nl/docusaurus-plugin-content-pages/apps/decidesk.mdx`:
      same content changes + fix wrong docs URL
      (`docs.conduction.nl/decidesk` → `decidesk.conduction.nl`).
- [x] 12. Check `img/app.svg` against the brand icon convention (white fill,
      24×24) — compliant, no change.
- [x] 13. Note the 3 dead Showcase CTA links in `decidesk.mdx`
      (`/calendar-contacts-notes`, `/deck`, `/mail-files`) that point to
      docs pages which don't exist yet — documented as a follow-up decision,
      not fabricated.
- [x] 14. Write this openspec change (proposal + tasks + spec delta).
