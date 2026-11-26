---
kind: code
depends_on: []
---

# Proposal: agenda-item-confidential-papers

## Summary

Make "Openbaar: Nee" on an agenda item mean something. When an item is not public, its papers can only be read by the people the meeting's body authorises: its members, the griffie and anyone the griffier adds by name. The rule is an OpenRegister read rule on the item and on its papers, so every surface that reads them (item page, meeting pack, search, API, the public site) refuses alike.

## The row this covers

Source: `openspec/parity/capabilities.json`.

- **age-09** Mark an agenda item as confidential so only authorised people see its papers (building, partial).

The row's note says what is missing: "A confidentiality restriction on an agenda item can be recorded with its legal ground and ratification status. Nothing enforces it: no code hides the item's papers from unauthorised users, and the ratification reminder is a register declaration only." Its evidence adds that the `AgendaItem.confidentiality` classifier that `embargo-geheimhouding` refers to is not declared on AgendaItem.

## Why

Every compared system has this: NotuBiz marks a confidential item with a lock that only users with read rights can open, iBabs shows confidential items only to authorised users, OpenSlides limits internal items and each file to access groups. A griffie that records a geheimhouding in decidiq today still has to keep the papers out of reach by hand.

`embargo-geheimhouding` and `confidentiality-in-plain-words` own the legal side: the restriction, its ground, ratification and lifting. This change owns the access side for one agenda item. It reads their `ConfidentialityRestriction`; it does not change that lifecycle.

## What changes

1. AgendaItem gets `public` (boolean, default true), shown as "Openbaar: Ja/Nee" in Kerngegevens on the item page (board DcAgendapunt). DigitalDocument gets the same field, shown as the "Openbaar" badge on each paper in the Stukken list. A paper of a non-public item is not public, whatever its own field says.
2. Imposing a `ConfidentialityRestriction` with scope item sets the item's `public` to false. Lifting it does not set it back: the griffier decides that, on the item page.
3. OpenRegister read rules on AgendaItem and DigitalDocument: a non-public object is readable by the secretariat, the administrators, the members of the meeting's body and the users in the item's `authorisedReaders`. Everyone else gets no object, not an empty one.
4. Papers of a non-public item are left out of the meeting pack folder and the public publication, and the item shows as "Besloten punt" without title text to people who may not read it.
5. Every read of a paper of a non-public item is logged with who and when (the view audit `embargo-geheimhouding` REQ-EMB-007 asks for), readable by the griffie on the item's history.

## Out of scope

- The geheimhouding lifecycle, its grounds and ratification (`embargo-geheimhouding`, `confidentiality-in-plain-words`).
- Signing in with DigiD or eHerkenning to open papers (plt-02, portaliq).
- Watermarking papers (age-19, `agenda-paper-watermark`).
- Files a member copied into their own Files before the item was closed.
