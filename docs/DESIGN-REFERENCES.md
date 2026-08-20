# Decidesk -- Design References & Dashboard Wireframes

## 1. Design Inspiration Sources

### Dashboard / Landing Page
| Source | URL / Search | Key Patterns |
|--------|-------------|--------------|
| Dribbble | Search "governance dashboard" (200+ results) | KPI cards, decision status charts, upcoming meetings strips |
| OnBoard | onboardmeetings.com | Board portal dashboard with meeting countdown, action items, pending votes |
| Govrn | govrn.com | AI-powered board dashboard with decision tracking and document management |
| Behance | Search "meeting management dashboard" | Calendar-centric layouts, agenda previews, attendance widgets |
| Figma Community | Search "board portal dashboard kit" | Free component kits for governance metrics, voting panels |

### Meeting List & Detail
| Source | URL / Search | Key Patterns |
|--------|-------------|--------------|
| OnBoard Meeting View | onboardmeetings.com/board-portal | Meeting list with date, body, status badges, agenda item count |
| MinuteBox | minutebox.com/product/board-portal | Meeting detail with agenda builder, attendee list, document packs |
| Aprio Board Portal | aprioboardportal.com | Meeting detail with inline voting, resolution tracking, quorum display |
| Dribbble "meeting detail UI" | Search Dribbble | Split-panel meeting views with sidebar for files and notes |
| Nextcloud Calendar | apps.nextcloud.com/apps/calendar | Familiar Nextcloud date/time patterns, recurring event support |

### Decision Board (Kanban-style by Status)
| Source | URL / Search | Key Patterns |
|--------|-------------|--------------|
| Nextcloud Deck | apps.nextcloud.com/apps/deck | Board/Stack/Card kanban -- familiar Nextcloud drag-and-drop UX |
| Trello | trello.com | Gold standard kanban: minimal cards, smooth drag-and-drop |
| Kanban Zone | kanbanzone.com/templates/team-meeting-agenda | Meeting agenda kanban with decision and action columns |
| Dribbble "decision tracking board" | Search Dribbble | Status-based columns with color-coded decision cards |
| Figma "kanban board" | figma.com/templates/kanban-board-example | Reusable kanban templates with card anatomy patterns |

### Voting Interface
| Source | URL / Search | Key Patterns |
|--------|-------------|--------------|
| Aprio Board Voting | aprioboardportal.com/features/board-voting-software | One-click For/Against/Abstain, real-time tallies, outcome locks |
| Boardlogic (Praxonomy) | praxonomy.com/board-portal-features/voting-and-approvals | Inline voting with motion text, quorum indicator, deadline nudges |
| OnBoard Voting | onboardmeetings.com/board-portal/voting-and-approvals | Mobile voting with clear deadlines, automatic tally updates |
| Parliament Module | academia.edu (Parliament module paper) | Robert's Rules state machine, motion lifecycle, amendment tracking |
| ParlTrack | parltrack.org | EU Parliament vote tracking, roll-call display, dossier monitoring |

### Agenda Management
| Source | URL / Search | Key Patterns |
|--------|-------------|--------------|
| MinuteBox Agenda Builder | minutebox.com | Drag-reorder agenda items, time allocation per item, document linking |
| Aprio Agenda | aprioboardportal.com | Agenda with item types (info/discussion/decision), presenter assignment |
| Kanban Zone Meeting Template | kanbanzone.com | Visual agenda board with background/discussion/decision columns |
| Dribbble "agenda builder UI" | Search Dribbble | Timeline-style agenda with duration bars, drag handles, type badges |
| Teamly Meeting Agenda | teamly.com/templates | Action items and agreements columns alongside agenda topics |

### Minutes & Decision/Resolution View
| Source | URL / Search | Key Patterns |
|--------|-------------|--------------|
| MinuteBox Minutes | minutebox.com | Auto-generated minutes from agenda + decisions, signature workflow |
| OnBoard Minutes | onboardmeetings.com | Minutes linked to agenda items, resolution numbering, approval tracking |
| Ideals Board | idealsboard.com | Document-centric resolution view with version history and annotations |
| Dribbble "meeting minutes UI" | Search Dribbble | Structured minutes with decision highlights and action item extraction |

> **Note (ADR-005):** Resolutions in Decidesk are Decision objects with `decisionType=resolution`. Design patterns for resolution views apply to the universal Decision detail view in mode=corp.

### Admin Settings / Process Configuration
| Source | URL / Search | Key Patterns |
|--------|-------------|--------------|
| Nextcloud Admin Settings | Nextcloud core | Settings sections with description, grouped by category |
| Pipelinq Admin | Pipelinq app | Pipeline stage editor, source/channel management, default configuration |
| OnBoard Admin | onboardmeetings.com | Body/committee management, role permissions, voting method config |
| Govrn Admin | govrn.com | Organization setup, compliance templates, workflow configuration |

---

## 2. Missing Features Identified from Design Patterns

Features not currently in FEATURES.md but commonly present in governance and board portal dashboards:

### MVP Additions
| Feature | Source Pattern | Justification |
|---------|--------------|---------------|
| Decision board toggle (kanban / list) | Nextcloud Deck, Trello, OnBoard | Users need both visual (kanban) and data-dense (list) views of decisions |
| Decision card quick actions | OnBoard, Aprio | Change status, assign rapporteur, set priority without opening detail |
| Quorum indicator on meeting detail | Aprio, Boardlogic | Real-time quorum tracking is essential for valid decision-making |
| Agenda item time allocation | MinuteBox, Aprio | Duration estimates per item help chairs manage meeting time |

