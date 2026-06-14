<?php

declare(strict_types=1);

return [
    'routes' => [
        // Dashboard + Settings.
        ['name' => 'dashboard#page', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'settings#index', 'url' => '/api/settings', 'verb' => 'GET'],
        ['name' => 'settings#create', 'url' => '/api/settings', 'verb' => 'POST'],
        ['name' => 'settings#load',  'url' => '/api/settings/load', 'verb' => 'POST'],

        // Process template management (admin-only — AuthorizedAdminSetting on every method).
        // @spec openspec/specs/process-configuration/spec.md
        ['name' => 'processTemplate#index',     'url' => '/api/process-templates',                  'verb' => 'GET'],
        ['name' => 'processTemplate#validate',  'url' => '/api/process-templates/validate',         'verb' => 'POST'],
        ['name' => 'processTemplate#create',    'url' => '/api/process-templates',                  'verb' => 'POST'],
        ['name' => 'processTemplate#show',      'url' => '/api/process-templates/{id}',             'verb' => 'GET'],
        ['name' => 'processTemplate#update',    'url' => '/api/process-templates/{id}',             'verb' => 'PUT'],
        ['name' => 'processTemplate#duplicate', 'url' => '/api/process-templates/{id}/duplicate',   'verb' => 'POST'],
        ['name' => 'processTemplate#destroy',   'url' => '/api/process-templates/{id}',             'verb' => 'DELETE'],

        // Member import (admin-only — AuthorizedAdminSetting on every method).
        // @spec openspec/specs/admin-settings/spec.md
        ['name' => 'memberImport#groups',       'url' => '/api/member-import/groups',                   'verb' => 'GET'],
        ['name' => 'memberImport#groupMembers', 'url' => '/api/member-import/groups/{groupId}/members', 'verb' => 'GET'],
        ['name' => 'memberImport#match',        'url' => '/api/member-import/match',                    'verb' => 'POST'],

        // Generic per-user preferences (used by shared nextcloud-vue widgets, e.g. CnSupportDialog).
        ['name' => 'preferences#getPreference', 'url' => '/api/preferences/{key}', 'verb' => 'GET'],
        ['name' => 'preferences#setPreference', 'url' => '/api/preferences/{key}', 'verb' => 'PUT'],

        // Analytics endpoint — personal action-item list only.
        // getSummary and getCompletionRates removed: generic aggregations now live in
        // x-openregister-aggregations on Meeting schema, rendered by the analytics leaf.
        // @spec openspec/changes/migrate-engagement-analytics-to-analytics-leaf/tasks.md#task-3.2
        ['name' => 'analytics#getMyItems',             'url' => '/api/analytics/action-items/my-items',         'verb' => 'GET'],

        // Live meeting endpoints — live decision recording during active meetings.
        // @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2
        ['name' => 'liveMeeting#recordLiveDecision', 'url' => '/api/meetings/{meetingId}/live-decisions', 'verb' => 'POST'],

        // Minutes endpoints — specific routes must precede the wildcard catch-all.
        // @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
        ['name' => 'minutes#generateDraft',   'url' => '/api/minutes/{minutesId}/generate-draft',  'verb' => 'POST'],
        ['name' => 'minutes#transition',      'url' => '/api/minutes/{minutesId}/transition',       'verb' => 'POST'],

        // ALV minutes endpoints.
        // @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3
        ['name' => 'minutes#generateALVDraft',    'url' => '/api/minutes/{minutesId}/generate-alv', 'verb' => 'POST'],
        ['name' => 'minutes#distributeALVMinutes', 'url' => '/api/minutes/{minutesId}/distribute',  'verb' => 'POST'],

        // Action item extraction endpoints.
        // @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4
        ['name' => 'minutes#extractActionItems',         'url' => '/api/minutes/{minutesId}/extract-action-items',              'verb' => 'POST'],
        ['name' => 'minutes#saveExtractedActionItems',   'url' => '/api/minutes/{minutesId}/save-extracted-action-items',       'verb' => 'POST'],

        // Minutes approval endpoints.
        // @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-6
        ['name' => 'minutes#submitForApproval',          'url' => '/api/minutes/{minutesId}/submit-for-approval',               'verb' => 'POST'],

        // Minutes approval workflow + document generation (minutes-ui-v1).
        // @spec openspec/specs/resolution-minutes/spec.md
        ['name' => 'minutes#addCorrection',     'url' => '/api/minutes/{minutesId}/corrections',                  'verb' => 'POST'],
        ['name' => 'minutes#resolveCorrection', 'url' => '/api/minutes/{minutesId}/corrections/{correctionId}',   'verb' => 'PUT'],
        ['name' => 'minutes#reject',            'url' => '/api/minutes/{minutesId}/reject',                       'verb' => 'POST'],
        ['name' => 'minutes#generateDocument',  'url' => '/api/minutes/{minutesId}/generate-document',            'verb' => 'POST'],

        // Notarial proof package (minutes-ui-v1) — chair/secretary gated.
        // @spec openspec/specs/resolution-minutes/spec.md
        ['name' => 'meeting#proofPackage', 'url' => '/api/meetings/{id}/proof-package', 'verb' => 'POST'],

        // Decision endpoints — server-side publish enforces governance access control.
        // @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6.2
        ['name' => 'decision#publish', 'url' => '/api/decisions/{decisionId}/publish', 'verb' => 'POST'],

        // Decision lifecycle state machine (guarded transition map).
        // @spec openspec/specs/decision-management/spec.md
        ['name' => 'decision#transition',  'url' => '/api/decisions/{decisionId}/transition',  'verb' => 'POST'],
        ['name' => 'decision#transitions', 'url' => '/api/decisions/{decisionId}/transitions', 'verb' => 'GET'],

        // Meeting lifecycle transitions (CRUD is handled by OpenRegister's object API directly).
        ['name' => 'meeting#lifecycle', 'url' => '/api/meetings/{id}/lifecycle', 'verb' => 'POST'],

        // Recurring series generation + document package assembly
        // (meeting-agenda-gaps-v1). @spec openspec/specs/meeting-management/spec.md
        ['name' => 'meeting#createSeries',    'url' => '/api/meetings/{id}/series',  'verb' => 'POST'],
        ['name' => 'meeting#assemblePackage', 'url' => '/api/meetings/{id}/package', 'verb' => 'POST'],


        // Agenda lifecycle routes (task-1.3) — specific routes BEFORE wildcard catch-all.
        ['name' => 'agenda#publish',             'url' => '/api/agendas/{meetingId}/publish',      'verb' => 'POST'],
        ['name' => 'agenda#revise',              'url' => '/api/agendas/{meetingId}/revise',       'verb' => 'PUT'],
        ['name' => 'agenda#advanceBobPhase',     'url' => '/api/agenda-items/{id}/bob-phase',      'verb' => 'PUT'],
        ['name' => 'agenda#processHamerstukken', 'url' => '/api/agendas/{meetingId}/hamerstukken', 'verb' => 'POST'],
        ['name' => 'agenda#reorder',             'url' => '/api/agendas/{meetingId}/reorder',      'verb' => 'PUT'],

        // Motion lifecycle and co-signature routes (specific before wildcard).
        ['name' => 'motion#transition',     'url' => '/api/motions/{id}/transition',      'verb' => 'POST'],
        ['name' => 'motion#coSignRequest',  'url' => '/api/motions/{id}/co-sign-request', 'verb' => 'POST'],
        ['name' => 'motion#coSignConfirm',  'url' => '/api/motions/{id}/co-sign-confirm', 'verb' => 'POST'],
        ['name' => 'motion#budgetImpact',   'url' => '/api/motions/{id}/budget-impact',   'verb' => 'POST'],

        // Amendment lifecycle routes (specific before wildcard).
        ['name' => 'motion#amendmentTransition', 'url' => '/api/amendments/{id}/transition', 'verb' => 'POST'],

        // Chair-set amendment voting order — @spec openspec/specs/motion-amendment/spec.md.
        ['name' => 'motion#amendmentOrder', 'url' => '/api/motions/{id}/amendment-order', 'verb' => 'POST'],

        // Voting round routes (specific before wildcard).
        ['name' => 'voting#open',        'url' => '/api/voting-rounds',             'verb' => 'POST'],
        ['name' => 'voting#cast',        'url' => '/api/voting-rounds/{id}/cast',   'verb' => 'POST'],
        ['name' => 'voting#close',       'url' => '/api/voting-rounds/{id}/close',  'verb' => 'POST'],
        ['name' => 'voting#publish',     'url' => '/api/voting-rounds/{id}/publish','verb' => 'POST'],
        ['name' => 'voting#tally',       'url' => '/api/voting-rounds/{id}/tally',  'verb' => 'POST'],
        ['name' => 'voting#proxy',       'url' => '/api/voting-rounds/{id}/proxy',  'verb' => 'POST'],
        ['name' => 'voting#revokeProxy', 'url' => '/api/voting-rounds/{id}/proxy',  'verb' => 'DELETE'],

        // Voting behaviour (stats) routes — @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
        ['name' => 'votingBehaviour#getStats', 'url' => '/api/voting-behaviour/{participantId}', 'verb' => 'GET'],

        // Projection public-state routes — @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-2
        ['name' => 'projection#publicState', 'url' => '/api/voting-rounds/{id}/public-state', 'verb' => 'GET'],

        // Motion forwarding routes — @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-3
        ['name' => 'motion#forward', 'url' => '/api/motions/{id}/forward', 'verb' => 'POST'],

        // Governance services (retained from the retired board portal,
        // retargeted onto the unified entities per ADR-006).
        // Conflict-of-interest declarations.
        ['name' => 'conflictOfInterest#declare',     'url' => '/api/conflicts',                  'verb' => 'POST'],
        ['name' => 'conflictOfInterest#forMember',   'url' => '/api/members/{id}/conflicts',     'verb' => 'GET'],
        ['name' => 'conflictOfInterest#recordAction', 'url' => '/api/conflicts/{id}/action',     'verb' => 'PUT'],

        // Audit log (secretary/admin only — enforced inside controller).
        ['name' => 'auditLog#index',  'url' => '/api/audit-log',                'verb' => 'GET'],
        ['name' => 'auditLog#verify', 'url' => '/api/audit-log/{id}/verify',    'verb' => 'GET'],
        ['name' => 'auditLog#export', 'url' => '/api/audit-log/export',         'verb' => 'GET'],

        // eIDAS QES integration on minutes (task-3.3).
        ['name' => 'eIDASSignature#initiate',   'url' => '/api/minutes/{minutesId}/eidas/initiate',  'verb' => 'POST'],
        ['name' => 'eIDASSignature#verify',     'url' => '/api/minutes/{minutesId}/eidas/verify',    'verb' => 'POST'],
        ['name' => 'eIDASSignature#finalize',   'url' => '/api/minutes/{minutesId}/eidas/finalize',  'verb' => 'POST'],
        ['name' => 'eIDASSignature#certStatus', 'url' => '/api/eidas/validate-cert',                 'verb' => 'POST'],

        // Proxy voting (task-5.1).
        ['name' => 'proxyVote#register', 'url' => '/api/proxies',               'verb' => 'POST'],
        ['name' => 'proxyVote#index',    'url' => '/api/proxies',               'verb' => 'GET'],
        ['name' => 'proxyVote#suspend',  'url' => '/api/proxies/{id}/suspend',  'verb' => 'PUT'],
        ['name' => 'proxyVote#revoke',   'url' => '/api/proxies/{id}',          'verb' => 'DELETE'],

        // Governance reporting (task-5.4).
        ['name' => 'governanceReport#generate', 'url' => '/api/governance-reports',                       'verb' => 'POST'],
        ['name' => 'governanceReport#index',    'url' => '/api/governance-reports',                       'verb' => 'GET'],
        ['name' => 'governanceReport#show',     'url' => '/api/governance-reports/{id}',                  'verb' => 'GET'],
        ['name' => 'governanceReport#export',   'url' => '/api/governance-reports/{id}/export/{format}',  'verb' => 'GET'],

        // Regulator export (task-6.1).
        ['name' => 'regulatorExport#generate', 'url' => '/api/regulator-exports',         'verb' => 'POST'],
        ['name' => 'regulatorExport#index',    'url' => '/api/regulator-exports',         'verb' => 'GET'],
        ['name' => 'regulatorExport#download', 'url' => '/api/regulator-exports/{id}',    'verb' => 'GET'],

        // Multilingual reconciliation (task-6.3).
        ['name' => 'multilingualReconciliation#queue',    'url' => '/api/multilingual/queue',          'verb' => 'POST'],
        ['name' => 'multilingualReconciliation#status',   'url' => '/api/multilingual/queue',          'verb' => 'GET'],
        ['name' => 'multilingualReconciliation#process',  'url' => '/api/multilingual/queue/process',  'verb' => 'POST'],

        // Public REST API — versioned v1 (REQ-API-001..004).
        // @spec openspec/changes/p4-integration/tasks.md#task-1
        // @spec openspec/changes/p4-integration/tasks.md#task-2
        // Health check — public, no auth required.
        ['name' => 'health#status',          'url' => '/api/v1/health',  'verb' => 'GET'],
        ['name' => 'health#statusOptions',   'url' => '/api/v1/health',  'verb' => 'OPTIONS'],
        // CORS preflight for the whole v1 surface — must precede the catch-all GET.
        ['name' => 'api#preflight',          'url' => '/api/v1/{resource}',      'verb' => 'OPTIONS', 'requirements' => ['resource' => '[a-z\-]+']],
        ['name' => 'api#preflightItem',      'url' => '/api/v1/{resource}/{id}', 'verb' => 'OPTIONS', 'requirements' => ['resource' => '[a-z\-]+']],
        // Read-only public entity endpoints (REQ-API-002).
        ['name' => 'api#index',              'url' => '/api/v1/{resource}',      'verb' => 'GET', 'requirements' => ['resource' => '[a-z\-]+']],
        ['name' => 'api#show',               'url' => '/api/v1/{resource}/{id}', 'verb' => 'GET', 'requirements' => ['resource' => '[a-z\-]+']],

        // ORI API 1.4 endpoints (REQ-ORI-001..004).
        // @spec openspec/changes/p4-integration/tasks.md#task-11
        ['name' => 'ori#preflight',     'url' => '/api/ori/v1/{resource}',      'verb' => 'OPTIONS', 'requirements' => ['resource' => '[a-z\-]+']],
        ['name' => 'ori#preflightItem', 'url' => '/api/ori/v1/{resource}/{id}', 'verb' => 'OPTIONS', 'requirements' => ['resource' => '[a-z\-]+']],
        ['name' => 'ori#index',         'url' => '/api/ori/v1/{resource}',      'verb' => 'GET', 'requirements' => ['resource' => '[a-z\-]+']],
        ['name' => 'ori#show',          'url' => '/api/ori/v1/{resource}/{id}', 'verb' => 'GET', 'requirements' => ['resource' => '[a-z\-]+']],

        // p4-collaboration routes — @spec openspec/changes/p4-collaboration/tasks.md.
        // Task/Delegation lifecycle routes were retired in
        // migrate-action-items-to-deck-leaf (ADR-022 / task-4.2): action-item
        // content lives on the CalDAV VTODO ActionItem (ADR-002) and the board UI
        // is the Deck integration leaf bound via the ADR-019 registry.

        // Workspace member-management routes retired in
        // migrate-workspaces-to-collectives-leaf (ADR-022 / task-4.2): the
        // faction/committee workspace is now a Nextcloud Collective surfaced via
        // the ADR-019 registry binding declared in
        // lib/Settings/register.d/41-migrate-workspaces-to-collectives-leaf.json,
        // so membership lives on the Collective and object-level RBAC stays in
        // OR AuthorizationService.

        // Notification preference endpoints (own preferences).
        ['name' => 'notificationPreference#show',   'url' => '/api/notification-preference', 'verb' => 'GET'],
        ['name' => 'notificationPreference#update', 'url' => '/api/notification-preference', 'verb' => 'PUT'],

        // Engagement capture and query.
        ['name' => 'engagement#capture', 'url' => '/api/engagement', 'verb' => 'POST'],
        ['name' => 'engagement#index',   'url' => '/api/engagement', 'verb' => 'GET'],

        // Motion co-authoring (specific routes before motion wildcard).
        ['name' => 'motionCoauthor#addCoauthor',    'url' => '/api/motions/{id}/coauthors',            'verb' => 'POST'],
        ['name' => 'motionCoauthor#removeCoauthor', 'url' => '/api/motions/{id}/coauthors/{personId}', 'verb' => 'DELETE'],
        ['name' => 'motionCoauthor#updateText',     'url' => '/api/motions/{id}/text',                 'verb' => 'POST'],
        ['name' => 'motionCoauthor#history',        'url' => '/api/motions/{id}/history',              'verb' => 'GET'],

        // SPA catch-all — same controller as the index route; must use a distinct route name
        // (duplicate names replace the earlier route in Symfony, which breaks GET /).
        ['name' => 'dashboard#catchAll', 'url' => '/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+'], 'defaults' => ['path' => '']],
    ],
];
