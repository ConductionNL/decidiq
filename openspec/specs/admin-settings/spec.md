---
status: idea
---

# Admin Settings Specification

## Purpose

Admin settings enable organization administrators to configure Decidesk for their specific governance context. This includes setting up organizations and governing bodies (bestuursorganen), assigning members with roles, selecting process templates, configuring voting methods and quorum rules, managing integration settings, and importing member data. The admin interface is the first thing configured after installation and determines how the entire system behaves.

**Standards**: Nextcloud Settings API (`OCP\Settings\ISettings`), Schema.org (`Organization`, `Role`, `GovernmentOrganization`), WBTR (Wet bestuur en toezicht rechtspersonen)
**Feature tier**: MVP

**Evidence sources**: Intelligence DB user stories #23, #28, #39, #40, #43, #46, #48, #55, #56, #59, #61, #70, #76, #79, #82, #85, #106, #107, #129, #309, #312, #330, #342, #347, #394; Requirement clusters #18 (Authorization/RBAC, 876 reqs/205 tenders), #19 (Role-based access, 629 reqs/200 tenders), #31 (Zaaktype configuration, 2425 reqs/159 tenders), #43 (Besluitvorming, 271 reqs/133 tenders), #73 (Document templates, 240 reqs/96 tenders); Category features: rbac, role-based-views, template-management, calendar-integration

## Requirements

---

### REQ-AS-01: Organization Configuration

The system MUST support configuring organization-level settings: organization name, logo, legal form (vereniging, stichting, cooperatie, NV, BV), default language (nl/en), timezone, currency for cost calculations, and archival retention period. Organization settings MUST be stored as an OpenRegister object using the `organization` schema.

**Feature tier**: MVP

#### Scenario: Configure organization defaults

- GIVEN an administrator opens the organization settings section
- WHEN they set organization name "Vereniging De Harmonie", legal form "vereniging", language "nl", timezone "Europe/Amsterdam", and currency "EUR"
- THEN these defaults MUST apply to all meetings, decisions, and generated documents
- AND the organization name and logo MUST appear on generated resolutions and minutes
- AND the legal form MUST determine which statutory rules (BW 2:38-52) are applied by default

#### Scenario: Upload organization logo and branding

- GIVEN an administrator in the organization branding section
- WHEN they upload a logo image and set primary branding color
- THEN the logo MUST be used on generated documents (minutes, resolutions, convocations)
- AND the branding MUST be applied to the Decidesk interface header
- AND the logo MUST be stored in Nextcloud Files under a configurable path

#### Scenario: Configure archival retention period

- GIVEN an organization subject to Archiefwet retention rules
- WHEN the administrator sets the default retention period to 10 years
- THEN all decisions, minutes, and resolutions MUST carry this retention metadata
- AND the system MUST warn when documents approach their retention deadline

---

### REQ-AS-02: Governing Body Management

The system MUST support creating and managing governing bodies (bestuursorganen). Each body MUST have a name, type (council, board, assembly, committee, team, ledenraad), member list with roles, default process template, quorum rules, and optional parent body reference. Bodies MUST be stored as OpenRegister objects in the `decidesk` register using the `body` schema.

**Feature tier**: MVP

#### Scenario: Create a governing body for an association board

- GIVEN an administrator in the Decidesk admin settings
- WHEN they create a body with name "Bestuur", type "board", and add 5 members with roles (chair, secretary, treasurer, member, member)
- THEN the system MUST create an OpenRegister object with the `body` schema
- AND each member MUST be linked to a Nextcloud user account
- AND the default process template MUST be selectable from available templates

#### Scenario: Create a committee under a parent body

- GIVEN an existing body "Bestuur" (board)
- WHEN the administrator creates a body "Kascommissie" with type "committee" and parent "Bestuur"
- THEN the committee MUST be linked to its parent body
- AND the committee MUST have its own member list independent of the parent
- AND the committee MUST validate that members are not board members (per BW 2:48)

#### Scenario: Create an ALV body with large membership

- GIVEN an organization with 200 registered members
- WHEN the administrator creates a body "Algemene Ledenvergadering" with type "assembly"
- THEN the body MUST support all 200 members as potential voters
- AND the system MUST differentiate between voting and non-voting members
- AND the body MUST support proxy (volmacht) configuration

---

### REQ-AS-03: Member Role Management

