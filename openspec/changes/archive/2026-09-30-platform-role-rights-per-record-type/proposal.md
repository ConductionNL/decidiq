---
kind: code
depends_on: []
---

# Proposal: platform-role-rights-per-record-type

## Summary

Per record type read and write rules exist as register declarations that OpenRegister enforces, but an administrator cannot see or change them per role. This change adds an admin page that shows, per record type, which groups may read and change it, and lets the administrator assign groups to the roles those rules name.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json` (comparedOn 2026-09-26). Each row is `building`: part of it works today. This change builds the missing half; the row stays `building` with `built.change` naming this change until it is built.

### plt-03, limit what each role may see and change per kind of record

Own rating `partial`, built.state `building`, owner `ConductionNL/decidiq`. Demand origin `code`, no originUrl.

Matrix evidence, verbatim:

> lib/Settings/decidesk_register.json:61 register-level authorization baseline (read/list authenticated+public, update/delete decidiq-administrators) plus 36 schema-level authorization blocks, all enforced by OpenRegister; decidiq's own guards on action endpoints: lib/Service/MeetingRoleGate.php (used lib/Controller/MeetingController.php:91), GovernanceScopeGuard.php, VoteCastGuard.php, PublicationStaffGuard.php, ParticipationStaffGuard.php, MinutesAccessGuard.php

Matrix note, verbatim:

> decidiq's action endpoints check chair/secretary/signatory and staff roles in code. The per-record-type read/write rules are declarations OpenRegister has to enforce, and an administrator cannot change them per role from a screen.

Competitor cells rated `yes`, verbatim:

- ibabs: https://support.ibabs.com/docs/overview-of-the-settings-for-an-agenda-type.md and https://support.ibabs.com/docs/inrichting-7.md state read, edit and confidential rights per agendatype and per overview, via users or groups
- go-raadsinformatie: https://www.gemeenteoplossingen.nl/blogs/nis_2_en_de_nieuwste_ontwikkeling_rond_cybersecuritywetgeving/ states the products have a rights and roles system deciding which information someone may see and which actions they may take, including closed papers and shielded agenda items
- diligent-boards: https://help.diligentoneplatform.com/helpdocs/boards/en-us/Content/boards-web-admin/user-management/assigning-tasks-to-users-bwa.htm assigns tasks such as approve, print and export per committee, and https://help.diligentoneplatform.com/helpdocs/boards/en-us/Content/boards-web-admin/books/managing-document-visibility-bwa.htm limits documents per user
- openslides: source read at 4.3.4, not driven: openslides-backend/meta/collections/group.yml:16 per meeting groups carry permissions per record type (agenda_item, assignment, motion, mediafile, poll, user and more, each can_see and can_manage), plus meeting_mediafile.yml:32 file access groups and motion_comment_section.yml:32 read and write groups; managed at participants/groups.

## Why

Rated yes by four competitors.

## What is built today

- Register-level baseline and 36 schema-level authorization blocks enforced by OpenRegister; decidiq role guards on action endpoints.

## What changes

1. An admin settings section Rights per record type listing each schema with its read, create, update and delete rule in plain words.
2. The rules name decidiq role groups (administrators, griffie, members, public); the page maps those roles to Nextcloud groups, stored in app config.
3. A change of mapping takes effect without editing the register JSON.

## Out of scope

- Per-object sharing.
