# The document accessibility check

Papers published with an agenda or a decision must be readable for everyone,
including people who use a screen reader. decidiq checks each PDF paper for
the most common problems before it is published, shows the result next to
the paper, and can hold a publication back until a failing paper is fixed or
someone records why it is published anyway.

## What the check is, and what it is not

The check is a **heuristic**. It looks for the problems that make a PDF
unreadable most often:

- **No text layer**: a scanned page with no text a screen reader can read.
- **Not tagged**: no structure tags, so headings, lists and tables cannot be
  followed.
- **No language**: the document does not say which language it is in.
- **No title**: the document properties carry no title.
- **No bookmarks**: a document of more than 20 pages without bookmarks.

It does not certify a document as accessible. A paper that passes can still
have problems, and the check is not a PDF/UA or EN 301 549 audit. The badge,
the report and the CSV export all say so.

Files that are not PDF are not checked; they show **Not checked yet** with a note
that only PDF documents are checked. A PDF that cannot be read, or that is
larger than 25 MB, is also **Not checked yet**, never accessible.

## See the result of a paper

Open a meeting or an agenda item and go to **Accessibility**. Each paper shows
its badge:

| Badge | Meaning |
| --- | --- |
| Accessible (heuristic check) | The check found no problems. |
| Accessible with warnings (heuristic check) | The paper has a title, language or bookmarks missing. |
| Not accessible (heuristic check) | The paper has no text layer or no structure tags. |
| Not checked yet | The paper was not checked yet, or could not be checked. |

Click a badge to see what the check found, what it saw in the file, and how
to fix it in the source document, for example "Export it again from the
source document with tagged PDF switched on".

A paper that was not checked yet has **Check now**, which checks it at once.

## Check papers as they are added

An administrator decides whether papers are checked as soon as they are
added. Go to **Administration settings** → **decidiq** → **Document
accessibility check** and switch **Check a paper as soon as it is added** on
or off. It is on by default; the check then runs in the background shortly
after the upload.

## Hold publications back

On the same settings page the administrator chooses **When a paper fails the
check**:

- **Publish without checking** (off): publishing works as before the check
  existed.
- **Warn, and publish once the publisher confirms** (warn, the default): a
  publication with a failing or unchecked paper shows the papers and their
  findings, and is published after the publisher confirms.
- **Hold the publication back unless a reason is given** (block): a publication
  with a failing paper is published only when the publisher gives a reason.

The check runs on the server, so it applies to publishing from the API as
well as from the screen. A paper that was not checked yet is checked when it
is published.

When a publication is published past the check, the reason, who gave it, when,
and which papers it concerned are kept with the publication record.

## The report per body and period

Open **Document accessibility** in the menu. Pick a governance body and a
period to see how many papers passed, had warnings, failed or were not
checked, with their share of the total, how many were published past the
check, and the papers that failed.

**Export** downloads the same figures as a CSV, with the note that they come
from a heuristic check.