The system MUST support assigning roles to body members. Standard roles MUST include chair (voorzitter), secretary (secretaris), treasurer (penningmeester), and member (lid). Custom roles MUST be supported. Each role MUST map to specific permissions within the governance workflow.

**Feature tier**: MVP

#### Scenario: Assign roles within a body

- GIVEN an existing body with members
- WHEN the administrator assigns the "chair" role to a member
- THEN the member MUST have chair-specific permissions (start votes, manage agenda, set speaking order, declare meeting opened/closed)
- AND the "secretary" role MUST grant minute-taking, convocation, and document management permissions
- AND the "treasurer" role MUST grant financial report upload and budget proposal permissions
- AND the "member" role MUST grant voting and speaking rights only

#### Scenario: Configure role-specific notification defaults

- GIVEN an administrator configuring the "secretary" role
- WHEN they set notification defaults for the role
- THEN all users assigned this role MUST receive notifications for convocation deadlines, minute approval requests, and action item assignments by default
- AND individual users MAY override these defaults in their personal settings

#### Scenario: Assign multiple roles to a single member

- GIVEN a small board where one person serves as both secretary and treasurer
- WHEN the administrator assigns both roles to member A
- THEN member A MUST have the combined permissions of both roles
- AND both roles MUST be visible in the member's profile and in meeting attendance records

---

### REQ-AS-04: Quorum Rule Configuration

The system MUST allow administrators to configure quorum rules per body. Quorum rules MUST support absolute numbers, percentages, and formulas (e.g., "50%+1 of members present or represented"). The system MUST support different quorum rules for different decision types (ordinary decisions vs. statute amendments).

**Feature tier**: MVP

#### Scenario: Configure standard quorum for a body

- GIVEN an existing body "ALV" with 200 members
- WHEN the administrator sets quorum to "50%+1 of members present or represented"
- THEN the quorum MUST be automatically calculated at each meeting based on registered attendance and proxy votes
- AND the system MUST display real-time quorum status (met / not met / at risk)

#### Scenario: Configure qualified majority for statute amendments

- GIVEN a body "ALV" with standard quorum rules
- WHEN the administrator adds a special rule: "Statute amendments require 2/3 of members present AND 2/3 majority"
- THEN this rule MUST automatically apply when a decision is marked as "statute amendment" type
- AND the voting interface MUST show both the quorum and majority thresholds
- AND the system MUST generate proof documents for the notary confirming proper quorum and majority

#### Scenario: Configure quorum with proxy vote limits

- GIVEN statutes that limit proxy votes to maximum 3 per person
- WHEN the administrator configures this limit on the body
- THEN the system MUST reject proxy assignments that exceed the limit
- AND proxy votes MUST be counted toward quorum calculation
- AND the system MUST flag members holding more than the allowed number of proxies

---

### REQ-AS-05: Process Template Assignment

The system MUST allow administrators to assign process templates to bodies. Each body MUST have a default template and MAY have additional templates for specific decision types (e.g., statute amendment, board election, budget approval, circular resolution).

**Feature tier**: MVP

#### Scenario: Assign default and specialized templates to a body

- GIVEN a body "ALV" with a default template "ALV Standard Decision"
- WHEN the administrator adds a specialized template "ALV Statute Amendment" for statute changes
- THEN the body MUST have both templates available
- AND when creating a decision, the user MUST be able to choose the applicable template
- AND if no template is chosen, the default MUST apply

#### Scenario: Create a circular resolution template

- GIVEN a body "Bestuur" that needs to make decisions between meetings
- WHEN the administrator creates a template "Written Decision (BW 2:40)" with workflow steps: propose, circulate, collect votes, record
- THEN the template MUST require unanimous consent for valid decisions
- AND the template MUST enforce a configurable response deadline
- AND non-responding board members MUST receive escalating reminders

#### Scenario: Configure template with mandatory agenda items

- GIVEN a body "ALV" that must always include certain agenda items
- WHEN the administrator configures mandatory items (opening, approval previous minutes, annual report, financial statements, kascommissie report, board election, rondvraag, closing)
- THEN every meeting created with this template MUST include these items
- AND mandatory items MUST NOT be removable from the agenda

---

### REQ-AS-06: Voting Method Configuration

The system MUST allow administrators to configure voting methods per body and per decision type. Supported methods MUST include: open vote (show of hands), roll call vote (per-member recorded), secret ballot, and digital/hybrid vote. Each method MUST define how results are calculated and displayed.

