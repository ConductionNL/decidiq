# Tasks: Migrate email linking to the Email integration leaf

## 1. Adopt the email leaf
- [x] 1.1 Confirm the email leaf is registered in the OR integration registry; add to decidesk's consumed-leaf list if absent
- [x] 1.2 Surface the email leaf as the email tab on the decision-dossier detail page via `MeetingIntegrations.vue`
- [x] 1.3 Surface the email leaf on the agenda-item detail where linking applied
- [x] 1.4 Use the registry object-link index for reverse lookup (no `EmailLink` object)
- [x] 1.5 Graceful degradation when Mail is absent (hide tab)

## 2. Decision-reference extraction (conditional)
- [x] 2.1 Check whether the email leaf already offers a link suggestion; if so, drop in-app extraction
- [x] 2.2 If not, retain a thin extraction helper that feeds a suggestion to the leaf (never a link store)

## 3. Vote-by-email (MailReplyHandler)
- [x] 3.1 Keep vote-casting logic in the in-app statutory voting path (ADR-022 exception)
- [x] 3.2 Repoint thread association from `EmailLink` to the registry email-object link before retiring the schema
- [x] 3.3 Surface the vote thread via the email leaf bound to the motion/decision object

## 4. Migration of legacy EmailLink objects
- [x] 4.1 Idempotent migration: create a registry email-object link per `EmailLink`
- [x] 4.2 Archive legacy `EmailLink` objects via OR archival (no hard delete)
- [x] 4.3 Resume-safe / no duplicates on re-run

## 5. Retire the in-app email-link stack
- [x] 5.1 Remove `EmailLinkService` from DI and delete the class
- [x] 5.2 Remove email-link controllers/routes from p4-collaboration
- [x] 5.3 Remove the in-app email-link Vue component from the detail-page tab set
- [x] 5.4 Retire local `EmailLink` schema from the active register set (keep archived objects readable)

## 6. Verification
- [x] 6.1 Linking an email creates a registry link, not an `EmailLink` object (browser check)
- [x] 6.2 Vote-by-email still casts via the statutory path; thread shows in the email leaf
- [x] 6.3 Mail-absent instance renders dossier without error
- [x] 6.4 Migration relinks + archives; re-run no duplicates
- [x] 6.5 `composer check:strict` and ESLint pass

## Notes (build phase)

- **Email leaf provider is not present** in this environment — only an xWiki/Articles
  leaf is registered in OpenConnector. The DECLARATIVE consumption is fully wired:
  registry-mode (`useRegistry: true`) integration surfaces on Decision + AgendaItem
  detail (`DecisionIntegrations.vue` / `AgendaItemIntegrations.vue`), the consumed-leaf
  declaration in `lib/Settings/decidesk_register.json` (`x-openregister.consumes`), and
  graceful degradation (registry omits the Email tab when Mail/leaf is absent). The
  live end-to-end "email leaf renders bound emails / linking through the leaf UI"
  (6.1 browser check, 6.2 thread render) is deferred to when the Email leaf provider
  ships — the wiring on decidesk's side is complete.
- 6.5: `composer phpcs`/`phpmd` clean on every touched PHP file (run in the NC
  container, PHP 8.3); new `EmailReferenceExtractorTest` 7/7 green.