### V1 Additions
| Feature | Source Pattern | Justification |
|---------|--------------|---------------|
| Stale decision detection (no activity for X days) | Board portal best practices | Highlights forgotten decisions with "last activity" indicator |
| Vote result summary per column header | Aprio, OnBoard | Decision board columns show count and pass/fail ratio |
| Amendment tracking on decisions | Parliament module, ParlTrack | Track motions, amendments, and sub-votes within a single decision |
| Speaking time management | Parliamentary procedure | Timer-based speaking allocation with alerts for chair and speakers |
| Auto-generated minutes from agenda + votes | MinuteBox, OnBoard | Structured minutes assembled from agenda items, decisions, and action items |

### Enterprise Additions
| Feature | Source Pattern | Justification |
|---------|--------------|---------------|
| Multi-body governance hierarchy | Large municipalities | Parent/child body relationships (e.g., council > committee > workgroup) |
| Compliance audit trail export | Govrn, Ideals Board | Export full decision history with votes, documents, and timestamps for audit |
| Delegation and proxy voting | Parliamentary procedure | Allow members to delegate votes to proxies with configurable rules |
| Public transparency portal | ParlTrack, Open Government | Public-facing view of decisions, votes, and minutes for transparency |
| Integration with document signing | MinuteBox | Digital signature workflow for approved resolutions |

---

## 3. Dashboard Wireframes

### 3.1 Main Dashboard

```
+-----------------------------------------------------------------------+
|  DECIDESK                                            [Search...] [+]  |
+----------+----------+----------+----------+----------+----------------+
| Dashboard| Meetings | Decisions|  Agenda  |  My Work |    Admin       |
+----------+----------+----------+----------+----------+----------------+
|                                                                       |
|  +---------------+ +---------------+ +---------------+ +------------+ |
|  | ACTIVE        | | UPCOMING      | | MY VOTES      | | OVERDUE    | |
|  | DECISIONS     | | MEETINGS      | | PENDING       | | ACTIONS    | |
|  |               | |               | |               | |            | |
|  |      14       | |       3       | |       5       | |      2     | |
|  |  +3 this week | |  next: 2 days | |  due today: 2 | |  ! urgent  | |
|  +---------------+ +---------------+ +---------------+ +------------+ |
|                                                                       |
|  +----------------------------------+ +----------------------------+  |
|  | Recent Decisions                 | | Upcoming Meetings          |  |
|  |                                  | |                            |  |
|  | * Bestemmingsplan Westpark       | | Mar 27  Raadsvergadering   |  |
|  |   Gemeenteraad Leiden            | |         Gemeenteraad       |  |
|  |   Status: On Agenda              | |         Leiden             |  |
|  |   Vote: scheduled Mar 27         | |         12 agenda items    |  |
|  |                                  | |                            |  |
|  | * Klimaatbegroting 2027          | | Apr 3   Commissie Ruimte   |  |
|  |   Commissie Financien            | |         & Wonen            |  |
|  |   Status: Debated                | |         8 agenda items     |  |
|  |   Vote: 8 for / 5 against       | |                            |  |
|  |                                  | | Apr 10  Raadsvergadering   |  |
|  | * Verordening reclamebelasting   | |         Gemeenteraad       |  |
|  |   Gemeenteraad Leiden            | |         Leiden             |  |
|  |   Status: Approved               | |         (not yet set)      |  |
|  |   Vote: unanimous                | |                            |  |
|  |                                  | | [View calendar ->]         |  |
|  | [View all decisions ->]          | +----------------------------+  |
|  +----------------------------------+                                 |
|                                                                       |
|  +----------------------------------------------------------------+   |
|  | My Action Items                                                 |   |
|  |                                                                 |   |
|  | ! Prepare advies Bestemmingsplan Westpark       Due: Mar 26     |   |
|  |   Assigned by: Voorzitter Commissie Ruimte      OVERDUE         |   |
|  |                                                                 |   |
|  | * Review amendement klimaatbegroting             Due: Apr 1     |   |
|  |   Assigned by: Griffier                          5 days left    |   |
|  |                                                                 |   |
|  | [View all my work ->]                                           |   |
|  +----------------------------------------------------------------+   |
|                                                                       |
+-----------------------------------------------------------------------+
```

### 3.2 Meeting List View

```
+-----------------------------------------------------------------------+
|  DECIDESK > Meetings                          [+ New Meeting] [Filter] |
+----------+----------+----------+----------+----------+----------------+
| Dashboard| Meetings | Decisions|  Agenda  |  My Work |    Admin       |
+----------+----------+----------+----------+----------+----------------+
|                                                                       |
| Body: [Alle organen v]  Status: [Alle v]  Period: [Dit kwartaal v]    |
|                                                                       |
| +---+------------+-------------------+----------+---------+----------+|
| |   | Date       | Title             | Body     | Status  | Agenda   ||
| +---+------------+-------------------+----------+---------+----------+|
| |   | 2026-03-27 | Raadsvergadering  | Gemeente-| Planned | 12 items ||
| |   | 19:30      | 27 maart 2026     | raad     |         |          ||
| |   |            |                   | Leiden   |         | Chair:   ||
| |   |            |                   |          |         | dhr. Smit||
| +---+------------+-------------------+----------+---------+----------+|
| |   | 2026-04-03 | Commissievergade- | Commissie| Draft   | 8 items  ||
| |   | 14:00      | ring Ruimte &     | Ruimte & |         |          ||
| |   |            | Wonen             | Wonen    |         | Chair:   ||
| |   |            |                   |          |         | mw. Vos  ||
| +---+------------+-------------------+----------+---------+----------+|
| |   | 2026-04-10 | Raadsvergadering  | Gemeente-| Draft   | --       ||
| |   | 19:30      | 10 april 2026     | raad     |         |          ||
| |   |            |                   | Leiden   |         | Chair:   ||
| |   |            |                   |          |         | dhr. Smit||
| +---+------------+-------------------+----------+---------+----------+|
| |   | 2026-03-20 | Raadsvergadering  | Gemeente-| Minutes | 15 items ||
| |   | 19:30      | 20 maart 2026     | raad     | Final   |          ||
| |   |            |                   | Leiden   |         | Chair:   ||
| |   |            |                   |          |         | dhr. Smit||
| +---+------------+-------------------+----------+---------+----------+|
|                                                                       |
|  Showing 4 meetings  |  Planned: 1  Draft: 2  Completed: 1           |
|                                                                       |
+-----------------------------------------------------------------------+
```

