# Corporate governance — gebruikershandleiding

> **Let op — architectuurwijziging (ADR-006, change `retire-board-portal`, Cycle-1 refactor 2026-06-14):**
> Het afzonderlijke "board portal" met eigen `Board*`-schema's is **buiten gebruik gesteld**. Corporate
> governance (RvC, RvB, audit- en remuneratiecommissies) wordt nu bediend via de **universele entiteiten**
> van Decidiq in `organisatie_modus=corp`. Een bestuur is een `GovernanceBody` met `bodyType=supervisory-board`
> of `bodyType=executive-board`; een bestuursvergadering is een gewone `Meeting`; een resolutie is een
> `Decision` met `decisionType=resolution`; bestuursleden zijn `Person`-objecten verbonden via `Membership`.
> De gekoppelde bestuursfeatures (eIDAS-ondertekening, belangenconflicten, volmachtstemmen, governance-rapportage,
> toezichthouder-export, meertalige reconciliatie) zijn hergericht op de universele entiteiten.
>
> Doelgroep: bestuursleden (RvC, RvB, audit-, remuneratie- en
> benoemingscommissies) en bestuurssecretarissen.
>
> Voormalige spec: `openspec/changes/board-meeting-resolutions/` (gearchiveerd).

## 1. Waar vind ik de corporate governance-functies?

Na installatie van Decidiq en het instellen van `organisatie_modus=corp` in
de beheerinstellingen past de zijbalk zich automatisch aan:

- **Dashboard** — overzicht van aankomende vergaderingen, openstaande resoluties en actiepunten.
- **Meetings** — bestuursvergaderingen, gefilterd op jouw besturen.
- **Decisions** (weergegeven als **Resolutions** in `corp`-modus) — besluiten en stemmingen.
- **Action items** — actiepunten uit vergaderingen.
- **Motions** — moties of agendaverzoeken.
- **Bodies** (weergegeven als **Board** in `corp`-modus) — overzicht van besturen.

Toegang wordt op objectniveau bewaakt door OpenRegister (ADR-022): je ziet
alleen de besturen, vergaderingen en resoluties waarvoor je expliciet een
actief `Membership`-object hebt. Beheerders en de bestuurssecretaris zien
alles binnen hun eigen organisatie.

## 2. Bodies — besturen aanmaken en beheren

In corporate-modus (`organisatie_modus=corp`) zijn besturen **GovernanceBody**-objecten
met `bodyType=supervisory-board` (RvC) of `bodyType=executive-board` (RvB).

### 2.1 Een bestuur aanmaken

1. Ga naar **Bodies** (in corp-modus weergegeven als **Board**) en klik op **Nieuw bestuur**.
2. Vul ten minste een **naam** in.
3. Stel **bodyType** in op `supervisory-board` (Raad van Commissarissen) of
   `executive-board` (Raad van Bestuur). Commissies (audit, remuneratie,
   benoeming, risk) worden als aparte GovernanceBody aangemaakt met
   `bodyType=committee` en een verwijzing naar het bovenliggende bestuur.
4. Bevestig — het bestuur wordt aangemaakt en je komt op de detailpagina.

### 2.2 Leden toevoegen, rol wijzigen, verwijderen

Bestuursleden zijn **Person**-objecten die via een **Membership** aan het
bestuur zijn verbonden. Op de bestuursdetailpagina staat de ledenlijst.

- **Lid uitnodigen** — zoek de persoon op (of maak een nieuw Person aan) en
  maak een Membership aan met de gewenste rol: `chairman`, `vice-chairman`,
  `member`, `executive-member`, `non-executive-member`, `independent-member`,
  of `employee-representative`. Stel optioneel `independenceStatus`
  (`independent` / `non-independent`) in op het Membership-object — dit telt
  mee voor de onafhankelijkheidsratio op het bestuursdashboard.
- **Rol wijzigen** — pas het Membership-object aan. De wijziging wordt
  automatisch vastgelegd in het auditlog van OpenRegister.
- **Lid verwijderen** — stel `endDate` op het Membership-object in op vandaag.
  Het Person-object blijft bestaan zodat historische resoluties geldig
  blijven verwijzen.

## 3. Meetings — bestuursvergaderingen

Bestuursvergaderingen zijn universele **Meeting**-objecten. Het onderscheid
met raadsvergaderingen ligt in de gekoppelde GovernanceBody (`bodyType=supervisory-board`
of `bodyType=executive-board`), niet in een apart schema.

### 3.1 Plannen

