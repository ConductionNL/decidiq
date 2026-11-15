# Board composition

A body lists the competences it needs. Its members record the competences they bring. The Composition widget on the body page shows where the body falls short and how its members are made up against the targets it set itself.

## The skills matrix

Open a body and scroll to the Composition widget. The current members are the rows and the body's competences are the columns. Each cell shows the level a member holds: basic, experienced or expert. A level nobody has confirmed yet says "unconfirmed".

Under each column the widget counts the members who hold that competence at experienced or expert, confirmed. When fewer members hold it than the body requires, the column says "Gap".

## Who does what

| Action | Who |
|---|---|
| Add or change a competence the body needs | A signatory of the body, or an administrator |
| Record a competence | The member, for their own seat, or a signatory for any member |
| Confirm a competence | A signatory of the body, never the holder |

Changing the level of a confirmed competence clears the confirmation. A signatory confirms it again.

Every write goes through decidiq's competence routes, which check the body's signatory scope. The object API accepts reads only for these two record types.

## Composition figures

The second half of the widget counts the current members by gender, age band, nationality, independence and whether they sit from outside. Each dimension shows counts, shares and how many members have nothing recorded. Age bands are worked out on the day you look: under 40, 40 to 54, 55 to 69, 70 and over. The figures never list names.

Gender is counted as stored on the person. "F" and "female" count apart, so keep one spelling per body.

## Targets

A body sets its own targets in `diversityTargets` on the body: a dimension, a value and a minimum share between 0 and 1. For example at least 0.33 female. The widget shows each target as met or not met.

## Try it

Load the corporate example set and open "Raad van Commissarissen Waterschap Amstel, Gooi en Vecht". IT and cybersecurity and Water management show as gaps. Confirm Mark van den Berg's Water management as Janneke de Bruin, and that gap closes.