**Feature tier**: MVP

#### Scenario: Configure voting methods for a body

- GIVEN a body "Bestuur" with 7 members
- WHEN the administrator sets default voting method to "open vote" and adds "secret ballot" for board elections
- THEN ordinary decisions MUST use open (per-member visible) voting
- AND board election decisions MUST automatically use secret ballot
- AND the administrator MUST be able to override the method for individual decisions

#### Scenario: Configure majority calculation rules

- GIVEN a body with configurable voting
- WHEN the administrator sets majority rules: simple majority (>50% of votes cast), absolute majority (>50% of all members), qualified majority (2/3 of votes cast), unanimous
- THEN each decision type MUST use its configured majority rule
- AND the voting results screen MUST show both the threshold and actual result
- AND abstentions MUST be handled according to the configured rule (counted or excluded)

---

### REQ-AS-07: Member Import and Synchronization

The system MUST support importing members from Nextcloud Groups, Nextcloud Contacts, or CSV file. Imported members MUST be linked to Nextcloud user accounts where possible. The system MUST support ongoing synchronization with Nextcloud Groups.

**Feature tier**: MVP

#### Scenario: Import members from a Nextcloud group

- GIVEN a Nextcloud group "bestuur" with 5 members
- WHEN the administrator imports the group into a Decidesk body
- THEN all 5 Nextcloud users MUST be added as body members
- AND their display names and email addresses MUST be populated from Nextcloud
- AND the administrator MUST be able to assign roles after import

#### Scenario: Import members from CSV

- GIVEN a CSV file with columns: name, email, role, membership_category, voting_rights
- WHEN the administrator uploads the CSV for a body
- THEN the system MUST create member entries for each row
- AND members with matching Nextcloud accounts (by email) MUST be automatically linked
- AND unmatched members MUST be flagged for manual linking or invitation

#### Scenario: Synchronize body membership with Nextcloud group

- GIVEN a body "Bestuur" linked to Nextcloud group "bestuur"
- WHEN a new user is added to the Nextcloud group
- THEN the user MUST be automatically added to the Decidesk body with "member" role
- AND when a user is removed from the group, they MUST be deactivated (not deleted) in the body
- AND the sync MUST be configurable as automatic or manual

---

### REQ-AS-08: Conflict of Interest Register

The system MUST support maintaining a digital conflict of interest register for all body members. The register MUST be automatically checked against agenda items to flag potential conflicts before meetings.

**Feature tier**: V1

#### Scenario: Manage conflict of interest register

- GIVEN a board with 7 members
- WHEN the administrator or secretary adds interests for each member (company affiliations, family relationships, financial interests)
- THEN the register MUST store all declared interests per member
- AND the system MUST automatically flag potential conflicts when agenda items match registered interests
- AND members MUST receive a notification to confirm or dismiss flagged conflicts before the meeting

#### Scenario: Annual conflict of interest update

- GIVEN a conflict of interest register that was last updated 11 months ago
- WHEN the annual review deadline approaches
- THEN all body members MUST receive a notification to review and update their declarations
- AND the secretary MUST receive a dashboard showing which members have completed their update
- AND the system MUST track declaration history with timestamps

---

### REQ-AS-09: Board Handover Management

The system MUST support structured handover when body members change. The handover process MUST include a configurable checklist covering access transfer, KvK registration, signing authority, and knowledge transfer.

**Feature tier**: V1

#### Scenario: Execute board member handover checklist

- GIVEN a board member is being replaced
- WHEN the administrator initiates a handover process
- THEN a checklist MUST be created with all required steps (KvK update, bank access transfer, system access revocation/granting, document handover)
- AND each step MUST have an assignee and deadline
- AND the handover MUST NOT be marked complete until all steps are confirmed

---

### REQ-AS-10: Integration Settings

The system MUST allow administrators to configure integration settings for connected Nextcloud apps. Integrations MUST include Calendar (meeting sync), Files (document storage path), Talk (meeting channels), Mail (convocation delivery), and Activity (audit trail).

**Feature tier**: V1

#### Scenario: Configure document storage path

- GIVEN the administrator in integration settings
- WHEN they set the base document path to "Governance/Decidesk"
- THEN all meeting folders MUST be created under this path in Nextcloud Files
- AND the path MUST be validated for write permissions
- AND existing meetings MUST NOT be affected by a path change

