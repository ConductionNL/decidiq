# Spec delta: Resolution and Minutes — draft initialization from AI-generated content

This file contains delta specifications for the meeting-transcription-ai-minutes change against the existing `resolution-minutes` capability. It adds one entry point into the minute-taking editor; the drafting, review, approval, locking, and document-generation requirements are unchanged.

---

## ADDED Requirements

### Requirement: Minute-taking editor initialization from an AI-generated draft

The minute-taking editor SHALL support initialization from an AI-generated draft produced by the meeting-transcription capability, in addition to the existing metadata pre-population. AI-generated content SHALL be visibly marked in the editor (a draft-provenance banner plus per-section markers), and the secretary SHALL be able to accept, edit, or discard each generated section independently. Accepting, editing, or submitting AI-initialized minutes SHALL follow the existing review and approval workflow without any shortcut: AI provenance SHALL never alter the lifecycle, and approval SHALL always be an explicit human action. The provenance metadata SHALL be retained on the minutes record through approval for audit purposes.

#### Scenario: Editor pre-filled from a generated draft

- **GIVEN** a meeting with an AI-generated draft from its transcript
- **WHEN** the secretary opens the minutes editor and chooses to start from the generated draft
- **THEN** the template is pre-populated with the per-agenda-item generated summaries alongside the existing metadata pre-population, a provenance banner is shown, and each generated section carries a visible AI marker

#### Scenario: Discard a generated section

- **GIVEN** the editor initialized from a generated draft
- **WHEN** the secretary discards the generated section for one agenda item and writes their own text
- **THEN** the discarded content is removed, the replacement section carries no AI marker, and the other generated sections are unaffected

#### Scenario: Approval workflow unchanged for AI-initialized minutes

- **GIVEN** minutes that were initialized from an AI-generated draft and marked ready for review
- **WHEN** the chair approves them
- **THEN** the approval follows the existing workflow (review, correction suggestions, explicit approval with timestamp and approver identity, locking), and the retained provenance metadata records that the draft originated from AI generation

#### Scenario: Provenance retained for audit

@e2e exclude metadata-retention contract — covered by PHPUnit on the minutes record
- **WHEN** AI-initialized minutes reach `approved`
- **THEN** the minutes record still carries the generation provenance (provider id, generated-at, sections accepted as generated vs. rewritten)