### 3.3 Meeting Detail View

```
+-----------------------------------------------------------------------+
|  DECIDESK > Meetings > Raadsvergadering 27 maart 2026   [Edit] [...] |
+-----------------------------------------------------------------------+
|                                                                       |
|  +--------------------------------+ +-------------------------------+ |
|  | MEETING INFO                   | | ATTENDEES         23/39      | |
|  |                                | |                   aanwezig   | |
|  | Title:  Raadsvergadering       | |                              | |
|  |         27 maart 2026          | | Quorum: 20  [=====>  ] OK    | |
|  | Body:   Gemeenteraad Leiden    | |                              | |
|  | Date:   27-03-2026  19:30      | | * dhr. Smit (voorzitter)     | |
|  | Chair:  dhr. A. Smit           | | * mw. Jansen (VVD)          | |
|  | Clerk:  mw. B. de Groot        | | * dhr. Bakker (D66)         | |
|  | Status: Planned                | | * mw. Visser (GroenLinks)   | |
|  | Location: Raadszaal            | | * dhr. de Vries (PvdA)      | |
|  |                                | | * ... 18 more               | |
|  +--------------------------------+ |                              | |
|                                     | Absent: 16                   | |
|  +--------------------------------+ | * dhr. Mulder (CDA) [afm]   | |
|  | AGENDA ITEMS                   | | * mw. Peters (SP) [afm]     | |
|  |                                | | * ... 14 more               | |
|  | = 1. Opening en mededelingen   | +-------------------------------+|
|  |   [info]  5 min  19:30-19:35  |                                 |
|  |                                | +-------- SIDEBAR ------------+ |
|  | = 2. Vaststelling agenda       | |                              | |
|  |   [decision]  5 min  19:35    | | [Files] [Notes] [Tasks]      | |
|  |                                | | [Audit Trail]                | |
|  | = 3. Bestemmingsplan Westpark  | |                              | |
|  |   [decision]  45 min  19:40   | | FILES                        | |
|  |   -> Decision #42             | | * Raadsvoorstel Westpark.pdf | |
|  |   2 amendments                | | * Bijlage 1 - Kaart.pdf     | |
|  |                                | | * Bijlage 2 - Zienswijzen   | |
|  | = 4. Klimaatbegroting 2027    | |                              | |
|  |   [discussion]  30 min  20:25 | | NOTES                        | |
|  |   -> Decision #38             | | * Griffie notitie 25-03     | |
|  |                                | |                              | |
|  | = 5. Rondvraag en sluiting    | | AUDIT TRAIL                  | |
|  |   [info]  15 min  20:55       | | Mar 25 - Agenda finalized    | |
|  |                                | | Mar 22 - Item 3 added        | |
|  | Total: 12 items  ~100 min     | | Mar 20 - Meeting created     | |
|  | Estimated end: 21:10          | |                              | |
|  +--------------------------------+ +------------------------------+ |
|                                                                       |
+-----------------------------------------------------------------------+
```

### 3.4 Decision Board (Kanban)

```
+-----------------------------------------------------------------------+
|  DECIDESK > Decisions                    [Kanban | List]  [Filter]    |
+----------+----------+----------+----------+----------+----------------+
| Dashboard| Meetings | Decisions|  Agenda  |  My Work |    Admin       |
+----------+----------+----------+----------+----------+----------------+
|                                                                       |
| Body: [Gemeenteraad Leiden v]  Category: [Alle v]   [+ New Decision]  |
|                                                                       |
| +---------+ +---------+ +---------+ +---------+ +---------+ +------+ |
| | DRAFT   | |SUBMITTED| |ON AGENDA| | DEBATED | |  VOTED  | |APPRO-| |
| | 3 items | | 2 items | | 4 items | | 2 items | | 1 item  | |VED   | |
| |---------| |---------| |---------| |---------| |---------| | 5    | |
| |+-------+| |+-------+| |+-------+| |+-------+| |+-------+| |      | |
| || Nota  || || Veror- || ||Bestem-|| ||Klimaat|| ||Parkeer|| |+----+| |
| || sport-|| || dening || ||mings- || ||begro- || ||beleid || ||Veror|| |
| || beleid|| || markt- || ||plan   || ||ting   || ||2027  || ||deni-|| |
| ||       || || kramen || ||West-  || ||2027   || ||       || ||ng   || |
| || Gem.- || ||       || ||park   || ||       || || Gem.- || ||recla|| |
| || raad  || || Gem.- || ||       || || Comm. || || raad  || ||me   || |
| ||       || || raad  || || Gem.- || || Fin.  || ||       || |+----+| |
| || cat:  || ||       || || raad  || ||       || ||Voted: || |      | |
| || Sport || || cat:  || ||       || || cat:  || ||12-8-3 || |[...]3| |
| ||       || || Handel|| || cat:  || || Fin.  || ||Approve|| | more | |
| |+-------+| |+-------+| || Ruimt.|| ||       || |+-------+| |      | |
| |+-------+| |+-------+| || Ordel.|| || Due:  || |         | +------+ |
| || Reno- || || Budget || ||       || || Apr 10|| |         | +------+ |
| || vatie || || jeugd- || || Vote: || |+-------+| |         | |REJEC-| |
| || stad- || || zorg  || || Mar 27|| |+-------+| |         | |TED   | |
| || huis || ||       || || 2 amen|| ||Veror- || |         | | 2    | |
| ||       || || Comm. || ||dement-|| ||dening || |         | |      | |
| || cat:  || || Soc.  || ||en    || ||OZB   || |         | |[...]1| |
| || Infra || || Zaken || |+-------+| ||       || |         | | more | |
| |+-------+| |+-------+| |+-------+| || Gem.- || |         | |      | |
| |+-------+| |         | || ...   || || raad  || |         | +------+ |
| || ...   || |         | || 2 more|| ||       || |         |          |
| |+-------+| |         | |+-------+| || Due:  || |         |          |
| |         | |         | |         | || Apr 10|| |         |          |
| | [+ Add] | | [+ Add] | | [+ Add] | |+-------+| |         |          |
| +---------+ +---------+ +---------+ +---------+ +---------+          |
|                                                                       |
+-----------------------------------------------------------------------+
```

