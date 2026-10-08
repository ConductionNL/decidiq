# Design: confidentiality-term-and-lapse

Read at decidiq development `d49a5ef2` (8 Oct 2026).

## Screen

Board **DcGeheimhouding** on canvas part 2 (https://claude.ai/artifact/QAAxpcsFKBCvDUGbQbwtCa; local copy `zuiddrecht/v2/project2/DcGeheimhouding.dc.html`): "Een geheimhouding van opleggen tot bekrachtigen en opheffen, met de grond en waar zij op ligt."

| Where on the board | What it shows | Source field |
|---|---|---|
| Header | title, pill Opgelegd, breadcrumb Registers / Geheimhoudingsregister / title, "Bekrachtiging door de raad uiterlijk 15 oktober 2026", Bewerken, Meer | `lifecycle`, `ratificationDeadline`, ratifying body |
| Stepper | Opgelegd (sinds 10 sep), Bekrachtigd (raad 15 okt), Opgeheven | `lifecycle`, `imposedAt`, ratification meeting |
| Tabs | Overzicht, Gerelateerd 2 | imposing decision, ratification meeting |
| Statustijdlijn 1 | 10 september 2026, Opgelegd door het college, "Collegebesluit 2026-0912 · grond: ..." | `imposedAt`, `imposedByBody`, `imposingDecision`, `ground` |
| Statustijdlijn 2 | 24 september 2026, Met het raadsvoorstel naar de raad, "De bijlage ligt alleen ter inzage voor raadsleden, in de besloten leesmap" | `sentToBodyAt`, `sentWithAgendaItem` |
| Statustijdlijn 3 | 15 oktober 2026, gepland, Bekrachtiging door de raad, "Raadsvergadering 15 oktober, agendapunt 9. Bekrachtigt de raad niet, dan vervalt de geheimhouding." | `ratificationAgendaItem`, its meeting; `lapsed` |
| Statustijdlijn 4 | Na gunning, uiterlijk 1 juli 2027, Opheffen, "Opheffen kan alleen met een besluit van de raad" | `liftingConditions`, `liftBy` |
| Card Geheimhouding | Reikwijdte, Doel, Grond, Wettelijke grondslag, Eerder, Opgelegd door, Opgelegd op, Bekrachtiging uiterlijk, Agendapunt bekrachtiging, Voorwaarden opheffing | `scope`, target, ground name, `citation`, `legacyCitation`, `imposedByBody`, `imposedAt`, `ratificationDeadline`, `ratificationAgendaItem`, `liftingConditions` + `liftBy` |
| Waar de geheimhouding op ligt | the document, "Document bij besluit Verordening parkeren binnenstad 2027" | target and its parent decision |
| Grond | Grond, Grondslag, Eerder, Soort Woo relatief, Bekrachtiging nodig Ja | ground: `name`, `citation`, `legacyCitation`, `category`, `requiresRatification` |
| Gerelateerd | Collegebesluit 2026-0912, Raadsvergadering 15 oktober 2026 | `imposingDecision`, ratification meeting |

## What exists

| Piece | Where |
|---|---|
| Restriction and ground schemas, lifecycle | `lib/Settings/register.d/83-confidentiality-in-plain-words.json` |
| Pages, three-stage timeline | `src/manifest.d/embargo-geheimhouding.json` (:93 timeline), `buildConfidentialityStages` in `src/components/widgets/registerDetailWidgets.js` |
| Publication refusal | `lib/Service/ConfidentialityRestrictions.php` (active states imposed, ratified) |
| Ratification and lifting workflow | `openspec/changes/embargo-geheimhouding` REQ-EMB-003, REQ-EMB-004 (not built) |

## Decisions

- **D1. Lapse is a state, not a lift.** `lapsed` is a separate terminal state so the register can tell "the council lifted it" from "the council did not ratify". `ConfidentialityRestrictions::ACTIVE_STATES` stays imposed and ratified, so a lapsed restriction stops blocking publication without any extra code there.
- **D2. When the system lapses.** A listener on the meeting's transition to closed looks for restrictions whose `ratificationAgendaItem` sits on that meeting, are still `imposed` on a ground with `requiresRatification`, and whose agenda item records neither a ratification decision nor "uitgesteld". Those move to `lapsed` with the meeting and item in the audit entry and a notification to the griffie. This narrows the "never auto-lift" clause of `embargo-geheimhouding` REQ-EMB-003 to "never on a deadline alone"; that change's text is updated in the same PR.
- **D3. The end date prepares, it does not decide.** A daily job finds restrictions with `liftBy` within the lead time (admin setting, default 30 days) and no lifting agenda item yet, and places a proposal "Opheffen geheimhouding <title>" on the next scheduled meeting of the body that ratified it (or imposed it when it needs no ratification). Past `liftBy` the restriction shows overdue in the register KPI. The lifecycle does not change.
- **D4. Sending to the body is recorded, not inferred.** `sentToBodyAt` and `sentWithAgendaItem` are set when the restricted paper is attached to an agenda item of another body; the board's line about the closed reading folder comes from that item's confidential-papers setting (`agenda-item-confidential-papers`).

## Example data

The municipality example set gains the board's restriction: "Onderbouwing tarieven grondexploitatie.pdf" under Verordening parkeren binnenstad 2027, imposed by the college on 10 Sep 2026 by Collegebesluit 2026-0912 on ground Woo 5.1 lid 2 b (eerder Wob 10 lid 2 b), sent with the raadsvoorstel on 24 Sep, ratification item 9 on the council meeting of 15 Oct, lifting "Na gunning van de grondexploitatie", liftBy 1 Jul 2027. A second example restriction is lapsed.
