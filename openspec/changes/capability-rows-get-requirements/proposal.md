# capability-rows-get-requirements

**Status**: planned
**Scope**: decidiq

## Why

Nine capability rows in `openspec/parity/capabilities.json` cite a spec that has requirements, but no requirement whose scenarios say what the row says. A row without a requirement cannot be checked against the code, and three of the nine turned out to be rated built while the code does only part of what the row promises.

## What changes

- Each of the nine rows gets one requirement with a scenario, in the main spec its area already uses.
- Six rows describe behaviour the code already has: the requirement documents it, and the row stays built.
- Three rows are rated built while part of the behaviour is missing: a meeting's proof package leaves out the minutes and the signatures, a body cannot limit the voting methods of its meetings, and an amendment can only target a motion, not a proposal. Those rows move to planned, and the tasks below build the missing part.

## Impact

- Specs: `nextcloud-integration`, `resolution-minutes`, `meeting-management`, `example-set-cards`, `agenda-builder`, `motion-amendment`, `p3-citizen-participation`, `p4-integration`, `email-linking-via-email-leaf`.
- Parity: rows plt-17, min-14, bod-14, bod-15, pla-14, mot-02, par-07, plt-13 and plt-33 record their requirement.
- Code: only for the three planned rows, listed in `tasks.md`.
