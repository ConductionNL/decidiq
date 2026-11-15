# Recommended text changes to a motion

Not every change to a motion's wording is political. The secretariat fixes a
wrong article number, a committee suggests clearer wording, the executive
corrects an amount. Such a change does not need an amendment and a vote: it is
recommended, and the chair or secretary accepts or rejects it. The motion keeps
the history either way.

Something that needs a vote is an amendment, not a recommendation.

## Line numbers

On a motion's page the **Motion text** widget has a **Line numbers** switch.
Turned on, every line of the text shows its number, starting at 1. Lines break
between words at no more than 80 characters, and a new paragraph starts a new
line, so the same text always gets the same numbers.

## Recommend a change

1. Open the motion and turn on **Line numbers**.
2. Press **Recommend a change**. Only the chair or the secretary of the motion's
   meeting sees this button.
3. Pick **From line** and **To line**. The dialog shows the current text of
   those lines.
4. Write the **New text** and, if you like, a reason.
5. Save. The recommendation is listed as **Pending** under **Recommended
   changes**.

Anyone else cannot create a recommendation, also not through the API.

## Accept or reject

The **Recommended changes** widget sits below the amendments on the motion
page. It lists every recommendation of the motion, oldest first, with its
lines and its status. Open one to see the tracked change: removed words struck
through, added words underlined.

On a pending recommendation the chair or secretary sees **Accept** and
**Reject**; everybody else reads a short note that only the chair or the
secretary can take the next step.

- **Accept** works the new wording into the motion text, the same way an
  adopted amendment does. The motion keeps its first wording and its history
  gains an entry that names the recommendation. The recommendation reads
  **Accepted**, with who decided and when. No voting round is opened.
- **Reject** records who decided and when and leaves the motion alone. A
  rejected recommendation stays listed as **Rejected**.

A decided recommendation cannot be decided again.

## When it can no longer be accepted

- **Passage changed**: something else, for example an adopted amendment,
  changed the lines first, so the passage the recommendation names no longer
  occurs in the text. The recommendation is marked **Passage changed**,
  **Accept** is disabled, and the server refuses it with "The passage has
  changed since this recommendation was made". It stays pending; reject it or
  recommend the change again on the current text.
- **Too late**: once a motion has left draft, proposed or deliberating (for
  example because its voting round is open), its text can no longer change and
  both Accept and Reject are refused.

## For developers

A recommendation is a `change-recommendation` object in the decidiq register,
linked to its motion, with `lineFrom`, `lineTo`, `targetPassage` (the exact
text of those lines), `proposedText`, `reason` and `status` (`pending`,
`accepted` or `rejected`). The secretariat and the administrators may create
one; every signed-in user may read it.

The decision goes through two endpoints, both refusing anyone but the chair or
secretary:

- `POST /apps/decidiq/api/change-recommendations/<id>/accept`
- `POST /apps/decidiq/api/change-recommendations/<id>/reject`

A refusal answers with a message a person can read: 404 for a missing
recommendation or motion, and a client error for a decided recommendation, a
changed passage or a motion past deliberation.