#### Scenario: Configure mail integration for convocations

- GIVEN the administrator enabling mail integration
- WHEN they configure the sender address and convocation template
- THEN convocations MUST be sent from the configured address
- AND the template MUST support merge fields for meeting date, body name, agenda link, and document links
- AND the system MUST track delivery status per recipient

---

### REQ-AS-11: Meeting Cost Configuration

The system MUST allow administrators to configure meeting cost calculation parameters per body. This includes hourly rates (average or per-member), cost components (salary, benefits, overhead), and reporting periods.

**Feature tier**: V2

#### Scenario: Configure meeting cost parameters

- GIVEN an administrator setting up cost tracking for the MT body
- WHEN they set average hourly rate to EUR 95 including benefits and overhead
- THEN the system MUST calculate meeting costs as attendees x hourly rate x duration
- AND the cost dashboard MUST show cost trends over time per body
- AND the live meeting cost ticker MUST be optionally displayable during meetings

---

### REQ-AS-12: Compliance Configuration

The system MUST allow administrators to configure statutory compliance settings based on the organization's legal form and applicable regulations (BW, WBTR, statutes).

**Feature tier**: V2

#### Scenario: Configure WBTR compliance rules

- GIVEN an organization of type "vereniging"
- WHEN the administrator enables WBTR compliance
- THEN the system MUST enforce conflict of interest declarations (BW 2:10)
- AND multiple voting restrictions MUST be applied per BW 2:40
- AND board decision documentation requirements MUST be enforced
- AND the Corporate Governance Code provisions MUST be trackable with comply-or-explain statements

## User Stories

