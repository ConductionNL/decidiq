# member-onboarding Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [bodies-onboarding-checklist-walkthrough](../../) (this delta)

## Purpose

An onboarding or offboarding record holds a checklist, but nobody can tick a step off and nothing happens when one is done. Moves decidiq matrix rows bod-08 toward built.

## ADDED Requirements

### Requirement: REQ-MOBW-001 The secretary walks a member through the checklist

The secretary SHALL be able to mark each open step done or skipped on the record page, and the record SHALL move to completed once no step is open.

#### Scenario: the last open step is marked done
- GIVEN secretary Anna opens the onboarding of Pieter with one open step
- WHEN she presses Mark as done on it
- THEN the step reads Done and the record status reads Completed

#### Scenario: a plain member cannot tick steps
- GIVEN member Kees opens the same record
- WHEN he sends the step update
- THEN the answer is 403 and the step is unchanged

### Requirement: REQ-MOBW-002 Installation and exit steps change the membership

Completing the installation step SHALL create the membership; completing the end step of an offboarding SHALL set the membership end date.

#### Scenario: installation creates the membership
- GIVEN an onboarding for Pieter in the council as member, installed on 1 October
- WHEN the installation step is marked done
- THEN the council's Members widget lists Pieter from 1 October

#### Scenario: offboarding ends it
- GIVEN an offboarding for Pieter with end date 31 December
- WHEN the end step is marked done
- THEN his membership has end date 31 December
