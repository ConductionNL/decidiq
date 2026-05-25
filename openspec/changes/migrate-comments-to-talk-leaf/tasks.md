# Tasks: Migrate in-app comments to the Talk integration leaf

## 1. Register the talk leaf on artifact detail pages
- [ ] 1.1 Confirm the talk leaf is registered in the OR integration registry (ADR-019); add it to decidesk's consumed-leaf list if absent
- [ ] 1.2 Surface the talk leaf as the discussion tab on the meeting detail page via `MeetingIntegrations.vue` (mirror the live xWiki-leaf wiring)
- [ ] 1.3 Surface the talk leaf as the discussion tab on the motion detail page
- [ ] 1.4 Implement graceful degradation when the Talk app is absent (hide tab / "discussion unavailable")

## 2. Migration of legacy Comment objects
- [ ] 2.1 Write an idempotent one-shot migration: for each artifact with `Comment` objects, ensure a bound Talk conversation exists
- [ ] 2.2 Replay each comment as a Talk message preserving author + original timestamp
- [ ] 2.3 Set each replayed `Comment` to an archived state via OR archival workflow (no hard delete)
- [ ] 2.4 Make the migration resume-safe (skip already-archived comments / existing messages)

## 3. Retire the in-app comment stack
- [ ] 3.1 Remove `CommentService` from DI registration and delete the service class
- [ ] 3.2 Remove comment-specific controllers/routes added by p4-collaboration
- [ ] 3.3 Remove the in-app comment Vue component from the detail-page tab set
- [ ] 3.4 Retire the local `Comment` schema from the active register set (keep archived objects readable)

## 4. Verification
- [ ] 4.1 Discussion posts on a meeting create Talk messages, not Comment objects (browser check)
- [ ] 4.2 Talk-absent instance renders detail page without error
- [ ] 4.3 Migration replays comments and archives originals; re-run produces no duplicates
- [ ] 4.4 `composer check:strict` and ESLint pass
