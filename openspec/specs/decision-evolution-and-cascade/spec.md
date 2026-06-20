---
status: done
---

# Specs: Minutes and Decisions — Other T2

**Change:** p2-minutes-and-decisions-other-t2
**App:** Decidesk
**Entities:** Decision, ActionItem
**Depends on:** p2-minutes-and-decisions

---

## Purpose

This spec defines decision evolution links, stakeholder notifications, decision cascade to departments, and the related dashboard extension for Decidesk.

# Requirements

## REQ-DEV: Decision Evolution

The system SHALL satisfy the REQ-DEV (Decision Evolution) requirements specified below.

### REQ-DEV-001 — Link a decision to a related decision

A secretary or clerk can link a Decision to another Decision using a typed relation (amends, supersedes, replaces, is-superseded-by).

**GIVEN** the Decision detail page is open for a published or adopted Decision
**WHEN** the user clicks "Koppel besluit" in the Related Decisions card header actions
**THEN** a dialog opens with a Decision search field and a relation type selector (amends / supersedes / replaces / is-superseded-by)
**AND** on confirmation the OpenRegister relation is created between the two Decision objects with the selected type as the relation label
**AND** the Related Decisions card refreshes to show the newly linked decision

---

### REQ-DEV-002 — View related decisions on the Decision detail page

A user can see all decisions linked to the current decision, together with their relation type.

**GIVEN** a Decision detail page is open
**WHEN** the page loads
**THEN** a "Gerelateerde besluiten" (Related Decisions) `CnDetailCard` is displayed
**AND** it lists all decisions that this Decision links to (fetchUses) with columns: title, decisionDate, outcome, relation type badge
**AND** it also lists all decisions that link to this Decision (fetchUsed) with the same columns
**AND** clicking a related decision navigates to that decision's detail page

---

### REQ-DEV-003 — Remove a decision relation

A secretary can remove an incorrectly created link between two decisions.

**GIVEN** the Related Decisions card on a Decision detail page shows a linked decision
**WHEN** the user clicks the remove icon on a relation row and confirms the dialog
**THEN** the OpenRegister relation is deleted
**AND** the removed decision no longer appears in the Related Decisions card

---

### REQ-DEV-004 — View evolution chain badge on Decision index

A user can see at a glance which decisions in the index are part of an evolution chain.

**GIVEN** the Decisions index page (`CnIndexPage`) is open
**WHEN** the list renders
**THEN** decisions that have at least one related decision (any type) display a "Gelinkt" badge in a dedicated "Relaties" column using `CnStatusBadge`
**AND** decisions with no relations show no badge in that column

---

## REQ-NOT: Stakeholder Notification

The system SHALL satisfy the REQ-NOT (Stakeholder Notification) requirements specified below.

### REQ-NOT-001 — Trigger stakeholder notification for a published decision

A secretary can explicitly send Nextcloud in-app notifications to selected stakeholders about a published decision.

**GIVEN** a Decision detail page is open for a Decision with `isPublished: true`
**WHEN** the user clicks "Betrokkenen informeren" in the Decision detail header actions
**THEN** a dialog opens listing participants from the linked Meeting (or manually searchable via autocomplete)
**AND** the secretary can select one or more participants as notification recipients
**AND** on confirmation, the backend `DecisionNotificationService::notify()` is called

---

### REQ-NOT-002 — Notification content and delivery

The notification sent to each stakeholder contains the decision summary and a direct link.

**GIVEN** "Betrokkenen informeren" has been confirmed with at least one recipient
**WHEN** `DecisionNotificationService::notify()` executes
**THEN** each selected participant receives a Nextcloud in-app notification with:
  - Title: the Decision `title` field value
  - Body: "Besluit gepubliceerd — uitkomst: {outcome}. Klik om het besluit te bekijken."
  - Deep link: `/apps/decidesk/decisions/{uuid}` (history-mode path, not hash format)
**AND** the notification appears in the Nextcloud notification bell for each recipient

---

