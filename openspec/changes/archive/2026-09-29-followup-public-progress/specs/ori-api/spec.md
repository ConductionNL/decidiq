# ori-api Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [followup-public-progress](../../) (this delta)

## Purpose

Motions are exposed on the public ORI API, but commitments are not published at all and there is no public progress view. Moves decidiq matrix rows fol-06 toward built.

## ADDED Requirements

### Requirement: REQ-FPP-001 The public sees commitment and motion progress

Commitments and motions SHALL be available on the public ORI API with their status, deadline and progress, without internal fields. A commitment SHALL appear only once its publication date has passed and while it is not depublished. The commitment item SHALL carry only the allow-listed fields: text, status, deadline, progress entries (date and note only), settlement note, the related motion and the publication date. A motion's execution status is its status field (`enacted` once carried out); its progress is followed through the commitments that name it.

#### Scenario: A journalist follows a promise
- GIVEN the alderman committed to a housing report by 1 December with one progress entry
- WHEN a journalist reads the public commitments list
- THEN she sees the commitment, its deadline and the progress entry

#### Scenario: Internal fields stay internal
- GIVEN a published commitment that names who made it, a settlement evidence link and a migration reference
- WHEN anyone reads it on the public commitments list or by its id
- THEN the answer carries none of those fields, and a progress entry carries only its date and note

#### Scenario: A commitment before its publication date is not public
- GIVEN a commitment whose publication date is next week, and one with no publication date
- WHEN anyone reads the public commitments list or asks for either by its id
- THEN neither is listed and asking by id answers not found

### Requirement: REQ-FPP-002 The clerk adds a progress entry

The chair or secretary of the meeting a commitment was made in, or an administrator, SHALL be able to add a dated progress entry to the commitment from its page. Anyone else SHALL be refused.

#### Scenario: The clerk records progress
- GIVEN the secretary of the council meeting where the commitment was made
- WHEN she adds the note "Draft report sent to the committee" on the commitment page
- THEN the commitment lists that entry with today's date, newest first

#### Scenario: A member cannot record progress
- GIVEN a council member who is not chair or secretary of that meeting
- WHEN she tries to add a progress entry
- THEN the request is refused and the commitment is unchanged

@e2e exclude the e2e session is an administrator, who may add progress; the refusal is proven by tests/Unit/Controller/CommitmentProgressTest.php::testMemberIsRefused over the real MeetingRoleGate.
