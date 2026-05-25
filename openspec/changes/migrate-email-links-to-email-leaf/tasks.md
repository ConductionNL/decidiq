# Tasks: Migrate email linking to the Email integration leaf

## 1. Adopt the email leaf
- [ ] 1.1 Confirm the email leaf is registered in the OR integration registry; add to decidesk's consumed-leaf list if absent
- [ ] 1.2 Surface the email leaf as the email tab on the decision-dossier detail page via `MeetingIntegrations.vue`
- [ ] 1.3 Surface the email leaf on the agenda-item detail where linking applied
- [ ] 1.4 Use the registry object-link index for reverse lookup (no `EmailLink` object)
- [ ] 1.5 Graceful degradation when Mail is absent (hide tab)

## 2. Decision-reference extraction (conditional)
- [ ] 2.1 Check whether the email leaf already offers a link suggestion; if so, drop in-app extraction
- [ ] 2.2 If not, retain a thin extraction helper that feeds a suggestion to the leaf (never a link store)

## 3. Vote-by-email (MailReplyHandler)
- [ ] 3.1 Keep vote-casting logic in the in-app statutory voting path (ADR-022 exception)
- [ ] 3.2 Repoint thread association from `EmailLink` to the registry email-object link before retiring the schema
- [ ] 3.3 Surface the vote thread via the email leaf bound to the motion/decision object

## 4. Migration of legacy EmailLink objects
- [ ] 4.1 Idempotent migration: create a registry email-object link per `EmailLink`
- [ ] 4.2 Archive legacy `EmailLink` objects via OR archival (no hard delete)
- [ ] 4.3 Resume-safe / no duplicates on re-run

## 5. Retire the in-app email-link stack
- [ ] 5.1 Remove `EmailLinkService` from DI and delete the class
- [ ] 5.2 Remove email-link controllers/routes from p4-collaboration
- [ ] 5.3 Remove the in-app email-link Vue component from the detail-page tab set
- [ ] 5.4 Retire local `EmailLink` schema from the active register set (keep archived objects readable)

## 6. Verification
- [ ] 6.1 Linking an email creates a registry link, not an `EmailLink` object (browser check)
- [ ] 6.2 Vote-by-email still casts via the statutory path; thread shows in the email leaf
- [ ] 6.3 Mail-absent instance renders dossier without error
- [ ] 6.4 Migration relinks + archives; re-run no duplicates
- [ ] 6.5 `composer check:strict` and ESLint pass