### REQ-NOT-003 — Notification cannot be sent to unpublished decisions

The notification action is unavailable until the decision has been published.

**GIVEN** a Decision detail page is open for a Decision with `isPublished: false`
**WHEN** the page renders
**THEN** the "Betrokkenen informeren" button is absent or disabled
**AND** no notification endpoint call is possible from the UI

---

### REQ-NOT-004 — Notification endpoint requires authentication

The notification endpoint is protected and only callable by authenticated users.

**GIVEN** an unauthenticated request is sent to `POST /api/decisions/{id}/notify`
**WHEN** the request is processed
**THEN** the server returns HTTP 401
**AND** no notifications are sent

---

## REQ-CAS: Decision Cascade to Departments

The system SHALL satisfy the REQ-CAS (Decision Cascade to Departments) requirements specified below.

### REQ-CAS-001 — Cascade a decision to selected departments

A manager or secretary can cascade a published decision to one or more departments, creating a tracked ActionItem for each.

**GIVEN** a Decision detail page is open for a Decision with `isPublished: true`
**WHEN** the user clicks "Cascaderen naar afdelingen" in the Decision detail header actions
**THEN** a dialog opens with a searchable list of governance bodies (departments / bodies from the GovernanceBody index)
**AND** the user can select one or more governance bodies as cascade targets
**AND** on confirmation, `DecisionCascadeService::cascade()` is called

---

### REQ-CAS-002 — ActionItem created per cascaded department

For each selected department, a linked ActionItem is created automatically.

**GIVEN** "Cascaderen naar afdelingen" has been confirmed with at least one department selected
**WHEN** `DecisionCascadeService::cascade()` executes
**THEN** one ActionItem is created per selected department via `ObjectService::saveObject()` with:
  - `title`: "Uitvoering: {decision title}"
  - `description`: first 500 characters of the Decision `text` field
  - `assignee`: name of the selected governance body
  - `taskStatus`: "open"
  - `dueDate`: 30 calendar days from today (default; editable after creation)
**AND** each created ActionItem has an OpenRegister relation pointing to the source Decision
**AND** each created ActionItem appears immediately in the ActionItems index (`/action-items`)

---

### REQ-CAS-003 — Cascaded action items visible on the Decision detail page

A user can see all ActionItems created via cascade directly on the Decision detail page.

**GIVEN** a Decision detail page is open for a Decision that has been cascaded to at least one department
**WHEN** the page loads
**THEN** a "Actiepunten afdelingen" (Department Action Items) `CnDetailCard` section is shown
**AND** it lists all ActionItems related to this Decision with columns: title, assignee, dueDate, taskStatus (with `CnStatusBadge`)
**AND** clicking a row navigates to the ActionItem detail page

---

### REQ-CAS-004 — Cascade is only available on published decisions

The cascade action is unavailable for unpublished or draft decisions.

**GIVEN** a Decision detail page is open for a Decision with `isPublished: false`
**WHEN** the page renders
**THEN** the "Cascaderen naar afdelingen" button is absent or disabled
**AND** no cascade endpoint call is possible from the UI

---

### REQ-CAS-005 — Cascade endpoint requires authentication

The cascade endpoint is protected and only callable by authenticated users.

**GIVEN** an unauthenticated request is sent to `POST /api/decisions/{id}/cascade`
**WHEN** the request is processed
**THEN** the server returns HTTP 401
**AND** no ActionItems are created

---

## REQ-DASH: Dashboard Extension

The system SHALL satisfy the REQ-DASH (Dashboard Extension) requirements specified below.

### REQ-DASH-001 — Dashboard KPI for open cascade action items

A manager can see at a glance how many cascade-generated ActionItems are still open.

**GIVEN** the Decidesk Dashboard page is open
**WHEN** the page loads
**THEN** a "Besluit-actiepunten open" `CnStatsBlock` KPI card is displayed
**AND** it shows the count of ActionItems with `taskStatus: open` or `taskStatus: in-progress`
**AND** the count updates when ActionItems are created via cascade or have their status changed
