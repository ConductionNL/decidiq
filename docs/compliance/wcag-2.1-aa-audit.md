# WCAG 2.1 AA audit report

decidiq version 0.1.0, evaluated on 2026-09-30.

This is a supplier self-evaluation following WCAG-EM 1.0 (Website Accessibility Conformance Evaluation Methodology). It is not an independent audit. An organisation cites it in its own accessibility statement (toegankelijkheidsverklaring).

The automated scan did not run for this report, so every criterion it decides reads not tested.

## Summary

- Pass: 0
- Fail: 0
- Not applicable: 0
- Not tested: 50

## Sample

- dashboard: / (decidiq)
- meetings: /meetings (decidiq)
- meeting: first row of /meetings (decidiq)
- meetings-calendar: /meetings/calendar (decidiq)
- reports: /reports (decidiq)
- store: /store (decidiq)
- roadmap: /features-roadmap (decidiq)
- flow: first row of /flows (decidiq)
- live-meeting: first row of /meetings, then /live (decidiq)
- personal-settings: /user-settings (decidiq)
- minutes: /minutes (decidiq)
- minutes-detail: first row of /minutes (decidiq)
- Process: A member opens a meeting (meetings, meeting)
- Process: A member casts a vote (meeting, live-meeting)
- Process: A member reads the minutes (minutes, minutes-detail)
- Not sampled: projection-screen. The projection screen is a JSON endpoint (GET /api/voting-rounds/{id}/public-state) with no page of its own; the live meeting page is sampled instead.

## Success criteria

| Criterion | Level | Outcome | Checked by | Findings |
| --- | --- | --- | --- | --- |
| 1.1.1 Non-text content | A | not tested | manual |  |
| 1.2.1 Audio-only and video-only (prerecorded) | A | not tested | manual |  |
| 1.2.2 Captions (prerecorded) | A | not tested | manual |  |
| 1.2.3 Audio description or media alternative (prerecorded) | A | not tested | manual |  |
| 1.3.1 Info and relationships | A | not tested | manual |  |
| 1.3.2 Meaningful sequence | A | not tested | manual |  |
| 1.3.3 Sensory characteristics | A | not tested | manual |  |
| 1.4.1 Use of colour | A | not tested | manual |  |
| 1.4.2 Audio control | A | not tested | manual |  |
| 2.1.1 Keyboard | A | not tested | manual |  |
| 2.1.2 No keyboard trap | A | not tested | manual |  |
| 2.1.4 Character key shortcuts | A | not tested | manual |  |
| 2.2.1 Timing adjustable | A | not tested | manual |  |
| 2.2.2 Pause, stop, hide | A | not tested | manual |  |
| 2.3.1 Three flashes or below threshold | A | not tested | manual |  |
| 2.4.1 Bypass blocks | A | not tested | manual |  |
| 2.4.2 Page titled | A | not tested | axe-core |  |
| 2.4.3 Focus order | A | not tested | manual |  |
| 2.4.4 Link purpose (in context) | A | not tested | manual |  |
| 2.5.1 Pointer gestures | A | not tested | manual |  |
| 2.5.2 Pointer cancellation | A | not tested | manual |  |
| 2.5.3 Label in name | A | not tested | manual |  |
| 2.5.4 Motion actuation | A | not tested | manual |  |
| 3.1.1 Language of page | A | not tested | axe-core |  |
| 3.2.1 On focus | A | not tested | manual |  |
| 3.2.2 On input | A | not tested | manual |  |
| 3.3.1 Error identification | A | not tested | manual |  |
| 3.3.2 Labels or instructions | A | not tested | manual |  |
| 4.1.1 Parsing | A | not tested | axe-core |  |
| 4.1.2 Name, role, value | A | not tested | manual |  |
| 1.2.4 Captions (live) | AA | not tested | manual |  |
| 1.2.5 Audio description (prerecorded) | AA | not tested | manual |  |
| 1.3.4 Orientation | AA | not tested | manual |  |
| 1.3.5 Identify input purpose | AA | not tested | manual |  |
| 1.4.3 Contrast (minimum) | AA | not tested | axe-core |  |
| 1.4.4 Resize text | AA | not tested | manual |  |
| 1.4.5 Images of text | AA | not tested | manual |  |
| 1.4.10 Reflow | AA | not tested | manual |  |
| 1.4.11 Non-text contrast | AA | not tested | manual |  |
| 1.4.12 Text spacing | AA | not tested | manual |  |
| 1.4.13 Content on hover or focus | AA | not tested | manual |  |
| 2.4.5 Multiple ways | AA | not tested | manual |  |
| 2.4.6 Headings and labels | AA | not tested | manual |  |
| 2.4.7 Focus visible | AA | not tested | manual |  |
| 3.1.2 Language of parts | AA | not tested | manual |  |
| 3.2.3 Consistent navigation | AA | not tested | manual |  |
| 3.2.4 Consistent identification | AA | not tested | manual |  |
| 3.3.3 Error suggestion | AA | not tested | manual |  |
| 3.3.4 Error prevention (legal, financial, data) | AA | not tested | manual |  |
| 4.1.3 Status messages | AA | not tested | manual |  |
