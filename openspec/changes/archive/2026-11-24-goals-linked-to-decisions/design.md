# Design: goals-linked-to-decisions

Read at decidiq development `d49a5ef2` (8 Oct 2026).

## Screen

Board **DcDoel** on canvas part 2 (https://claude.ai/artifact/QAAxpcsFKBCvDUGbQbwtCa; local copy `zuiddrecht/v2/project2/DcDoel.dc.html`): "Een doel met voortgang die vanzelf meetelt".

| Where on the board | What it shows | Where it comes from |
|---|---|---|
| Header | Title, status pill (Actief), breadcrumb Registers / Doelen / title, "Eigenaar Tarik Ouali · deadline 31 december 2027", Bewerken and Meer | `title`, `status`, `owner`, `deadline` |
| Stepper | Concept (12 dec 2025), Actief (sinds 1 jan 2026), Bereikt (doel 31 dec 2027) | goal lifecycle `draft`, `active`, `achieved`; `at-risk` shows as Actief with a warning pill, `abandoned` replaces Bereikt |
| Tabs | Overzicht, Gerelateerd 9 | count of contributing objects |
| Card Voortgang | Doelwaarde 7 van 12 fietsroutes met verlichting; Toezeggingen afgedaan 2 van 4; Actiepunten klaar 3 van 5; Subdoelen bereikt 1 van 2; the line "De voortgang telt vanzelf mee ... Niemand hoeft hier een percentage in te vullen." | `currentValue`/`targetValue`/`unit`; aggregations settled/linked commitments, completed/linked action items, achieved/child goals |
| Card Doel (Bewerken) | Titel, Horizon, Eigenaar, Orgaan, Start, Deadline, Doelwaarde, Huidige waarde, Hoofddoel, Status | goal properties, `parentGoal` resolved to its title |
| Subdoelen (2), Subdoel toevoegen | Subdoel, Eigenaar, Deadline, Status | goals with `parentGoal` = this goal |
| Wat aan dit doel bijdraagt (9), Alle 9 bekijken | Soort, Wat, Termijn, Status; rows Toezegging, Actiepunt, Termijnagenda | commitments, action items, planned agenda items and decisions with `goal` = this goal |
| Kerngegevens | Eigenaar, Orgaan, Horizon, Looptijd (start tot deadline), Hoofddoel | goal properties |
| Hoofddoel | Parent title, "3 subdoelen, waarvan 1 bereikt" | parent goal and its child counts |

The board draws no decision row in the contribution list, but its list is a sample of 9 kinds of contribution; a decision row reads Soort "Besluit", Wat its title, Termijn its decision date, Status its lifecycle.

## What exists

| Piece | Where |
|---|---|
| Goal schema, lifecycle, aggregations, calculations | `lib/Settings/register.d/66-organisation-goals.json` (aggregations :200-260, calculations rates) |
| Goal pages | `src/manifest.d/organisation-goals.json` (index `/goals`, detail `/goals/:id` with a data and a related widget) |
| Goal links | `governance-commitment.goal` (`84-commitment-in-plain-words.json`), `action-item.goal` (`decidesk_register.json:2980`), `planned-agenda-item.goal` (`86-the-last-two-dutch-names.json:262`) |
| Retired schemas still counted | `toezegging` (`84`, active false) in `linkedCommitmentCount`, `settledCommitmentCount` |
| Materialise on save | OpenRegister `CalculationOnSaveListener`, `RematerialiseCalculationsCommand` |

## Decisions

- **D1. The link lives on the decision.** One optional `goal` on `decision`, same shape as on the other three schemas. A decision serves at most one goal, like a commitment.
- **D2. Fix the counts in the declaration.** Aggregations name `governance-commitment` (settled = `lifecycle: disposed`) and add `linkedPlannedAgendaItemCount` and `linkedDecisionCount`. No PHP aggregation service (ADR-031).
- **D3. Refresh by re-saving the goal's calculations.** A listener on OpenRegister's object saved and deleted events for the four contributor schemas and for `goal` reads the old and new `goal` (or `parentGoal`) and asks OpenRegister to re-materialise the calculations of each affected goal (the same path `RematerialiseCalculationsCommand` uses). This is the one imperative seam: a calculation that depends on other objects cannot trigger itself.
- **D4. The page stays declared.** The detail page uses manifest widgets: a stats widget for the progress card, a table widget for subgoals and for contributions. Where the shared library lacks a "x van y" stat, the widget is a registered detail widget in `src/components/widgets/`, as `DelegationChainWidget` is.

## Example data

The municipality example set gains the board's goal "Verlichte en veilige fietsroutes in 2027" (owner Tarik Ouali, College van B&W, target 12, current 7) under "Mobiliteitsvisie 2030", with two subgoals, four commitments (two disposed), five action items (three completed), one planned agenda item and one decision.