**Decision card anatomy:**
```
+--------------------+
| Bestemmingsplan    |  <- Title (clickable -> detail view)
| Westpark           |
|                    |
| Gemeenteraad       |  <- Body name
| Leiden             |
|                    |
| cat: Ruimt. Ordel. |  <- Category badge
|                    |
| Proposer: Wethouder|  <- Proposer
| Ruimte             |
|                    |
| Vote: Mar 27       |  <- Scheduled vote date
| 2 amendementen     |  <- Amendment count
| ! Due in 1 day     |  <- Due date warning
+--------------------+
```

### 3.5 Decision Detail View

```
+-----------------------------------------------------------------------+
|  DECIDESK > Decisions > Bestemmingsplan Westpark         [Edit] [...] |
+-----------------------------------------------------------------------+
|                                                                       |
|  +----------------------------------+ +------- SIDEBAR -----------+   |
|  | DECISION INFO                    | |                            |   |
|  |                                  | | [Dossier] [Notes] [Tasks] |   |
|  | Title:    Bestemmingsplan        | | [Audit Trail]              |   |
|  |           Westpark               | |                            |   |
|  | Status:   On Agenda              | | DOSSIER FILES              |   |
|  | Body:     Gemeenteraad Leiden    | | * Raadsvoorstel.pdf        |   |
|  | Category: Ruimtelijke Ordening   | | * Bestemmingsplan v3.pdf   |   |
|  | Proposer: Wethouder Ruimte       | | * Zienswijzennota.pdf      |   |
|  | Rapporteur: mw. Visser           | | * Advies Comm. Ruimte.pdf  |   |
|  | Created:  2026-02-15             | | [+ Upload]                 |   |
|  | Due:      2026-03-27             | |                            |   |
|  +----------------------------------+ | DISCUSSION NOTES           |   |
|                                       | * Commissiebehandeling     |   |
|  +----------------------------------+ |   15-03: positief advies   |   |
|  | STATE MACHINE PROGRESS           | | * Inspraakreactie bewoners |   |
|  |                                  | |   10-03: 12 zienswijzen   |   |
|  | [*] Draft                        | | [+ Add note]               |   |
|  |  |                               | |                            |   |
|  | [*] Submitted                    | | TASKS                      |   |
|  |  |                               | | * Prepare amendement-      |   |
|  | [*] On Agenda  <- current        | |   reactie (mw. Visser)     |   |
|  |  |                               | |   Due: Mar 26              |   |
|  | [ ] Debated                      | | * Verwerk zienswijzen      |   |
|  |  |                               | |   (griffie) Done           |   |
|  | [ ] Voted                        | |                            |   |
|  |  |                               | | AUDIT TRAIL                |   |
|  | [ ] Approved / Rejected          | | Mar 22 - Added to agenda   |   |
|  |  |                               | |   Raadsvergadering 27-03  |   |
|  | [ ] Implemented                  | | Mar 15 - Comm. advies:     |   |
|  |                                  | |   positief                 |   |
|  | Meeting: Raadsvergadering        | | Mar 10 - Submitted by      |   |
|  |          27 maart 2026           | |   Wethouder Ruimte         |   |
|  | [Move to Debated ->]             | | Feb 15 - Draft created     |   |
|  +----------------------------------+ +----------------------------+   |
|                                                                       |
|  +----------------------------------------------------------------+   |
|  | MOTIONS & AMENDMENTS                              [+ Add Motion] |   |
|  |                                                                 |   |
|  | +-------------------------------------------------------------+|   |
|  | | #1  Amendement klimaatbegroting                  Status: Open ||   |
|  | |     Indiener: Fractie GroenLinks                             ||   |
|  | |     "Toevoegen van paragraaf 3.4 over klimaatadaptieve       ||   |
|  | |      maatregelen in het plangebied Westpark"                 ||   |
|  | |     [View full text]                          [Start Vote]   ||   |
|  | +-------------------------------------------------------------+|   |
|  |                                                                 |   |
|  | +-------------------------------------------------------------+|   |
|  | | #2  Amendement parkeerplaatsen                Status: Open   ||   |
|  | |     Indiener: Fractie VVD                                   ||   |
|  | |     "Wijzigen van artikel 5.2: minimum 1.5 parkeerplaats    ||   |
|  | |      per woning in plaats van 1.0"                           ||   |
|  | |     [View full text]                          [Start Vote]   ||   |
|  | +-------------------------------------------------------------+|   |
|  +----------------------------------------------------------------+   |
|                                                                       |
|  +----------------------------------------------------------------+   |
|  | VOTING HISTORY                                                  |   |
|  |                                                                 |   |
|  | No votes recorded yet. Vote scheduled for 27 maart 2026.       |   |
|  +----------------------------------------------------------------+   |
|                                                                       |
|  +----------------------------------------------------------------+   |
|  | RELATED DECISIONS                                               |   |
|  |                                                                 |   |
|  | * Klimaatbegroting 2027 (Debated) -- same meeting               |   |
|  | * Nota Grondbeleid 2025 (Approved) -- same category             |   |
|  +----------------------------------------------------------------+   |
|                                                                       |
+-----------------------------------------------------------------------+
```

