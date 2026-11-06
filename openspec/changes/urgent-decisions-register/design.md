# Design: urgent-decisions-register

Read at decidiq development `d49a5ef2` (8 Oct 2026).

## Screen

Board **DcSpoedbesluiten** on canvas part 2 (https://claude.ai/artifact/QAAxpcsFKBCvDUGbQbwtCa; local copy `zuiddrecht/v2/project2/DcSpoedbesluiten.dc.html`): "Alle spoedbesluiten op één lijst zien, met wie de spoed verklaarde en welke nog bekrachtiging van de raad nodig hebben."

| Where on the board | What it shows | Source field |
|---|---|---|
| Header | Spoedbesluiten, Downloaden, breadcrumb Voorstellen, "5 van 5 spoedbesluiten · nieuwste eerst" | list total, sort `urgencyDeclaredAt` desc |
| Quick filters | Alle spoedbesluiten 5, Wacht op bekrachtiging 3, Bekrachtigd 2 | `isUrgent`, `awaitingRatification` |
| View modes | Tabel (on), Kaarten, Bord, Kaart | shared index page |
| Column Besluit | title; under it "Burgemeester · gemeld aan de raad op 6 okt" or "College van B en W" | `title`, `urgencyDeclaredByBody`, `reportedToRatifyingBodyAt` |
| Column Spoed verklaard | 2 okt 2026 | `urgencyDeclaredAt` |
| Column Wacht op bekrachtiging | pill Ja (amber) or Nee (green) | `awaitingRatification` |
| Column Levenscyclus | pill In werking, Besloten | `lifecycle` |
| Column Acties | Bekijken | navigate `DecisionDetail` |
| Row select | checkbox per row | mass export |

## What exists

| Piece | Where |
|---|---|
| List page | `src/manifest.d/urgent-decision-procedure.json` (columns, quick filters, export) |
| Urgency fields, `awaitingRatification` | `lib/Settings/register.d/46-urgency-policy.json` |
| Declare flow, guard, ratification stage | `openspec/changes/urgent-decision-procedure` (not built) |
| Raadsinformatiebrief | `raadsinformatiebrieven` change, schema `raadsinformatiebrief` |

## Decisions

- **D1. The body, not the user.** `urgencyDeclaredByBody` is a reference to a `governance-body` (College van B en W) or, for a single-office power such as the mayor's, the body the office belongs to plus `urgencyDeclaredInOffice` (text, "Burgemeester"). The subtitle shows the office when set, else the body name.
- **D2. Reported is a date, set once.** `reportedToRatifyingBodyAt` is set by the first of: the action Gemeld aan de raad, the ratification agenda item being placed, a raadsinformatiebrief linked to the decision. It is never cleared by a later step. The action is guarded like the declaration (same allowed roles).
- **D3. Pills say the word.** Ja and Nee are text in a pill, colour from CSS variables, never colour alone.
- **D4. Counts on the quick filters** come from the list query facets on `awaitingRatification`, no extra request per filter.
