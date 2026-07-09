---
kind: feature
depends_on: [decidesk-base]
---

# Commissievergaderingen

## Why

Vrijwel elke Nederlandse gemeenteraad werkt met raadscommissies als voorbereidende organen op de plenaire raadsvergadering. De griffie moet commissievergaderingen plannen, samenstelling beheren, belangenconflicten registreren, en commissieadviezen traceerbaar koppelen aan plenaire besluiten. Commissieleden en burgers hebben grote handmatige lasten: fractie-voorzitters moeten per e-mail plaatsvervangers doorgeven, commissieadviezen verdwijnen in PDF-bijlagen, en insprekers moeten hun persoonlijke gegevens publiek maken.

Deze spec breidt Decidesk uit met een eersteklas commissie-model dat:
- Sub-vergaderingen voor commissies beheert (planning, agendavoorbereiding, verslaglegging)
- Vaste samenstelling per commissie (leden, plaatsvervangers, voorzitter, griffier) bijhoudt
- Belangenverstrengelings-declaraties per agendapunt verplicht stelt (artikel 28 Gemeentewet)
- Commissieadvies automatiseert naar de plenaire raadsagenda (met fractie-standpunten als gestructureerde data)
- Inspraak-aanmelding biedt via publiek portaal met privacy-scheiding (contactgegevens intern, onderwerp publiek)
- Besloten zittingen ondersteunt met aparte toegangscontrole en audit-trail voor Woo-openbaarmaking

De spec adresseert drie pijnpunten uit griffies-interviews:
1. **Plaatsvervanging**: Fractie-voorzitter geeft plaatsvervanger door via fractie-portaal; presentie en stemrecht synchroniseren automatisch
2. **Advies-tracering**: Commissieadvies wordt eersteklas annotatie op plenaire agendapunt met fractie-standpunten, niet losse PDF
3. **Privacy bij inspraak**: Burgers melden inspraak aan, contactgegevens blijven intern, inspraak-onderwerp en spreker-naam worden publiek

## What Changes

- **NEW** 8 schemas: `Commissie`, `CommissieLidmaatschap`, `CommissieVergadering`, `CommissieAgendapunt`, `CommissieAdvies`, `BelangenverstrengelingDeclaratie`, `InspraakAanmelding`, `Presentielijst`
- **NEW** `lib/Settings/commissievergaderingen_register.json` met schema-definities en seed data (3-5 Nederlandse commissies met leden en vergaderingen)
- **NEW** `lib/Migration/CommissionRegistration.php` die de register via `ConfigurationService::importFromApp()` importeert
- **MODIFIED** `lib/AppInfo/Application.php` – registratie van CommissionRegistration
- **NEW** `lib/Service/CommissionService.php` – business logic voor commissie-beheer (samenstelling, plaatsvervanging)
- **NEW** `lib/Service/CommissionMeetingService.php` – commissievergadering-lifecycle en adviesvorming
- **NEW** `lib/Controller/CommissionController.php` – REST API voor commissies en leden
- **NEW** `lib/Controller/CommissionMeetingController.php` – REST API voor commissievergaderingen
- **NEW** `lib/Controller/CommissionAdviceController.php` – adviesvorming en publicatie naar plenaire raad
- **NEW** frontend views voor griffie en commissieleden (Vue.js met Nextcloud Vue)
- **NEW** `tests/Unit/Service/CommissionServiceTest.php` en bijbehorende integration tests
- **NEW** `docs/features/commissievergaderingen.md` – operator docs en gebruikers-handleiding

## Capabilities

### New Capabilities

- `commissie-management`: Commissies instellen op basis van raadsbesluit, samenstelling beheren per fractie, plaatsvervangers aanwijzen
- `commissie-vergaderingen`: Vergaderingen plannen, agenda opstellen, agendapunten typen (mededeling, bespreekstuk, advies-aan-raad, inspraak, besloten), verslaglegging
- `adviesvorming`: Commissieadvies per agendapunt (strekking, tekst, fractie-standpunten), automatische doorgeleiding naar plenaire raad
- `belangenverstrengeling`: Declaratie per commissielid per agendapunt (financieel-belang, aandeelhouderschap, bestuursfunctie, familierelatie, eerder-betrokken, geen), gevolg (onthoudt-zich, verlaat, meldt-maar-blijft)
- `inspraak-aanmelding`: Burgers en organisaties kunnen zich aanmelden voor inspraak via publiek portaal; griffie goedkeurt/wijst af; contactgegevens gescheiden van publieke onderwerp
- `besloten-zittingen`: Agendapunten kunnen als besloten gemarkeerd worden; publieke livestream en portaal onderbr oken; aparte audit-trail voor Woo-openbaarmaking
- `presentielijst-met-plaatsvervanging`: Dynamische presentielijst met aanwezigheid-status, plaatsvervanger-indicatie, aankomst- en vertrektijd
- `commissie-verordening-synchronisatie`: Commissie gekoppeld aan verordening-versie; wijzigingen traceerbaar vastgelegd

### Modified Capabilities

- `p2-meeting-management`: CommissieVergadering is sub-type van Meeting; vererft meeting-lifecycle maar voegt commissie-specifieke velden toe
- `p2-decision-flow`: CommissieAdvies wordt linked annotation op plenaire agendapunt; fractie-standpunten als gestructureerde data
- `p3-governance-bodies`: GovernanceBody krijgt commissie-relaties; Commissie heeft raadslid-koppelingen via CommissieLidmaatschap

## Impact

**Backend:**
- `lib/Settings/commissievergaderingen_register.json` (new)
- `lib/Migration/CommissionRegistration.php` (new)
- `lib/Service/CommissionService.php` (new)
- `lib/Service/CommissionMeetingService.php` (new)
- `lib/Controller/CommissionController.php` (new)
- `lib/Controller/CommissionMeetingController.php` (new)
- `lib/Controller/CommissionAdviceController.php` (new)
- `lib/AppInfo/Application.php` (modified – migration registration)

**Frontend:**
- `src/views/CommissionList.vue` (new)
- `src/views/CommissionDetail.vue` (new)
- `src/views/CommissionMeetingAgenda.vue` (new)
- `src/components/CommissionAdvicePanel.vue` (new)
- `src/components/ConflictDeclaration.vue` (new)
- `src/components/InspraakPanel.vue` (new)

**Data:**
- 8 new OpenRegister schemas; no existing data affected

**Dependencies:**
- Decidesk base (decidesk-base)
- OpenRegister app (runtime autoloader)
- Nextcloud Vue for frontend components

**Other apps:**
- `docudesk`: PDF-rendering van commissie-verslagen met eIDAS-handtekening
- `opentalk`: Livestream van openbare commissievergaderingen met tijdcode-koppeling
- `opencatalogi`: Publicatie van publieke commissie-stukken als TOOI-catalogus
- `openconnector`: Synchronisatie naar iBabs/Notubiz; agenda-sync naar Outlook/Google
- `launchpad`: Dashboards voor griffie (commissie-activiteit, attendance-trends, advies-ratio)
- `openklant`: Inspraak-aanmeldingen via openklant contactgegevens-beheer

## Out of Scope

- Frontend UI in v1 (REST API only; UI follows in p3 sprint)
- Audio/video-recording integration (opentalk-koppeling volgt in p3)
- Livestream-onderbreking bij besloten zitting (opentalk-specifiek, volgt in p3)
- PDF-watermarking voor besloten stukken (docudesk-integratie volgt in p3)
- Automatische kalender-sync (openconnector volgt in p3)
- Dashboard-rendering (launchpad volgt in p3)
- Per-role tool visibility voor MCP tools (v2)
