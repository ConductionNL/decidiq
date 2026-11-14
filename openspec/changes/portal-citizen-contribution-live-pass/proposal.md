---
kind: code
---

# Proposal: portal-citizen-contribution-live-pass

## Summary

decidiq declares what a resident without an account may read and create through portaliq: react to an open consultation and propose a budget item, and read back what they submitted. `PortalCreateOpenParentGuardListener` guards those creates. What nobody has shown yet is the whole path: a resident submits on the portal, the reaction lands in decidiq for the griffie to moderate, and Mijn inspraak lists it with its status. This change adds that end-to-end requirement and the run that proves it.

## Motivation

The unit tests pin the declaration (`PortalContributionProviderTest`) and the guard (`PortalCreateOpenParentGuardListenerTest`), and REQ-DKPCA-001 carries an `@e2e exclude` because decidiq has no page of its own to drive. The row par-10 stays at building until the portal half is proven, and that proof needs both apps installed together.

## Scope

### In scope

1. One end-to-end requirement: a contribution submitted on the portal reaches decidiq moderation and is listed back to the resident with its moderation status.
2. A Playwright run with portaliq and decidiq on one instance, as DcMijnInspraak draws the resident's side.
3. Fixes in decidiq that the run finds, if any.

### Out of scope

- The portal pages themselves: they are portaliq's (portal-contribution-contract).
- Signing up to speak at a meeting, which DcMijnInspraak also draws. No decidiq row asks for it yet.

## Matrix rows

- par-10: Let citizens submit a contribution through the public portal.
