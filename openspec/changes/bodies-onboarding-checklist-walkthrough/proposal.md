---
kind: code
depends_on: []
---

# Proposal: bodies-onboarding-checklist-walkthrough

## Summary

An onboarding or offboarding record holds a checklist, but nobody can tick a step off and nothing happens when one is done. This change lets the secretary complete or skip each step on the record's page, runs the two steps decidiq can run itself (create the membership when a member is installed, end-date it when a member leaves), and moves the record to completed when every step is done or skipped.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json` (comparedOn 2026-09-26). Each row is `building`: part of it works today. This change builds the missing half; the row stays `building` with `built.change` naming this change until it is built.

### bod-08, walk a new member through an onboarding checklist, and a leaving member through offboarding

Own rating `partial`, built.state `building`, owner `ConductionNL/decidiq`. Demand origin `code`, no originUrl.

Matrix evidence, verbatim:

> lib/Settings/register.d/88-member-onboarding-in-plain-words.json MemberOnboarding/MemberOffboarding with steps[] checklist and lifecycle; src/manifest.d/member-onboarding.json MemberOnboarding/MemberOffboarding index+detail (generic data widget); no PHP service executes steps (grep 'onboarding' in lib/Service, lib/Listener: none); completion guard and created-notification only as x-openregister-lifecycle/x-openregister-notifications declarations

Matrix note, verbatim:

> A secretary can keep an onboarding or offboarding record with a checklist as data and move its status. Nothing walks the person through: steps (account link, group assignment, end-dating the membership) are not executed by decidiq, and the completion guard is only declared.

No competitor cell is rated `yes`.

## Why

bod-08 sits in the core area (bodies). The data model and the pages exist; the walk through the checklist does not.

## What is built today

- MemberOnboarding and MemberOffboarding schemas with steps[] and lifecycle (register.d/88).
- Index and detail pages from src/manifest.d/member-onboarding.json that show the record and its steps.

## What changes

1. A Checklist widget on both detail pages lists the steps with status, and offers Mark as done and Skip per open step to the secretary, chair or admin.
2. Completing the installation step of an onboarding creates the Membership (person, targetBody, targetRole, startDate installedOn) and links it; completing the end step of an offboarding sets the membership endDate.
3. When no step is open, the record moves to completed; the first completed step moves it from started to in-execution.

## Out of scope

- Account linking and group assignment in Nextcloud (steps stay manual, ticked by hand).
- Bulk intake after a council turnover.
