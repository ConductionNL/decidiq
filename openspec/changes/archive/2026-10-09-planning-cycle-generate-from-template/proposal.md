---
kind: code
depends_on: []
---

# Proposal: planning-cycle-generate-from-template

## Summary

A yearly planning and control cycle and its steps can be recorded by hand, but a cycle made from a template stays empty. This change generates a year's steps from the chosen template (dates resolved within the year), and shows the steps of a cycle in order with their status, deadline and overdue marker on the cycle page.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json` (comparedOn 2026-09-26). Each row is `building`: part of it works today. This change builds the missing half; the row stays `building` with `built.change` naming this change until it is built.

### pla-12, plan the yearly planning and control cycle, such as budget, spring memo and annual accounts, as a sequence of steps

Own rating `partial`, built.state `building`, owner `ConductionNL/decidiq`. Demand origin `code`, no originUrl.

Matrix evidence, verbatim:

> lib/Settings/register.d/82-planning-cycle-in-plain-words.json PlanningCycle, PlanningCycleTemplate (steps), PlanningCycleStep (sequence, stepType, deliveryDeadline, committeeDate, agendaItem, decision, status); src/manifest.d/pc-cyclus.json PlanningCycles/PlanningCycleDetail/PlanningCycleStepDetail; no service generates steps from a template (grep 'planning-cycle', 'PlanningCycle' in lib/Service, lib/Listener, src/components, src/views: none)

Matrix note, verbatim:

> A yearly cycle and its steps (budget, spring memo, annual accounts) can be recorded and tracked on generic pages. Generating a year's steps from a template is not built, and step/overdue counts rest on register calculations.

No competitor cell is rated `yes`.

## Why

pla-12 sits in the core area (planning). The template carries the steps; nothing turns it into a year.

## What is built today

- PlanningCycle, PlanningCycleTemplate (steps), PlanningCycleStep schemas (register.d/82).
- Generic pages from src/manifest.d/pc-cyclus.json.

## What changes

1. A listener on creation of a planning cycle with a template creates one PlanningCycleStep per template step, sequence kept, deadlines resolved to dates in the cycle year.
2. A Steps widget on PlanningCycleDetail lists the steps in order with deadline, committee date, status and an Overdue badge.

## Out of scope

- Linking a step to an agenda item automatically.
