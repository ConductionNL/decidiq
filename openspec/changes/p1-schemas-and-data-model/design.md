# Design: Schemas and Data Model

**Status:** in-progress
**Spec:** p1-schemas-and-data-model
**App:** Decidesk
**Issue:** #3

## Summary

Define all 17 entity schemas in the OpenRegister register template, replacing the placeholder
`example` schema. This is the Phase 1 foundation that all subsequent specs build on.

## What and Why

Decidesk needs a complete data model before any governance features can be built. This change:

1. Defines all 17 entity schemas with proper types, required flags, and schema.org vocabulary
2. Provides seed data (3-5 objects per schema) for development and testing
3. Registers deep links so Nextcloud unified search can link to entity detail views
4. Registers object types in the frontend store so Vue views can query OpenRegister
5. Creates basic navigation, index, and detail views for all entities using standard
   @conduction/nextcloud-vue components (CnIndexPage, CnDetailPage)

## Entities (17)

All defined in ADR-000-data-model. Governance-specific:
- GovernanceBody, Participant, Meeting, AgendaItem
- Motion, Amendment, VotingRound, Vote
- Decision, ActionItem, Minutes

Generic (schema.org):
- DigitalDocument, MonetaryAmount, Offer, Order, Product, Report

## Reuse Analysis

- **ObjectService**: all CRUD via OpenRegister — no custom mappers
- **CnIndexPage / CnDetailPage**: schema-driven list + detail — no custom page layouts
- **CnFormDialog**: schema-driven forms — no custom form components
- **createObjectStore / useListView / useDetailView**: standard store + composables
- **ConfigurationService**: register import via repair step — already wired
- **No overlap found** with existing OpenRegister services or @conduction/nextcloud-vue components

## Schema Standards

- PascalCase schema names
- schema.org vocabulary where equivalent exists
- OpenRegister relations (register + schema + objectId) — no foreign keys
- Explicit types on all properties
- Seed data uses general organization context (municipality/consultancy)
