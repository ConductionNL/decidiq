# Board portal — gebruikershandleiding

> Doelgroep: bestuursleden (RvC, RvB, audit-, remuneratie- en
> benoemingscommissies) en bestuurssecretarissen.
>
> Status: shipped Phase 1-8 (registratie, services, controllers, eIDAS,
> proxy/written/governance reporting, CalDAV-koppeling, Vue-frontend).
> Phase 9 dekt de API-contracttests; Phase 10 is dit document.
> Spec: `openspec/changes/board-meeting-resolutions/`.

## 1. Waar vind ik het board portal?

Na installatie van Decidesk verschijnen drie nieuwe top-level navigatie-items
in de zijbalk van de app:

- **Boards** — overzicht van alle besturen waar je lid van bent.
- **Board Meetings** — vergaderingen, gefilterd op je actieve besturen.
- **Resolutions** — besluiten en stemmingen.

Toegang wordt op objectniveau bewaakt door OpenRegister (ADR-022): je ziet
alleen de besturen, vergaderingen en resoluties waarvoor je expliciet bent
geregistreerd als `BoardMember`. Beheerders en de bestuurssecretaris zien
alles binnen hun eigen organisatie.

## 2. Boards (besturen)

### 2.1 Een bestuur aanmaken

1. Klik in `Boards` op **Nieuw bestuur**.
2. Vul ten minste een **naam** in.
3. Kies optioneel een **type** (Raad van Commissarissen, Raad van Bestuur,
   audit-, remuneratie-, benoemings- of risk-commissie, of een one-tier
   board) en een **governance-model** (`two-tier` of `one-tier`).
4. Bevestig — het bestuur wordt aangemaakt en je komt op de detailpagina.

### 2.2 Leden toevoegen, rol wijzigen, verwijderen

Op de bestuursdetailpagina staat de ledenlijst.

- **Lid uitnodigen** — voer naam en Nextcloud-gebruikers-id in en kies een
  rol uit: `chairman`, `vice-chairman`, `member`, `executive-member`,
  `non-executive-member`, `independent-member`, of
  `employee-representative`. Markeer optioneel de **onafhankelijkheids-status**
  (`independent` / `non-independent`) — dit telt mee voor de
  onafhankelijkheidsratio op het bestuursdashboard.
- **Rol wijzigen** — gebruik de rol-knop achter een lid. Een wijziging
  schrijft een entry naar het hash-chained auditlog.
- **Lid verwijderen** — zet `termEndDate` op vandaag. De rij wordt niet
  fysiek verwijderd zodat historische resoluties geldig blijven verwijzen.

## 3. Board meetings (bestuursvergaderingen)

### 3.1 Plannen

1. Open een bestuur en klik **Vergadering plannen**.
2. Vul de **vergaderdatum** in (verplicht), kies een **type** (`regular`,
   `extraordinary`, `strategy-day`, `closed-session`, `executive-session`),
   een **format** (`in-person`, `remote`, `hybrid`) en de **taal** van de
   vergadering (`nl`, `en`, ...).
3. De vergadering komt in status `scheduled`.

### 3.2 Lifecycle

De bestuurssecretaris doorloopt deze statusovergangen:

| Actie | Vanuit | Naar |
|---|---|---|
| `send-notice` | `scheduled` | `notice-sent` |
| `distribute-materials` | `notice-sent` | `materials-distributed` |
| `open` | `materials-distributed` / `scheduled` | `in-session` |
| `adjourn` | `in-session` | `adjourned` |
| `close` | `in-session` / `adjourned` | `closed` |
| `sign-minutes` | `closed` | `minutes-signed` |