### 3.6 Voting Interface

**Active Vote -- Roll Call:**
```
+-----------------------------------------------------------------------+
|  DECIDESK > LIVE VOTE                                    [End Vote]   |
+-----------------------------------------------------------------------+
|                                                                       |
|  +----------------------------------------------------------------+   |
|  | MOTION                                                          |   |
|  |                                                                 |   |
|  | Amendement klimaatbegroting (#1)                                |   |
|  | on: Bestemmingsplan Westpark                                    |   |
|  |                                                                 |   |
|  | "Toevoegen van paragraaf 3.4 over klimaatadaptieve              |   |
|  |  maatregelen in het plangebied Westpark"                        |   |
|  |                                                                 |   |
|  | Indiener: Fractie GroenLinks                                    |   |
|  +----------------------------------------------------------------+   |
|                                                                       |
|  +--------------------+  Quorum: 20  Present: 23/39                   |
|  |   VOTE RESULTS     |  [========================] OK                |
|  |                     |                                              |
|  |  FOR:        12     |  Timer: [02:34] remaining                    |
|  |  AGAINST:     8     |                                              |
|  |  ABSTAIN:     2     |  Method: Roll Call (hoofdelijke stemming)    |
|  |  NOT VOTED:   1     |                                              |
|  |                     |  Majority required: Simple (>50%)             |
|  |  Status: OPEN       |  Threshold: 12 of 23                         |
|  +--------------------+                                               |
|                                                                       |
|  +----------------------------------------------------------------+   |
|  | ROLL CALL                                                       |   |
|  |                                                                 |   |
|  | Name                 | Party       | Vote                       |   |
|  | ---------------------|-------------|--------------------------- |   |
|  | dhr. Bakker          | D66         | [FOR]                      |   |
|  | mw. Jansen           | VVD         | [AGAINST]                  |   |
|  | mw. Visser           | GroenLinks  | [FOR]                      |   |
|  | dhr. de Vries        | PvdA        | [FOR]                      |   |
|  | dhr. Hendriks        | CDA         | [ABSTAIN]                  |   |
|  | mw. van den Berg     | SP          | [FOR]                      |   |
|  | dhr. Mulder          | VVD         | [AGAINST]                  |   |
|  | mw. Scholten         | D66         | [FOR]                      |   |
|  | dhr. Willems         | PvdA        | [--]  <- not yet voted     |   |
|  | ...                  |             |                            |   |
|  |                                                                 |   |
|  | [Show all 23 members]                                           |   |
|  +----------------------------------------------------------------+   |
|                                                                       |
|  My Vote:  [ FOR ]  [ AGAINST ]  [ ABSTAIN ]                         |
|                                                                       |
+-----------------------------------------------------------------------+
```

**Active Vote -- Secret Ballot:**
```
+-----------------------------------------------------------------------+
|  DECIDESK > LIVE VOTE                                    [End Vote]   |
+-----------------------------------------------------------------------+
|                                                                       |
|  +----------------------------------------------------------------+   |
|  | MOTION: Benoeming wethouder Financien                           |   |
|  | Method: Secret Ballot (geheime stemming)                        |   |
|  +----------------------------------------------------------------+   |
|                                                                       |
|  +--------------------+  Quorum: 20  Present: 35/39                   |
|  |   VOTE RESULTS     |  [============================] OK           |
|  |                     |                                              |
|  |  FOR:        22     |  Timer: [05:00] remaining                    |
|  |  AGAINST:    10     |                                              |
|  |  ABSTAIN:     1     |  Majority required: Absolute (>50% of seats)|
|  |  NOT VOTED:   2     |  Threshold: 20 of 39                         |
|  |                     |                                              |
|  |  Status: OPEN       |  NOTE: Individual votes are not disclosed.   |
|  +--------------------+  Results show totals only.                    |
|                                                                       |
|  +----------------------------------------------------------------+   |
|  |  Voted: 33/35 present members                                   |   |
|  |  [=================================  ] 94%                      |   |
|  |                                                                 |   |
|  |  Waiting for 2 more votes...                                    |   |
|  +----------------------------------------------------------------+   |
|                                                                       |
|  My Vote:  [ FOR ]  [ AGAINST ]  [ ABSTAIN ]                         |
|            (your vote is anonymous)                                    |
|                                                                       |
+-----------------------------------------------------------------------+
```

**Active Vote -- Weighted Voting:**
```
+-----------------------------------------------------------------------+
|  DECIDESK > LIVE VOTE                                    [End Vote]   |
+-----------------------------------------------------------------------+
|                                                                       |
|  +----------------------------------------------------------------+   |
|  | MOTION: Begrotingswijziging Gemeenschappelijke Regeling         |   |
|  | Method: Weighted Vote (gewogen stemming)                        |   |
|  +----------------------------------------------------------------+   |
|                                                                       |
|  +------------------------+  Quorum: 60% of total weight              |
|  |   WEIGHTED RESULTS     |  Total weight: 1000                       |
|  |                        |  Present weight: 850                      |
|  |  FOR:     520 (61.2%)  |  [========================] OK            |
|  |  AGAINST: 280 (32.9%)  |                                           |
|  |  ABSTAIN:  50 ( 5.9%)  |  Majority required: >50% of present wt.  |
|  |                        |  Threshold: 426 of 850                    |
|  |  Status: OPEN          |                                           |
|  +------------------------+                                           |
|                                                                       |
|  +----------------------------------------------------------------+   |
|  | DELEGATIONS                                                     |   |
|  |                                                                 |   |
|  | Municipality    | Weight | Representative    | Vote              |   |
|  | ----------------|--------|-------------------|------------------ |   |
|  | Leiden          |    250 | dhr. Bakker       | [FOR]  250 wt.   |   |
|  | Oegstgeest      |     80 | mw. Jansen        | [FOR]   80 wt.   |   |
|  | Leiderdorp      |    120 | dhr. de Vries     | [AGAINST] 120 wt.|   |
|  | Voorschoten     |     60 | mw. Visser        | [FOR]   60 wt.   |   |
|  | Zoeterwoude     |     40 | dhr. Mulder       | [ABSTAIN] 40 wt. |   |
|  | ...             |        |                   |                   |   |
|  +----------------------------------------------------------------+   |
|                                                                       |
|  My Vote (weight: 250):  [ FOR ]  [ AGAINST ]  [ ABSTAIN ]           |
|                                                                       |
+-----------------------------------------------------------------------+
```

