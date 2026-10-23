---
kind: code
depends_on: []
---

# Proposal: motion-change-recommendations

## Summary

Let the griffie or the motion's committee propose small text changes to a motion line by line, as recommendations, and let the chair or secretary accept or reject each one without a vote. An accepted recommendation is worked into the motion text the same way an adopted amendment is (REQ-AMT-001), and the motion keeps the history. A rejected one changes nothing and stays visible.

## The row this covers

Source: `openspec/parity/capabilities.json`.

- **mot-19** Propose line by line text changes to a motion as a recommendation, and accept or reject each one (built, partial; this change covers the unbuilt half).

The row's note says what is missing: "Text changes can be proposed as amendments, shown as tracked changes and each adopted or rejected by vote. There are no line-numbered recommendations that the author or chair can accept or reject one by one without a vote."

## Why

An amendment is a political act: a member tables it and the meeting votes on it. Much of what changes in a motion's wording is not political. The griffie fixes a wrong article number, a committee suggests clearer wording, the college corrects an amount it got wrong. Today each of those has to become an amendment with its own voting round, or the secretary edits the motion text and the change leaves no trace. OpenSlides has had change recommendations with line ranges since 4.0 (`motion_change_recommendation`, extended in 4.2.25). decidiq already has the two parts this needs: the merge of a passage into a motion's text (`AmendmentTextMerger`, #1394) and the tracked-changes view (`AmendmentDiffView`).

## What changes

1. A line-numbered view of the motion text on the motion page, with lines numbered the same way every time the text is shown.
2. "Recommend a change": pick a range of lines, write the new wording and a reason. Stored as a `change-recommendation` object linked to the motion.
3. A "Recommended text changes" widget on the motion page listing each recommendation with its lines, the tracked change and its status, and Accept and Reject for the chair or secretary.
4. Accept works the change into the motion text and appends to `amendmentHistory`; Reject records the decision and leaves the text alone.
5. A recommendation whose passage no longer occurs in the text (because something else changed it first) shows "Passage changed" and cannot be accepted.

## Out of scope

- Voting on a recommendation. Something that needs a vote is an amendment.
- Recommendations on amendments, or on documents other than the motion text.
- Changing line length per body. One fixed width for every motion.
- Publishing recommendations on the public site.