De knop **Verstuur uitnodiging** roept `send-notice` aan; de
lifecycle-dropdown op de detailpagina dekt de overige acties. Iedere
transitie wordt vastgelegd in het auditlog en — als CalDAV beschikbaar is —
gesynchroniseerd naar de bestuurskalender via `X-DECIDESK-*` properties
(zie [Architecture](../Technical/board-portal-architecture.md#caldav)).

## 4. Resolutions (resoluties en stemmingen)

### 4.1 Resolutie voorstellen

Op de detailpagina van een vergadering klik je **Resolutie voorstellen**:

1. Voer een **titel** in (verplicht).
2. Kies optioneel het **type** (`approval`, `appointment`, `dismissal`,
   `financial`, `strategic`, `policy`, `delegation-of-authority`,
   `acknowledgement`, `written-resolution`).
3. Kies de **stemdrempel** (`voteThreshold`): `simple-majority`,
   `qualified-majority-two-thirds`, `qualified-majority-three-quarters`,
   `unanimous`. Standaard staat op `simple-majority`.
4. Kies optioneel de **stemvorm** (`voteType`: `named` of `anonymous`).

De resolutie krijgt status `proposed`.

### 4.2 Stemming openen

De voorzitter klikt **Stemming openen** op de resolutiedetailpagina. Dit
roept de quorum-guard aan
(`ResolutionLifecycleGuard::canOpenVote`): de bijbehorende vergadering
moet `in-session` zijn en het aantal aanwezige leden moet voldoen aan de
quorumregel van het bestuur. Pas dan gaat de resolutie naar
`under-discussion`.

### 4.3 Stem uitbrengen

Tijdens `under-discussion` kan elk bestuurslid in het portal stemmen via
de stem-tegel:

- **Stem** — `in-favor`, `against`, `abstain`, `absent`, of
  `recused-due-to-conflict`.
- **Methode** — `electronic` (default), `in-person`, of `proxy`.
- **Proxy-houder** — UID van het lid dat namens je stemt (alleen bij
  `proxy`).

De `ConflictOfInterestService` blokkeert je stem automatisch als er een
actief belangenconflict is voor jou op het bijbehorende agenda-item.

### 4.4 Live tally + conclusie

Tijdens de stemming toont het portal een live **tally** (telling per
optie + totaal uitgebracht). Wanneer de voorzitter op **Stemming sluiten**
klikt:

- Worden alle `board-vote` rijen voor de resolutie ingelezen.
- Berekent `ResolutionService::conclude` de uitkomst tegen de
  `voteThreshold`.
- Krijgt de resolutie status `adopted` of `rejected`.

De uitkomst en raw votes zijn altijd via **Audit** opvraagbaar.

## 5. Schriftelijke besluiten (written resolutions)

Een resolutie kan ook buiten een vergadering om vastgesteld worden:

1. Maak in `Resolutions` een resolutie aan met type `written-resolution`.
2. Stuur de digitale handtekenverzoeken naar alle leden via de **eIDAS
   QES** flow (zie [Architecture — eIDAS](../Technical/board-portal-architecture.md#eidas-qualified-signatures)).
3. Zodra alle vereiste handtekeningen binnen zijn, wordt de resolutie
   automatisch als `adopted` gemarkeerd.

## 6. Belangenconflicten

Op je persoonlijke profielpagina kun je een **belangenconflict declareren**:

- **Type** — direct, indirect, familieband, financieel, of bestuurlijk.
- **Status** — actief / opgelost.
- **Werkingssfeer** — algemeen of beperkt tot specifieke agenda-items /
  resoluties.

Een actief, op de scope toepasselijk conflict blokkeert automatisch zowel
het stemmen op het betreffende agenda-item als deelname aan de discussie
(volgens de access-level matrix in `BoardMaterialAuthorizationService`).
Iedere declaratie en automatische blokkade wordt vastgelegd in het
auditlog.

## 7. Bestuursdocumenten (board materials)

Op de bestuursdetailpagina staat een tabel met alle **board materials**
(agenda's, financiële stukken, juridische memo's). Toegang per document
volgt de access-level matrix:

- `public` — iedereen met portal-toegang.
- `members-only` — alleen leden van dit bestuur.
- `committee-only` — alleen leden van de betreffende commissie.
- `executive-only` — alleen executive members.
- `chair-only` — alleen voorzitter + vice-voorzitter.

De **Download**-knop logt iedere download in het auditlog. Documenten
boven access-level `members-only` worden door docudesk gewatermerkt
geleverd (zie Architecture).

## 8. Hulp en troubleshooting

- Een transitie wordt geweigerd met "lifecycle transition not allowed
  from {status}" — controleer de huidige status van de vergadering.
- "Resolution not found" of "Board not found" — de OpenRegister object-API
  filtert op leesrechten; je bent waarschijnlijk geen lid van dit bestuur.
- "Quorum not met" — onvoldoende leden ingecheckt voor de vergadering.
- Voor verdere ondersteuning, zie de
  [admin runbook](../admin/board-portal-admin.md).