### 3.7 Agenda Management

```
+-----------------------------------------------------------------------+
|  DECIDESK > Meetings > Raadsvergadering 27 maart > Agenda    [Save]  |
+-----------------------------------------------------------------------+
|                                                                       |
| Meeting: Raadsvergadering 27 maart 2026                               |
| Total items: 12  |  Est. duration: 100 min  |  Start: 19:30          |
| Est. end: 21:10  |  Buffer: 10 min                                   |
|                                                                       |
| +---+------+------+-------------------------------------------+----+ |
| |   | #    | Time | Item                                      |Type| |
| +---+------+------+-------------------------------------------+----+ |
| | = |  1   | 5m   | Opening en mededelingen                   |INFO| |
| |   |      |19:30 | Chair: dhr. Smit                          |    | |
| +---+------+------+-------------------------------------------+----+ |
| | = |  2   | 5m   | Vaststelling agenda                       |DEC | |
| |   |      |19:35 |                                           |    | |
| +---+------+------+-------------------------------------------+----+ |
| | = |  3   | 45m  | Bestemmingsplan Westpark                  |DEC | |
| |   |      |19:40 | -> Decision #42  |  2 amendments          |    | |
| |   |      |      | Docs: Raadsvoorstel.pdf + 2 bijlagen      |    | |
| |   |      |      | Speaker: Wethouder Ruimte (15 min)        |    | |
| +---+------+------+-------------------------------------------+----+ |
| | = |  4   | 30m  | Klimaatbegroting 2027                     |DISC| |
| |   |      |20:25 | -> Decision #38                           |    | |
| |   |      |      | Docs: Nota klimaatbegroting.pdf           |    | |
| |   |      |      | Speaker: Wethouder Financien (10 min)     |    | |
| +---+------+------+-------------------------------------------+----+ |
| | = |  5   | 5m   | Vaststelling notulen 20 maart 2026        |DEC | |
| |   |      |20:55 | Docs: Notulen_20-03-2026_concept.pdf      |    | |
| +---+------+------+-------------------------------------------+----+ |
| | = |  6   | 3m   | Ingekomen stukken                         |INFO| |
| |   |      |21:00 | 8 ingekomen stukken  [View list]          |    | |
| +---+------+------+-------------------------------------------+----+ |
| | = |  7   | 2m   | Hamerstukken                              |DEC | |
| |   |      |21:03 | 3 hamerstukken  [View list]               |    | |
| +---+------+------+-------------------------------------------+----+ |
| | = |  8   | 5m   | Rondvraag en sluiting                     |INFO| |
| |   |      |21:05 |                                           |    | |
| +---+------+------+-------------------------------------------+----+ |
|                                                                       |
| Legend:  INFO = Informational  DISC = Discussion  DEC = Decision      |
| Drag = to reorder items  |  [+ Add Agenda Item]                      |
|                                                                       |
+-----------------------------------------------------------------------+
```

### 3.8 My Work View

