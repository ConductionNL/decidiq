---
kind: code
depends_on: []
---

# Proposal: motions-stages-and-themes

## Summary

A motion can only be moved through its stages from the Decisions page, withdrawing is API only, and motions cannot be tagged or filtered by theme. This change puts the stage buttons, including withdraw, on the motion page and adds themes with a filter on the motions list.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json` (comparedOn 2026-09-26). Each row is `building`: part of it works today. This change builds the missing half; the row stays `building` with `built.change` naming this change until it is built.

### mot-08, move a motion through its stages, such as submitted, adopted, rejected or withdrawn

Own rating `partial`, built.state `building`, owner `ConductionNL/decidiq`. Demand origin `code`, no originUrl.

Matrix evidence, verbatim:

> src/components/tabs/DecisionLifecycleTab.vue:175/212 GET transitions + POST /api/decisions/{id}/transition (propose|deliberate|openVoting|decide|enact|archive, lib/Lifecycle/DecisionTransitionGuard.php TRANSITIONS); MotionDetail lifecycle field is editable:false and has no transition widget; withdraw exists only in lib/Lifecycle/MotionLifecycleTransitioner.php MOTION_TRANSITIONS behind POST /api/motions/{id}/transition, no frontend caller

Matrix note, verbatim:

> A motion can be walked draft->proposed->deliberating->voting->decided (adopted/rejected) through the generic decision lifecycle, but only from the Decisions page. Withdrawal and the motion-specific transition rules are API only.

Competitor cells rated `yes`, verbatim:

- notubiz: https://amsterdam.raadsinformatie.nl/modules/6/moties_en_amendementen/view filters Uitslag with niet in stemming gebracht, verworpen, ingetrokken, aangenomen, staking van stemmen
- ibabs: https://support.ibabs.com/docs/inrichting-7.md names the choice list status motie/amendement filled by the administrator for the Moties overview
- go-raadsinformatie: https://www.gemeenteoplossingen.nl/producten/papierloos_vergaderen/go._raadsinstrumenten/ states council instruments carry a status, are filterable on status and show the steps a document went through
- openslides: source read at 4.3.4, not driven: openslides-backend/meta/collections/motion_state.yml:9 workflow states with next_state_ids (line 105), restrictions (line 34) and recommendation labels, grouped in motion_workflow.yml; managed at motions/workflows and applied via motion.set_state from the motion view.

### mot-15, tag motions by theme and filter on those tags

Own rating `no`, built.state `building`, owner `ConductionNL/openregister`. Demand origin `competitor`, no originUrl.

Matrix evidence, verbatim:

> Decision schema has no theme/tag property; Motions index (src/manifest.json:853) has no tag column or filter; only src/views/MotionIntegrations.vue:24 mentions tags in the OpenRegister integration sidebar (useRegistry), which this repo cannot show working

Matrix note, verbatim:

> Filtering motions by theme is absent. Tagging itself may exist through OpenRegister's integration sidebar, unverified here.

Competitor cells rated `yes`, verbatim:

- notubiz: https://www.kennisbank.notubiz.nl/handleidingen/zoeken-in-politiek-portaal states list module items are filtered by Beleidsveld and Rubriek; https://www.notubiz.nl/onze-diensten/themadossiers links motions to theme dossiers
- ibabs: https://support.ibabs.com/docs/themapaginas.md links motions to a theme with a Beleidsveld choice list; https://support.ibabs.com/docs/overzichtsvelden-weergave-en-exports-bewerken.md makes fields filterable
- openslides: source read at 4.3.4, not driven: openslides-backend/meta/collections/motion.yml:250 tag_ids to tag.yml, managed at motions/tags, filtered in openslides-client/client/src/app/site/pages/meetings/pages/motions/services/list/motion-list-filter.service/motion-list-filter.service.ts:95; motion_category.yml adds nested categories.

## Why

mot-08 is rated yes by four competitors, mot-15 by three. They share the motion page and list.

## What is built today

- Generic decision lifecycle widget on DecisionDetail; MotionLifecycleTransitioner with withdraw behind POST /api/motions/{id}/transition.

## What changes

1. MotionDetail gets a stage widget calling POST /api/motions/{id}/transition with the transitions allowed for the caller, including Withdraw for the submitter.
2. The Motions index gets a stage column and filter (submitted, adopted, rejected, withdrawn).
3. A Theme configuration list (name) and Decision.themes (references); the motion form picks themes; the index filters on them.

## Out of scope

- Theme pages (publication-theme-pages).