1. Open een bestuur en klik **Vergadering plannen** (of ga naar **Meetings** en kies het bestuur als organiserend orgaan).
2. Vul de **vergaderdatum** in (verplicht), kies een **type** (`regular`,
   `extraordinary`, `strategy-day`, `closed-session`, `executive-session`),
   een **format** (`in-person`, `remote`, `hybrid`) en de **taal** van de
   vergadering (`nl`, `en`, ...).
3. De vergadering komt in status `draft` en kan via de lifecycle naar `convened` worden gezet.

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

## 4. Decisions / Resolutions (resoluties en stemmingen)

In corp-modus worden Decisions weergegeven als **Resolutions**. Een resolutie
is een `Decision`-object met `decisionType=resolution` (ADR-005).

### 4.1 Resolutie voorstellen

Op de detailpagina van een vergadering klik je **Resolutie voorstellen** (of **+ New Decision** in de Decisions-lijst):

1. Voer een **titel** in (verplicht).
2. Het veld **decisionType** is vooringesteld op `resolution` in corp-modus.
   Kies optioneel een subtype via de **category**: `approval`, `appointment`, `dismissal`,
   `financial`, `strategic`, `policy`, `delegation-of-authority`,
   `acknowledgement`, `written-resolution`.
3. Kies de **stemdrempel** (`requiredMajority`): `simple`, `qualified` (2/3),
   `qualified-three-quarters`, `unanimous`. Standaard staat op `simple`.
4. Kies optioneel de **stemvorm** via het ProcessTemplate (named / anonymous).

De resolutie krijgt status `draft` en doorloopt de geconfigureerde workflow.

### 4.2 Stemming openen

De voorzitter klikt **Stemming openen** op de resolutiedetailpagina. De
quorum-guard controleert of de bijbehorende vergadering `in_progress` is en
of het aantal aanwezige leden (via Membership-aggregatie) voldoet aan de
quorumregel van het bestuur. Pas dan gaat de resolutie naar de volgende
workflowstatus (`debated` of `voted`, afhankelijk van het ProcessTemplate).

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

Tijdens de stemming toont het scherm een live **tally** (telling per
optie + totaal uitgebracht). Wanneer de voorzitter op **Stemming sluiten**
klikt:

- Worden alle gekoppelde `Vote`-objecten voor de resolutie ingelezen.
- Wordt de uitkomst berekend op basis van de `requiredMajority` van de Decision.
- Krijgt de resolutie status `approved` of `rejected`.

De uitkomst en alle individuele stemmen zijn altijd via het OpenRegister
auditlog opvraagbaar.

## 5. Schriftelijke besluiten (written resolutions)

Een resolutie kan ook buiten een vergadering om vastgesteld worden:

1. Maak in **Decisions** (corp-modus: Resolutions) een Decision aan met `decisionType=resolution`
   en category `written-resolution`.
2. Stuur de digitale handtekenverzoeken naar alle leden via de **eIDAS
   QES** flow (zie [Architecture — corporate governance](../Technical/board-portal-architecture.md#eidas-qualified-signatures)).
3. Zodra alle vereiste handtekeningen binnen zijn, wordt de resolutie
   automatisch als `approved` gemarkeerd.

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

Bestuursdocumenten zijn **DigitalDocument**-objecten (schema:DigitalDocument) verbonden
aan het bestuur of de vergadering. Ze worden beheerd via het OpenRegister-bestandsbeheer
(gekoppeld aan Nextcloud Files via `IRootFolder`). Toegang per document volgt de
Membership-gebaseerde RBAC van OpenRegister (ADR-022):

- `public` — iedereen met portal-toegang.
- `members-only` — alleen leden van dit bestuur (actief Membership).
- `committee-only` — alleen leden van de betreffende commissie.
- `executive-only` — alleen executive members (role=executive-member).
- `chair-only` — alleen voorzitter + vice-voorzitter (role=chairman / vice-chairman).

Iedere download wordt vastgelegd in het OpenRegister auditlog. Documenten
boven access-level `members-only` worden door docudesk gewatermerkt
geleverd (zie Architecture).

## 8. Hulp en troubleshooting

- Een transitie wordt geweigerd — controleer de huidige lifecycle-status van de vergadering of de Decision.
- "Decision not found" of "GovernanceBody not found" — de OpenRegister object-API
  filtert op leesrechten; je hebt waarschijnlijk geen actief Membership voor dit bestuur.
- "Quorum not met" — onvoldoende leden aanwezig (quorumMet=false op de Meeting).
- Voor verdere ondersteuning, zie de [admin runbook](../admin/board-portal-admin.md).
