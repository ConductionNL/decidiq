---
kind: code
depends_on: []
---

# Proposal: agenda-formalities-hamerstukken

## Summary

The live screen can adopt formalities (hamerstukken) in one go, but nothing lets anyone mark an item as a formality, and the adopted state lands in a property the schema lacks. This change adds a formality flag on agenda items, shows it on the agenda, and records adoption on a declared field.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json` (comparedOn 2026-09-26). Each row is `building`: part of it works today. This change builds the missing half; the row stays `building` with `built.change` naming this change until it is built.

### age-07, mark items as formalities and adopt them together without debate

Own rating `no`, built.state `building`, owner `ConductionNL/decidiq`. Demand origin `code`, no originUrl.

Matrix evidence, verbatim:

> src/views/LiveMeeting.vue:397-398 treats items tagged 'hamerstuk' as consent items, :514-516 POST /api/agendas/{id}/hamerstukken -> lib/Service/AgendaService.php:296 processHamerstukken() patches 'status'='completed'; nothing in src/ adds the 'hamerstuk' tag (only removal at LiveMeeting.vue:540-542); neither 'tags' nor 'status' is declared on AgendaItem (lib/Settings/decidesk_register.json:1255 and register.d/70)

Matrix note, verbatim:

> The live screen can adopt consent items in one go, but no screen lets anyone mark an item as a formality, and the adopted state is written to a property the agenda-item schema does not declare.

Competitor cells rated `yes`, verbatim:

- notubiz: https://www.notubiz.nl/onze-diensten/bestuurlijke-besluitvorming states college members mark proposals on an agenda as hamerstuk or bespreekstuk so the agenda is handled efficiently
- ibabs: https://support.ibabs.com/docs/hamer-bespreekstukken.md states invitees indicate before publication whether an item is a hamer- or bespreekstuk, with counts shown to the agenda manager
- openslides: source read at 4.3.4, not driven: openslides-client/client/src/app/site/pages/meetings/pages/motions/pages/motion-blocks/components/motion-block-detail/motion-block-detail.component.html:50 a motion block groups motions under one agenda item (motion_block.yml:27) and Follow recommendations for all motions sets them all at once, routed at motions/blocks.

## Why

Rated yes by three competitors. The adopt-together button exists and can never find an item.

## What is built today

- LiveMeeting treats items tagged hamerstuk as consent items; POST /api/agendas/{id}/hamerstukken patches status completed.

## What changes

1. AgendaItem.isFormality (boolean) declared, editable on the item form and toggled per row on the meeting Agenda widget by chair or secretary.
2. The live screen reads isFormality instead of a tag.
3. processHamerstukken() writes a declared outcome (adopted without debate) and adoptedAt.
4. A member can ask to discuss a formality before the meeting, which clears the flag with a note.

## Out of scope

- Counting per-member requests as iBabs does.