1. **Board secretary managing conflict of interest register**: As a board secretary, I want to maintain a digital conflict of interest register for all board and supervisory board members, so that potential conflicts are proactively identified before meetings. (Source: intelligence DB #23, priority: must)

2. **Supervisory board chair managing director appointment**: As a supervisory board chair, I want to manage the full director appointment process from vacancy to formal appointment, so that governance procedures are properly followed. (Source: intelligence DB #28, priority: should)

3. **Board secretary organizing document archive**: As a board secretary, I want to maintain a structured, searchable governance document archive with access controls, so that governance documents are secure, findable, and properly retained. (Source: intelligence DB #43, priority: must)

4. **Legal counsel tracking governance code compliance**: As legal counsel, I want to track compliance with each provision of the Corporate Governance Code, so that I can prepare the comply-or-explain statement for the annual report. (Source: intelligence DB #39, priority: must)

5. **Board secretary preparing governance chapter**: As a board secretary, I want to compile the corporate governance chapter for the annual report from compliance data, so that reporting is consistent and complete. (Source: intelligence DB #40, priority: should)

6. **Secretary sending ALV convocation**: As secretary, I want to send the ALV convocation to all voting members via their preferred channel so that I can prove all members were properly notified within the statutory deadline. (Source: intelligence DB #46, priority: critical)

7. **Secretary handling extraordinary ALV request**: As secretary, I want to verify that an extraordinary ALV request is valid (signed by 10%+ of members) and convene the meeting within 4 weeks so that we comply with BW 2:41. (Source: intelligence DB #48, priority: high)

8. **Secretary registering attendance and verifying quorum**: As secretary, I want to register member attendance and automatically calculate quorum including proxy votes so that I can confirm the meeting is valid. (Source: intelligence DB #55, priority: critical)

9. **Secretary verifying member voting rights**: As secretary, I want to verify each attendee's voting rights so that only eligible members participate in voting. (Source: intelligence DB #56, priority: critical)

10. **Secretary verifying qualified majority for statute amendments**: As secretary, I want to verify that a statute amendment vote meets the required quorum and qualified majority so that the notary can confirm proper procedure. (Source: intelligence DB #59, priority: critical)

11. **Chair nominating kascommissie members**: As chair, I want to present kascommissie candidates for ALV approval ensuring they meet requirements (non-board, minimum 2) so that the audit function is properly established. (Source: intelligence DB #70, priority: high)

12. **Administrator publishing meeting decisions**: As administrator, I want to publish key decisions from ALV and board meetings on the member portal so that all members stay informed about association governance. (Source: intelligence DB #76, priority: medium)

13. **Member petitioning for extraordinary ALV**: As a member, I want to digitally petition for an extraordinary ALV and collect supporting signatures so that we can reach the 10% threshold required by law. (Source: intelligence DB #79, priority: medium)

14. **Ledenraad member preparing for council meeting**: As a ledenraad member, I want to review the agenda, consult my constituency, and prepare my voting position so that I can effectively represent my members. (Source: intelligence DB #82, priority: medium)

15. **Secretary executing board member handover**: As secretary, I want to follow a structured handover checklist when board members change so that KvK registration, signing authority, bank access, and system access are all properly transferred. (Source: intelligence DB #85, priority: high)

16. **MT member cascading new policy**: As an MT member, I want to cascade an approved policy through the management layers so that every employee understands what changed and what it means for them. (Source: intelligence DB #106, priority: medium)

17. **CEO tracking policy adoption**: As a CEO, I want to track which policies have been acknowledged and implemented across departments so that I can identify compliance gaps. (Source: intelligence DB #107, priority: low)

18. **Employee searching decision register**: As an employee, I want to search a central decision register by topic, date, meeting, or decision-maker, so that I can find relevant past decisions. (Source: intelligence DB #129, priority: high)

19. **Clerk publishing vote results**: As a clerk, I want to publish vote results including each member's position so that citizens can verify how their representatives voted. (Source: intelligence DB #309, priority: must)

20. **Board chair verifying quorum before voting**: As a board chair, I want to verify quorum before opening voting so that all decisions taken are legally valid per BW 2:38. (Source: intelligence DB #312, priority: must)

21. **CEO viewing meeting cost dashboard**: As a CEO, I want to see a real-time dashboard showing total organizational meeting costs and trends so that I can identify optimization opportunities. (Source: intelligence DB #330, priority: must)

22. **Meeting secretary tracking quorum and attendance**: As a meeting secretary, I want real-time quorum tracking with automatic alerts when quorum is at risk, and historical attendance analytics per member. (Source: intelligence DB #342, priority: must)

23. **Board secretary conducting formal votes**: As a board secretary, I want to conduct formal votes with configurable majority rules (simple, absolute, qualified 2/3) and an auditable record. (Source: intelligence DB #347, priority: must)

24. **Raadslid drafting motion from template**: As a raadslid, I want to create a motion using a standard template, so that my motion meets all procedural requirements. (Source: intelligence DB #144, priority: must)

25. **New council member onboarding**: As a new raadslid, I want a guided onboarding process for all digital tools, so that I can start my council work immediately after being sworn in. (Source: intelligence DB #201, priority: should)

26. **Candidate presenting profile to members**: As a board candidate, I want to present my profile, vision, and qualifications to all members before the election so that they can make an informed choice. (Source: intelligence DB #61, priority: medium)

27. **CEO viewing meeting health report**: As a CEO, I want a monthly organizational meeting health report showing key metrics compared to industry benchmarks. (Source: intelligence DB #349, priority: should)

## Acceptance Criteria

1. Organization settings (name, logo, legal form, language, timezone, currency, retention) are stored as an OpenRegister object with the `organization` schema
2. Governing bodies are stored as OpenRegister objects with member lists, roles, and parent body references
3. Body types include council, board, assembly, committee, team, and ledenraad
4. Body roles (chair, secretary, treasurer, member, custom) map to specific governance permissions
5. Quorum rules are configurable per body with support for absolute numbers, percentages, and formulas
6. Different quorum/majority rules are configurable per decision type (ordinary vs. statute amendment)
7. Proxy vote limits are configurable per body according to statutes
8. Process templates are assignable to bodies with default and specialized options
9. Templates support mandatory agenda items that cannot be removed
10. Voting methods (open, roll call, secret ballot, digital) are configurable per body and decision type
11. Majority calculation rules (simple, absolute, qualified, unanimous) are configurable
12. Member import from Nextcloud Groups, Contacts, and CSV is supported with automatic account linking
13. Group synchronization is configurable as automatic or manual
14. Conflict of interest register with automatic agenda-item flagging is supported
15. Board handover checklist with step tracking and deadline management is supported
16. Integration settings (Calendar, Files, Talk, Mail, Activity) are configurable
17. Meeting cost calculation parameters (hourly rates, components) are configurable per body
18. WBTR and statutory compliance rules are configurable based on legal form
19. Admin settings use Nextcloud Settings API (`OCP\Settings\ISettings`)
20. All configuration changes are logged in the Nextcloud Activity feed for audit purposes
