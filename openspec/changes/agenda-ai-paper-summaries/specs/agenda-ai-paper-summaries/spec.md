# agenda-ai-paper-summaries Specification

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [agenda-ai-paper-summaries](../../) (this change)

## Purpose

A clerk asks Nextcloud's AI to summarise a meeting paper or to compare two papers, reviews the result, and shows it to members. Closes decidiq matrix rows age-16, age-20 and plt-27.

**Standards**: Schema.org `CreativeWork` (`isBasedOn`), Nextcloud TaskProcessing (`TextToTextSummary`, `TextToText`).

## ADDED Requirements

### Requirement: REQ-APS-001 An AI summary is a record with a review status

The app SHALL store every AI summary or comparison as a `PaperSummary` object carrying its agenda item, paper, kind, text, provider and a `status` of `requested`, `draft`, `shown`, `hidden` or `failed`, with transitions declared in `x-openregister-lifecycle`. Members SHALL read only summaries in `shown`; the secretariat and administrators SHALL read all.

#### Scenario: A member does not see an unchecked summary
- GIVEN a draft summary of the budget paper on the council's agenda item Begroting 2027
- WHEN council member Pieter opens that agenda item
- THEN he sees no summary

#### Scenario: A clerk sees every summary
- GIVEN the same draft summary
- WHEN the griffier opens the agenda item
- THEN she sees the draft with Edit, Show to members and Hide

### Requirement: REQ-APS-002 A clerk asks for a summary or a comparison of a paper

On the agenda item page, a member of the secretariat SHALL be able to ask for a summary of a paper attached to that item, or for the main differences between it and a second paper. The app SHALL schedule a Nextcloud TaskProcessing task and record the request. When no TaskProcessing provider is installed the actions SHALL not be shown and the endpoint SHALL answer 503.

#### Scenario: The griffier asks for a summary
- GIVEN the agenda item Begroting 2027 has the paper Programmabegroting 2027 attached
- WHEN the griffier chooses Summarise on that paper
- THEN a summary with status requested appears in the widget

#### Scenario: Two budget years are compared
- GIVEN the papers Programmabegroting 2027 and Programmabegroting 2026 are attached
- WHEN the griffier chooses Compare with and picks the 2026 paper
- THEN a comparison with status requested appears, naming both papers

#### Scenario: No AI provider installed
- GIVEN an installation without any TaskProcessing provider
- WHEN the griffier opens the agenda item
- THEN no Summarise or Compare with action is shown and the widget says no AI provider is installed

### Requirement: REQ-APS-003 A confidential paper is not summarised outside its circle

The app SHALL refuse a request when an active confidentiality restriction targets the agenda item or the paper and the caller is outside the restriction's circle. A summary SHALL never be readable by someone who cannot read its paper.

#### Scenario: A clerk outside the circle is refused
- GIVEN a restriction on the agenda item Grondtransactie Noord limits it to the college
- WHEN a clerk who is not in that circle asks for a summary
- THEN the request is refused with a message naming the restriction and no task is scheduled

### Requirement: REQ-APS-004 The AI result lands as a draft, and long papers are summarised in parts

When the TaskProcessing task succeeds the app SHALL write its output into the summary and move it to `draft`; when it fails, to `failed`. A paper longer than one task's input SHALL be summarised part by part and the parts summarised again, and the summary SHALL record that it was.

#### Scenario: The result arrives
- GIVEN a requested summary whose task finishes
- WHEN the griffier reloads the agenda item
- THEN the summary shows as draft with its text and the provider that wrote it

#### Scenario: The provider fails
- GIVEN a requested summary whose task fails
- WHEN the griffier reloads the agenda item
- THEN the summary shows as failed with a Try again action

### Requirement: REQ-APS-005 Members see a summary only after a clerk shows it

A member of the secretariat SHALL be able to edit a draft's text and then show it to members or hide it. A shown summary SHALL be labelled as AI-generated with the name of the clerk who checked it and the date. A shown summary SHALL be hideable again.

#### Scenario: The griffier corrects and shows a summary
- GIVEN a draft summary that says the budget closes with a surplus of 2 million
- WHEN the griffier corrects it to 1.2 million and chooses Show to members
- THEN council members read the corrected text, labelled "AI-generated summary, checked by" the griffier with the date

#### Scenario: A shown summary is withdrawn
- GIVEN a shown summary
- WHEN the griffier chooses Hide
- THEN members no longer see it and the history records who hid it
