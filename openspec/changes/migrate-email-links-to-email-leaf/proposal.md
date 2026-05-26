# Proposal: Migrate email linking to the Email integration leaf

## Why

The archived `p4-collaboration` / `p2-motion-and-voting` changes shipped two in-app email mechanisms:

- `EmailLinkService` (6 methods) — stores `EmailLink` objects mapping emails (Nextcloud Mail) to governance objects (Decision, AgendaItem), with reverse lookup and decision-reference extraction from email subject/body for auto-suggest linking.
- `MailReplyHandler` (background job) — polls for email replies to voting-notification threads and casts votes from the reply body.

The `EmailLink` mechanism is a textbook ADR-022 anti-pattern: an app-local link table (`{app}_email_links`) that mirrors what the ADR-019 **email** leaf provides — emails bound to an OR object, surfaced as a registry tab + widget. This change moves email-to-dossier linking to the email leaf on the decision-dossier detail page.

`MailReplyHandler` is **not** a simple email-link case: it is a vote-casting path tied to statutory voting. Its handling is split out in design.md (D2) — the email *thread* surfaces via the leaf, but vote casting stays in the in-app voting path (ADR-022 statutory-voting exception).

## What Changes

- **Adopt the email leaf** on the decision-dossier detail page (and agenda-item detail where linking applied), bound via the ADR-019 registry, surfaced through the registry tab/widget shell. Linking an email to a dossier is done through the leaf, not the in-app `EmailLink` object.
- **Retire `EmailLinkService` and the `EmailLink` schema.** Reverse lookup ("which dossier is this email linked to?") is provided by the registry's object-link mechanism. Decision-reference extraction (auto-suggest) is retained only if the email leaf cannot offer the suggestion itself — decided at apply time per ADR-022; if retained it becomes a thin helper feeding the leaf, not a link store.
- **`MailReplyHandler` (vote-by-email):** the email *thread* is surfaced via the leaf, but the vote-casting logic remains in the in-app statutory-voting path. Documented as an ADR-022 exception in design.md.
- **Migrate** existing `EmailLink` objects to registry email-object links; archive the legacy objects (not purged).

## Capabilities

### New Capabilities

- `email-linking-via-email-leaf`: Emails are linked to a decision dossier / agenda item through the ADR-019 email integration leaf bound to the OR object, replacing the in-app `EmailLink` store.

### Removed Capabilities

- `email-integration` (the p4-collaboration in-app `EmailLinkService` capability) — superseded by `email-linking-via-email-leaf`.

## Impact

- **Services retired:** `EmailLinkService`.
- **Schema retired:** local `EmailLink` schema (objects archived for audit, not purged).
- **Background job:** `MailReplyHandler` retained for vote-by-email casting (statutory exception); its thread visibility moves to the leaf.
- **Frontend:** email tab on the decision-dossier detail switches to the registry-driven email leaf.
- **Dependency:** Nextcloud Mail app; OpenRegister integration registry (ADR-019).
- **Out of scope / kept in-app:** statutory voting (incl. `MailReplyHandler` vote casting) and ORI/Popolo publication — see design.md exceptions.
