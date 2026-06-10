# Proposal: Migrate in-app comments to the Talk integration leaf

## Why

The archived `p4-collaboration` change shipped an in-app `CommentService` (10 methods) that stores threaded discussion comments on governance artifacts (agenda items, motions, amendments, decisions) using a polymorphic `{register}:{schema}:{uuid}` target reference and a local `Comment` schema.

This duplicates an OpenRegister abstraction. ADR-022 lists "duplicate sidebar tab systems" and "app-local linked notes/discussions that mirror an OR integration" as review-blocking anti-patterns. ADR-019's integration registry already exposes a **talk** leaf — a Nextcloud Talk conversation bound to an OR object, surfaced as a sidebar tab and a widget, with parity across every Conduction app. Decidesk is already a registry consumer (`MeetingIntegrations.vue` wires the xWiki leaf live), so the wiring path exists.

Keeping a parallel comment store means decidesk discussions never benefit from Talk's mentions, reactions, read-state, attachments, federation, and AI-summary roadmap; it also fragments cross-app "discussion on this object" semantics.

## What Changes

- **Retire `CommentService` and the local `Comment` schema** as the discussion mechanism. Discussion on a meeting or motion detail page moves to the **talk** integration leaf (one Talk conversation per governance artifact, bound via the registry's object link).
- **Surface the talk leaf** on the meeting and motion detail pages through the existing `MeetingIntegrations.vue` registry-driven tab/widget shell, the same way the xWiki leaf is surfaced today.
- **Migration path** for any existing in-app `Comment` objects: a one-shot import that seeds a Talk conversation per target and posts each comment as a Talk message preserving author + timestamp, then marks the legacy objects archived (not hard-deleted — audit trail).
- **Remove** the comment-specific endpoints/controllers added by p4-collaboration once the leaf is the discussion surface.

## Capabilities

### New Capabilities

- `discussion-via-talk-leaf`: Threaded discussion on a governance artifact is provided by a Nextcloud Talk conversation bound to the OR object via the ADR-019 integration registry and surfaced as a registry tab + widget on the meeting/motion detail page.

### Removed Capabilities

- `discussion-and-comments` (the p4-collaboration in-app `CommentService` capability) — superseded by `discussion-via-talk-leaf`.

## Impact

- **Services retired:** `CommentService`.
- **Schema retired:** local `Comment` schema (objects archived, kept for audit, not purged).
- **Frontend:** discussion tab on meeting/motion detail switches from the in-app comment component to the registry-driven talk leaf in `MeetingIntegrations.vue`.
- **Dependency:** Nextcloud Talk app; OpenRegister integration registry (ADR-019), already a decidesk dependency.
- **Out of scope / kept in-app:** none — discussion has no statutory constraint, so the full feature migrates.
