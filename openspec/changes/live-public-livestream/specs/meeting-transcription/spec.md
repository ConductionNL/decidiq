# meeting-transcription Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [live-public-livestream](../../) (this delta)

## Purpose

Narrows one rule of the existing capability so the subtitles of a public
broadcast can be released, while the transcript, its text file and the
recording stay confidential. Part of matrix row liv-10.

## MODIFIED Requirements

### Requirement: Confidentiality and retention of recordings and transcripts

Access to `Transcript` objects and their files SHALL be restricted to the governance body's members via OpenRegister RBAC and the meeting folder's Files access. Recordings and transcripts SHALL be permanently ineligible for public publication, included in the public-publication structural deny-list; the approved minutes are the only public record of what was said beyond the broadcast itself. The one exception SHALL be a caption track derived from an aligned transcript of a meeting flagged `isPublic`, covering only the windows in which that meeting was broadcast live, and released by the chair or secretary after review (meeting-broadcast REQ-LSTR-006 and REQ-LSTR-007). A released caption track SHALL be a separate file named without the deny-list's `recording` and `transcript` markers, and releasing it SHALL NOT make the `Transcript` object, its text file or any recording file publishable. A per-body retention policy (`keep`, `delete-recording`, `delete-both`; default `delete-both` 30 days after minutes approval) SHALL be enforced by a scheduled background job, and each retention deletion SHALL be recorded in the meeting's audit trail. The retention job SHALL NOT delete a released caption track, which follows the public recording rather than the transcript.

#### Scenario: Non-member access denied

@e2e exclude RBAC contract, covered by Newman IDOR suite
- **WHEN** an authenticated user who is not a member of the governance body requests a `Transcript` object or its file
- **THEN** access is denied with HTTP 403/404 by OpenRegister RBAC and Files permissions

#### Scenario: Transcript publication structurally refused

@e2e exclude deny-list contract, covered by PHPUnit on the publication payload service
- **WHEN** a publish request targets a `Transcript` object or a recording file
- **THEN** payload construction is refused as not-publishable regardless of status or actor

#### Scenario: Releasing subtitles does not publish the transcript

@e2e exclude deny-list contract, covered by PHPUnit on PublicationEligibilityService and BroadcastCaptionService
- **GIVEN** a released Dutch caption track for the public council meeting of 12 March
- **WHEN** a publish request targets that meeting's `Transcript` object
- **THEN** it is still refused as not-publishable

#### Scenario: Retention job deletes after approval

@e2e exclude scheduled background job, verified at the PHPUnit layer by invoking the job class directly
- **GIVEN** a body with the default retention policy and minutes approved more than 30 days ago
- **WHEN** the retention job runs
- **THEN** the recording and raw transcript files are deleted, the `Transcript` object reflects the retention state, and the deletion is recorded in the meeting's audit trail

#### Scenario: Retention keeps a released caption track

@e2e exclude scheduled background job, covered by PHPUnit on TranscriptRetentionJob
- **GIVEN** the same body and a released caption track for the meeting
- **WHEN** the retention job runs
- **THEN** the caption file and its share link remain
