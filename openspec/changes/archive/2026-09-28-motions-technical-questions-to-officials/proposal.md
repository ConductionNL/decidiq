---
kind: code
depends_on: []
---

# Proposal: motions-technical-questions-to-officials

## Summary

A technical question can be put on the agenda as an item with fields for question and answer, but nothing assigns it to an official or watches the answer deadline. This change assigns each technical question to an official, sets an answer deadline, notifies the official, and shows open and overdue questions.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json` (comparedOn 2026-09-26). Each row is `building`: part of it works today. This change builds the missing half; the row stays `building` with `built.change` naming this change until it is built.

### mot-17, let members put technical questions to the civil service ahead of a meeting and track the answers

Own rating `partial`, built.state `building`, owner `ConductionNL/decidiq`. Demand origin `tender`, originUrl https://www.tenderned.nl/aankondigingen/overzicht/384605.

Matrix evidence, verbatim:

> lib/Settings/profiles/municipality.json:800 seeds agenda item type 'Technische vraag' (a question about an information letter) with fields question, answer, answeredBy and answeredOn; lib/Migration/MigrateDocumentsToAgendaItems.php:119-135 moves old technical questions (question, answer, answeredOn) into typeFields under the same keys; the declared fields are inputs on the agenda item form and on AgendaItemDetail (src/components/tabs/AgendaItemTypeFieldsTab.vue, #1393); lib/Settings/register.d/82-planning-cycle-in-plain-words.json:385 technicalQuestionsStart/End window on a planning cycle step; no answer deadline watch and no routing to an official

Matrix note, verbatim:

> A technical question can be put on the agenda as an item under the letter it is about, with fields for the question, the answer, who answered and when, and a planning cycle step can carry a window for questions. Nothing assigns the question to an official or watches an answer deadline.

Competitor cells rated `yes`, verbatim:

- ibabs: https://support.ibabs.com/docs/technische-vragen.md (updated 2025-12-02) states questions are raised from an agenda item, assigned to an official who can only answer, with deadline, notification and status colours

## Why

A tender demand row (TenderNed 384605) asks for it; iBabs has it.

## What is built today

- Agenda item type 'Technische vraag' with question, answer, answeredBy, answeredOn in typeFields (#1393); a planning cycle step window for questions.

## What changes

1. The technical question type gains assignedTo (a person with an account, picked by name) and answerDeadline.
2. Assigning notifies the official with a link; the official can fill only the answer.
3. The meeting page lists its technical questions as open, answered or overdue, with status colours.
4. When answered, the member who asked is notified.

## Out of scope

- Written questions under the rules of order (schriftelijke vragen).
