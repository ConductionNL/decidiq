# Document details

A griffie knows more about a stuk than its file name says: which kind of stuk it
is, the zaaknummer, whether it is a concept or final. This page shows how an
administrator declares kinds of document with their own fields, and how a clerk
fills them in for each file on a meeting or an agenda item. You need no
supplier to add a field.

## Declare a document type

1. Open the settings menu and choose **Document types**.
2. Add a type, for example "Raadsvoorstel".
3. Say where it is offered: on meeting files, on agenda item files, or both.
4. Add its fields. Each field has a key, a label and a kind: text, long text,
   number, yes or no, date, a pick from a list, or a link to another record.
   Tick **Required** when a document of this kind may not be saved without it.
5. Save.

A type you switch off is no longer offered, and the details already recorded
with it stay.

## Fill in the details of a file

1. Open a meeting or an agenda item and find the **Document details** widget.
   It lists every file attached to the page.
2. Press **Details** beside a file.
3. Pick a document type. Only the types offered for this place appear.
4. Fill in the fields and press **Save details**.

The widget then shows the type and the first two values beside the file. A
second save on the same file changes its details; a file never gets two sets.

A required field left empty is refused, in the dialog and through the API, and
the message names the field.

## Renaming a field key

The key is where the value is stored. Change a key and the values saved under
the old key are no longer shown. Change the label instead when you only want a
different name on screen.

## Next

Declare the two or three kinds of stuk your griffie files most, give each the
field people now write into the file name, and ask a clerk to fill in the
details of next week's agenda.