```
+-----------------------------------------------------------------------+
|  DECIDESK > My Work                                 [Filter v] [Sort] |
+----------+----------+----------+----------+----------+----------------+
| Dashboard| Meetings | Decisions|  Agenda  |  My Work |    Admin       |
+----------+----------+----------+----------+----------+----------------+
|                                                                       |
|  Showing: [All v]  Votes (5) * Actions (3) * Meetings (2) * Motions  |
|                                                                       |
|  +----------------------------------------------------------------+   |
|  | MY PENDING VOTES                                          5     |   |
|  |                                                                 |   |
|  | +-------------------------------------------------------------+|   |
|  | | Bestemmingsplan Westpark                        Due: Mar 27  ||   |
|  | | Gemeenteraad Leiden  |  Method: Roll Call                   ||   |
|  | | Amendement #1: klimaatadaptatie  [Vote Now ->]               ||   |
|  | +-------------------------------------------------------------+|   |
|  |                                                                 |   |
|  | +-------------------------------------------------------------+|   |
|  | | Bestemmingsplan Westpark                        Due: Mar 27  ||   |
|  | | Gemeenteraad Leiden  |  Method: Roll Call                   ||   |
|  | | Amendement #2: parkeerplaatsen   [Vote Now ->]               ||   |
|  | +-------------------------------------------------------------+|   |
|  |                                                                 |   |
|  | +-------------------------------------------------------------+|   |
|  | | Bestemmingsplan Westpark                        Due: Mar 27  ||   |
|  | | Gemeenteraad Leiden  |  Method: Roll Call                   ||   |
|  | | Hoofdvoorstel                    [Vote Now ->]               ||   |
|  | +-------------------------------------------------------------+|   |
|  |                                                                 |   |
|  | [Show 2 more pending votes...]                                  |   |
|  +----------------------------------------------------------------+   |
|                                                                       |
|  +----------------------------------------------------------------+   |
|  | MY ACTION ITEMS                                           3     |   |
|  |                                                                 |   |
|  | +-------------------------------------------------------------+|   |
|  | | ! Prepare advies Bestemmingsplan Westpark      OVERDUE       ||   |
|  | |   Assigned by: Voorzitter Commissie Ruimte     Due: Mar 26  ||   |
|  | |   Decision: Bestemmingsplan Westpark (#42)                   ||   |
|  | +-------------------------------------------------------------+|   |
|  |                                                                 |   |
|  | +-------------------------------------------------------------+|   |
|  | | Review amendement klimaatbegroting              5 days left  ||   |
|  | |   Assigned by: Griffier                        Due: Apr 1   ||   |
|  | |   Decision: Klimaatbegroting 2027 (#38)                     ||   |
|  | +-------------------------------------------------------------+|   |
|  |                                                                 |   |
|  | +-------------------------------------------------------------+|   |
|  | | Voorbereiden spreektekst OZB                    12 days left ||   |
|  | |   Assigned by: Fractievoorzitter               Due: Apr 8   ||   |
|  | |   Decision: Verordening OZB 2027 (#51)                      ||   |
|  | +-------------------------------------------------------------+|   |
|  +----------------------------------------------------------------+   |
|                                                                       |
|  +----------------------------------------------------------------+   |
|  | UPCOMING MEETINGS                                         2     |   |
|  |                                                                 |   |
|  | +-------------------------------------------------------------+|   |
|  | | Mar 27  19:30  Raadsvergadering 27 maart 2026               ||   |
|  | | Gemeenteraad Leiden  |  12 agenda items  |  23/39 aanwezig  ||   |
|  | +-------------------------------------------------------------+|   |
|  |                                                                 |   |
|  | +-------------------------------------------------------------+|   |
|  | | Apr 3   14:00  Commissievergadering Ruimte & Wonen          ||   |
|  | | Commissie Ruimte & Wonen  |  8 agenda items                 ||   |
|  | +-------------------------------------------------------------+|   |
|  +----------------------------------------------------------------+   |
|                                                                       |
|  +----------------------------------------------------------------+   |
|  | MY MOTIONS                                               1      |   |
|  |                                                                 |   |
|  | +-------------------------------------------------------------+|   |
|  | | Amendement klimaatbegroting                    Status: Open  ||   |
|  | | on: Bestemmingsplan Westpark                                 ||   |
|  | | Scheduled vote: Mar 27  |  Gemeenteraad Leiden               ||   |
|  | +-------------------------------------------------------------+|   |
|  +----------------------------------------------------------------+   |
|                                                                       |
+-----------------------------------------------------------------------+
```

### 3.9 Admin Settings

```
+-----------------------------------------------------------------------+
|  Administration > Decidesk                                             |
+-----------------------------------------------------------------------+
|                                                                       |
|  +----------------------------------------------------------------+   |
|  | VERSION INFO                                  Decidesk v0.1.0  |   |
|  |                                                                 |   |
|  | Nextcloud 30.0.0  |  PHP 8.3  |  Database: PostgreSQL 16       |   |
|  | License: EUPL-1.2   |  Author: ConductionNL                    |   |
|  | [Documentation]  [Report Issue]  [GitHub]                       |   |
|  +----------------------------------------------------------------+   |
|                                                                       |
|  +----------------------------------------------------------------+   |
|  | ORGANIZATION CONFIG                                             |   |
|  |                                                                 |   |
|  | Organization name: [Gemeente Leiden                          ]  |   |
|  | Default language:  [Nederlands v]                               |   |
|  | Fiscal year start: [January v]                                  |   |
|  | Decision numbering: [YYYY-NNN v]  (e.g., 2026-042)             |   |
|  |                                                                 |   |
|  | [Save]                                                          |   |
|  +----------------------------------------------------------------+   |
|                                                                       |
|  +----------------------------------------------------------------+   |
|  | BODY MANAGEMENT                                  [+ Add Body]   |   |
|  |                                                                 |   |
|  | +-------------------------------------------------------------+|   |
|  | | * Gemeenteraad Leiden (default)          39 seats    [Edit]  ||   |
|  | |   Type: Council  |  Quorum: 20  |  Chair: dhr. A. Smit      ||   |
|  | |   Clerk: mw. B. de Groot                                    ||   |
|  | |   Meeting frequency: Bi-weekly Thursday 19:30                ||   |
|  | +-------------------------------------------------------------+|   |
|  |                                                                 |   |
|  | +-------------------------------------------------------------+|   |
|  | | Commissie Ruimte & Wonen                 15 seats    [Edit]  ||   |
|  | |   Type: Committee  |  Quorum: 8  |  Chair: mw. C. Vos       ||   |
|  | |   Parent body: Gemeenteraad Leiden                           ||   |
|  | +-------------------------------------------------------------+|   |
|  |                                                                 |   |
|  | +-------------------------------------------------------------+|   |
|  | | Commissie Financien                      15 seats    [Edit]  ||   |
|  | |   Type: Committee  |  Quorum: 8  |  Chair: dhr. D. Bakker   ||   |
|  | |   Parent body: Gemeenteraad Leiden                           ||   |
|  | +-------------------------------------------------------------+|   |
|  |                                                                 |   |
|  | +-------------------------------------------------------------+|   |
|  | | Commissie Sociaal & Maatschappelijk      15 seats    [Edit]  ||   |
|  | |   Type: Committee  |  Quorum: 8  |  Chair: mw. E. Peters    ||   |
|  | |   Parent body: Gemeenteraad Leiden                           ||   |
|  | +-------------------------------------------------------------+|   |
|  +----------------------------------------------------------------+   |
|                                                                       |
|  +----------------------------------------------------------------+   |
|  | PROCESS TEMPLATES (State Machine)             [+ Add Template]  |   |
|  |                                                                 |   |
|  | +-------------------------------------------------------------+|   |
|  | | * Default Decision Process                           [Edit]  ||   |
|  | |                                                             ||   |
|  | |   Draft -> Submitted -> On Agenda -> Debated -> Voted ->    ||   |
|  | |   Approved / Rejected -> Implemented                        ||   |
|  | |                                                             ||   |
|  | |   7 states  |  8 transitions  |  Used by: 14 decisions      ||   |
|  | +-------------------------------------------------------------+|   |
|  |                                                                 |   |
|  | +-------------------------------------------------------------+|   |
|  | | Hamerstuk (Consent Agenda)                           [Edit]  ||   |
|  | |                                                             ||   |
|  | |   Draft -> Submitted -> On Agenda -> Approved               ||   |
|  | |                                                             ||   |
|  | |   4 states  |  3 transitions  |  Used by: 5 decisions       ||   |
|  | +-------------------------------------------------------------+|   |
|  |                                                                 |   |
|  | +-------------------------------------------------------------+|   |
|  | | Urgentieprocedure (Emergency)                        [Edit]  ||   |
|  | |                                                             ||   |
|  | |   Submitted -> Voted -> Approved / Rejected                 ||   |
|  | |                                                             ||   |
|  | |   3 states  |  3 transitions  |  Used by: 1 decision        ||   |
|  | +-------------------------------------------------------------+|   |
|  +----------------------------------------------------------------+   |
|                                                                       |
|  +----------------------------------------------------------------+   |
|  | VOTING METHOD CONFIG                           [+ Add Method]   |   |
|  |                                                                 |   |
|  | +-------------------------------------------------------------+|   |
|  | | * Handopsteken (Show of hands)     Default        [Edit]    ||   |
|  | |   Majority: Simple (>50% present)                           ||   |
|  | |   Quorum required: Yes  |  Anonymous: No                    ||   |
|  | +-------------------------------------------------------------+|   |
|  |                                                                 |   |
|  | +-------------------------------------------------------------+|   |
|  | | Hoofdelijke stemming (Roll call)                  [Edit]    ||   |
|  | |   Majority: Simple (>50% present)                           ||   |
|  | |   Quorum required: Yes  |  Anonymous: No                    ||   |
|  | +-------------------------------------------------------------+|   |
|  |                                                                 |   |
|  | +-------------------------------------------------------------+|   |
|  | | Geheime stemming (Secret ballot)                  [Edit]    ||   |
|  | |   Majority: Absolute (>50% seats)                           ||   |
|  | |   Quorum required: Yes  |  Anonymous: Yes                   ||   |
|  | +-------------------------------------------------------------+|   |
|  |                                                                 |   |
|  | +-------------------------------------------------------------+|   |
|  | | Gewogen stemming (Weighted vote)                  [Edit]    ||   |
|  | |   Majority: Simple (>50% present weight)                    ||   |
|  | |   Quorum required: Yes (60% weight)  |  Anonymous: No       ||   |
|  | +-------------------------------------------------------------+|   |
|  +----------------------------------------------------------------+   |
|                                                                       |
+-----------------------------------------------------------------------+
```

