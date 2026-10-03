---
kind: code
depends_on: []
---

# Proposal: signing-external-service-with-order

## Summary

Signers can be chosen for minutes and a backend sends minutes to a signing service through integriq, but no screen starts it, there is no signing order, and decision lists, proposals and motions cannot be sent. The backend also never resolves, because it looks up an integriq class that does not exist. This change fixes the lookup, adds a signing order, and puts Send for signature on minutes, decision lists and motions.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json` (comparedOn 2026-09-26). Each row is `building`: part of it works today. This change builds the missing half; the row stays `building` with `built.change` naming this change until it is built.

### min-17, send decision lists, proposals and motions to an external signing service with chosen signers and order, and store the signed copy back automatically

Own rating `partial`, built.state `building`, owner `ConductionNL/integriq`. Demand origin `tender`, originUrl https://www.tenderned.nl/aankondigingen/overzicht/415883.

Matrix evidence, verbatim:

> src/components/tabs/MinutesSignersTab.vue:25 'Add signer' picks signers on a minutes record, :329 signNow() only posts a lifecycle transition (:333 /api/minutes/{id}/transition); appinfo/routes.php:202-204 /api/minutes/{minutesId}/eidas/{initiate,verify,finalize} -> lib/Service/EIDASSignatureService.php:99 hands the request to an integriq source and :302 stores the archive reference, hash and signers on the minutes row; no src/ caller of 'eidas'; lib/Settings/connections.json:14-19 eidas reportedOnly; no signing order field and no path for decision lists, proposals or motions

Matrix note, verbatim:

> Signers can be chosen for minutes, and a backend exists to send minutes to a signing service through integriq and store the result, but no screen starts it and there is no signing order. Decision lists, proposals and motions cannot be sent for signature.

Competitor cells rated `yes`, verbatim:

- notubiz: https://www.notubiz.nl/nieuws/hoe-richt-je-veilig-en-efficient-een-ondertekenproces-in states a document is offered from NotuBiz to ValidSign with one or more signers and an optional signing order, and returns signed automatically; https://www.notubiz.nl/over-ons/onze-partners names besluitenlijsten

## Why

A tender demand row (TenderNed 415883) asks for it; NotuBiz offers it with ValidSign.

## What is built today

- MinutesSignersTab picks signers; EIDASSignatureService initiate, verify and finalize for minutes behind /api/minutes/{id}/eidas/*.

## What changes

1. EIDASSignatureService resolves integriq's current call API instead of Db\SourceMapper, which does not exist (defect).
2. Signers carry an order; the request sends them in order.
3. A Send for signature action on minutes, decision list (meeting decisions) and motion pages, generalising the service to a subject type and id.
4. When the service reports the signed copy, it is stored back in the subject's folder and linked.

## Out of scope

- The signing provider itself (integriq connection).
