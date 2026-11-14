# capability-rows-get-requirements tasks

## 1. Requirements for the rows

- [x] 1.1 Write a requirement with a scenario for rows plt-17, min-14, bod-14, bod-15, pla-14, mot-02, par-07, plt-13 and plt-33.
- [x] 1.2 Record each requirement in the row's `built.requirement` in `openspec/parity/capabilities.json`.
- [x] 1.3 Move min-14, bod-14 and mot-02 from built to planned, with what is missing in the evidence.

## 2. The proof package holds the minutes and signatures (min-14)

- **spec_ref**: `openspec/changes/capability-rows-get-requirements/specs/resolution-minutes/spec.md#requirement-a-meetings-evidence-is-bundled-into-one-proof-package`
- **files**: `lib/Service/ProofPackageService.php`, `tests/Unit/Service/ProofPackageServiceTest.php`
- [x] 2.1 Add the approved minutes, the attendance list and the signatures to `assemble()`, inside the hashed package.
- [x] 2.2 Name the minutes' state in the package when they are not approved.
- [x] 2.3 Unit tests for both scenarios.

## 3. A body limits its voting methods (bod-14)

- **spec_ref**: `openspec/changes/capability-rows-get-requirements/specs/meeting-management/spec.md#requirement-a-body-limits-which-voting-methods-its-meetings-may-use`
- **files**: `lib/Settings/decidesk_register.json` (GovernanceBody `allowedVotingMethods`), `lib/Service/VotingRoundPreflight.php`, `src/components/VotingRoundPanel.vue`, `tests/Unit/Service/VotingRoundPreflightTest.php`
- [x] 3.1 Add the allowed methods to the body schema, empty meaning all.
- [x] 3.2 Refuse a round whose method the body does not allow, with a message naming the allowed ones.
- [ ] 3.3 Offer only the allowed methods in the voting round panel.
- [x] 3.4 Unit tests.

## 4. Amendments on proposals (mot-02)

- **spec_ref**: `openspec/changes/capability-rows-get-requirements/specs/motion-amendment/spec.md#requirement-an-amendment-can-target-a-proposal-as-well-as-a-motion`
- **files**: `src/components/tabs/MotionAmendmentsTab.vue`, `src/manifest.json` (DecisionDetail amendments widget)
- [ ] 4.1 Let the amendments widget key on any decision, not only a motion.
- [ ] 4.2 Place the widget on the proposal's detail page.
- [ ] 4.3 Test the proposal case.

## Verification

- `openspec validate capability-rows-get-requirements --strict`.
- For tasks 2 to 4: `composer check:strict` and `npm run lint` once before push.