### 3.10 User Settings Dialog

```
+-----------------------------------------------------------------------+
|  +---------------------------------------------------------------+    |
|  |                     Decidesk Settings                    [X]  |    |
|  +---------------------------------------------------------------+    |
|  |                                                               |    |
|  |  NOTIFICATIONS                                                |    |
|  |  +---------------------------------------------------------+  |    |
|  |  |                                                         |  |    |
|  |  | [x] Notify me of upcoming meetings (24h before)         |  |    |
|  |  | [x] Notify me when a vote is opened                     |  |    |
|  |  | [x] Notify me when assigned an action item              |  |    |
|  |  | [ ] Notify me when a decision status changes            |  |    |
|  |  | [x] Notify me when my motion is scheduled               |  |    |
|  |  | [ ] Daily digest of pending items                       |  |    |
|  |  |                                                         |  |    |
|  |  | Notification channel: [Push + Email v]                  |  |    |
|  |  |                                                         |  |    |
|  |  +---------------------------------------------------------+  |    |
|  |                                                               |    |
|  |  DEFAULT VIEW                                                 |    |
|  |  +---------------------------------------------------------+  |    |
|  |  |                                                         |  |    |
|  |  | Landing page:  ( ) Dashboard                            |  |    |
|  |  |                (*) My Work                              |  |    |
|  |  |                ( ) Meetings                             |  |    |
|  |  |                ( ) Decisions                            |  |    |
|  |  |                                                         |  |    |
|  |  | Decision view: (*) Kanban board                         |  |    |
|  |  |                ( ) List view                            |  |    |
|  |  |                                                         |  |    |
|  |  +---------------------------------------------------------+  |    |
|  |                                                               |    |
|  |  SPEAKING TIME                                                |    |
|  |  +---------------------------------------------------------+  |    |
|  |  |                                                         |  |    |
|  |  | Alert threshold:  [30] seconds before time expires      |  |    |
|  |  | Alert sound:      [Soft chime v]                        |  |    |
|  |  | Show timer:       [x] Display countdown during debates  |  |    |
|  |  |                                                         |  |    |
|  |  +---------------------------------------------------------+  |    |
|  |                                                               |    |
|  |  ACCESSIBILITY                                                |    |
|  |  +---------------------------------------------------------+  |    |
|  |  |                                                         |  |    |
|  |  | [x] High contrast vote buttons                          |  |    |
|  |  | [ ] Screen reader announcements for vote results        |  |    |
|  |  | Font size: [Default v]                                  |  |    |
|  |  |                                                         |  |    |
|  |  +---------------------------------------------------------+  |    |
|  |                                                               |    |
|  +---------------------------------------------------------------+    |
+-----------------------------------------------------------------------+
```
