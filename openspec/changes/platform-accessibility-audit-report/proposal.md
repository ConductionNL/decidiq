---
kind: code
depends_on: []
---

# Proposal: platform-accessibility-audit-report

## Summary

A tender asks for an audit report that shows the public website meets WCAG 2.1
AA. decidiq has no public website of its own: residents reach its data through
portaliq and OpenCatalogi. What decidiq can and should prove is that its own
screens, and the portal pages built from what it contributes, meet WCAG 2.1 AA,
and it should prove it with a report a buyer can read. This change adds an
automated axe-core scan of a structured sample of pages, a manual checklist a
tester fills in, and a generated audit report per release that says per
success criterion what passed, what failed and what was not tested.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json`.

### plt-22, show with an audit report that the public website meets WCAG 2.1 AA

Own rating `no`, built.state `none`, owner `ConductionNL/decidiq`.

Demand row: origin `tender`, originUrl
https://www.tenderned.nl/aankondigingen/overzicht/384605 (TenderNed 384605).

No competitor is rated yes. The cells: notubiz `unknown`, ibabs `partial` (the
Publieksportaal was audited against WCAG 2.2 in June 2025 and links the full
report, https://support.ibabs.com/docs/toegankelijkheid.md),
go-raadsinformatie `unknown`, diligent-boards `unknown`, openslides `unknown`.

The matrix note, verbatim: "There is no audit report showing WCAG 2.1 AA
conformance. The public face of decidiq's publications is OpenCatalogi, whose
accessibility was not checked here."

## Why

A Dutch public body must publish a toegankelijkheidsverklaring for every
website and app it offers, including intranets and extranets, under the
Besluit digitale toegankelijkheid overheid. Its status rests on an audit
against WCAG 2.1 AA. The organisation writes the statement; the supplier has to
hand it the audit. Today decidiq has nothing to hand over.

decidiq also has no evidence of its own. The hydra axe gate reads
`tests/axe/report.json` and skips silently when the file is absent, and decidiq
has no such file, so the axe gate has never run on decidiq.

## What changes

1. A WCAG-EM structured sample of decidiq's pages: one of every page type in
   the manifest, the live meeting page, the voting screens, and the portal
   pages built from decidiq's portaliq contribution when portaliq is
   installed.
2. A Playwright suite that runs axe-core with the WCAG 2.1 A and AA rules on
   every sampled page and writes `tests/axe/report.json`, which ends the hydra
   axe gate's silent skip for decidiq.
3. A manual checklist, one entry per WCAG 2.1 A and AA success criterion that
   axe cannot decide (keyboard, screen reader, zoom, reflow, captions), which a
   tester fills in per release.
4. A generator that combines both into `docs/compliance/wcag-2.1-aa-audit.md`:
   date, version, sample, and per success criterion `pass`, `fail`, `not
   applicable` or `not tested`, with each finding and who owns it.
5. The report attached to every release, and linked from the docs site.

## Out of scope

- The toegankelijkheidsverklaring itself. It is the organisation's, per
  website; the report is what it cites.
- An independent audit. The report says plainly that it is a supplier
  self-evaluation following WCAG-EM; an independent audit report, when one is
  commissioned, is linked from it.
- portaliq's renderer and OpenCatalogi's public pages. Findings in their
  markup are recorded in the report under their owner and not claimed as
  decidiq's to fix.
- Published PDF documents. `document-accessibility-check` checks those.

## Risks

- **An automated scan that reads green is not an audit.** axe decides a part of
  the criteria. The report lists every criterion and marks the ones nobody
  checked as `not tested`, so a green scan cannot pass for a full audit.
- **A stale report.** The report carries the version and date it was made
  for, and the release attaches the report made for that release.
