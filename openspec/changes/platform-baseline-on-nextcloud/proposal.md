---
kind: code
---

# Proposal: platform-baseline-on-nextcloud

## Summary

Write down what decidiq inherits from the Nextcloud it runs on, as testable requirements. Sign-in, single sign-on, the second factor, users and groups, hosting and data residency all come from that Nextcloud instance and its OpenRegister. Tenders ask for each of these by name, and until now no spec said where decidiq stands on them.

The same spec carries four tender and platform rows that were parked outside the matrix: ISO 27001 hosting, room audio and video, a public register of ancillary positions, and use on a phone. The first is a property of the instance, like residency. The other three are partly built or not built, and the tasks below plan them.

## Motivation

The decidiq matrix (`openspec/parity/capabilities.json`) had five built rows with `spec: none` (plt-01, plt-06, plt-21, plt-28, plt-32) and four pending rows (pen-01, pen-02, pen-03, plt-05) that no requirement described. A build lane cannot plan against a row without a requirement, and a tender answer cannot cite one.

## Scope

### In scope

1. decidiq keeps no user store and no login of its own. Sign-in, SSO, the second factor and user and group management are the Nextcloud instance's.
2. decidiq names the per-body groups it creates, and an administrator manages every other group in Nextcloud.
3. decidiq runs on any self-hosted Nextcloud with OpenRegister, and keeps its data only in that instance, so residency and the hosting certificate follow where the instance runs.
4. The meeting tells the room's audio and video system who is speaking, through an integriq connection, so the cameras and the recording follow the speaker.
5. A member's published ancillary positions are readable without an account on the public portal.
6. Every decidiq page works at phone width.

### Out of scope

- An identity provider, a two-factor provider or user management inside decidiq.
- A hosting contract or an ISO 27001 certificate: those belong to whoever runs the instance.
- Vendor-specific audio and video drivers (Jacott, ARK and others). integriq owns the connection; decidiq sends speaker events in one shape.

## Matrix rows

`built.change` names this change for: plt-01, plt-06, plt-21, plt-28, plt-32, pen-01, pen-02, pen-03, plt-05.
