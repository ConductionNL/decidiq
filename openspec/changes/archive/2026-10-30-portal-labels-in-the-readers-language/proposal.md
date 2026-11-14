---
kind: code
---

# Proposal: portal-labels-in-the-readers-language

## Summary

The resident menu on a Dutch portal shows decidiq's section in English: the heading "DECIDIQ" and the items "My votes", "My notifications", "Council calendar" and the rest. Portaliq shows a contribution's labels exactly as the app sends them, so decidiq has to answer in the reader's language itself. This change runs every visible label of the citizen contribution through decidiq's own catalogue, and names the section after what it is about instead of the product.

## Why

Found in the live test of the Zuiddrecht demo portal on 13 and 14 October 2026: a signed-in resident saw an English DECIDIQ section in an otherwise Dutch menu. A resident does not know the product name, and visitor text on a Dutch portal is Dutch.

## What changes

1. `PortalContributionProvider` takes an optional `IFactory` (the container hands it in; `new` with no arguments still works and answers in English, amendment A1 holds).
2. `PortalLabelTranslator` translates the `label` and `group` strings of the manifest through decidiq's catalogue and leaves ids, schema slugs, field names and filter values alone. Same approach as learniq's translator.
3. The section heading is "Council and participation" ("Raad en inspraak") instead of "Decidiq".
4. `l10n/en.json` and `l10n/nl.json` carry every label; a test fails when a label has no Dutch entry.

## Out of scope

- Other languages than Dutch: the keys are in `en.json`, so translators pick them up.
- The zaakafhandelapp "Mijn Zaken" section: it only appears where zaakafhandelapp is enabled.
