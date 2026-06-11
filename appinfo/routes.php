<?php

declare(strict_types=1);

return [
    'routes' => [
        // Dashboard + Settings.
        ['name' => 'dashboard#page', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'settings#index', 'url' => '/api/settings', 'verb' => 'GET'],
        ['name' => 'settings#create', 'url' => '/api/settings', 'verb' => 'POST'],
        ['name' => 'settings#load',  'url' => '/api/settings/load', 'verb' => 'POST'],

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

        // Decision endpoints — server-side publish enforces governance access control.
        // @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6.2
        ['name' => 'decision#publish', 'url' => '/api/decisions/{decisionId}/publish', 'verb' => 'POST'],

        // Meeting lifecycle transitions (CRUD is handled by OpenRegister's object API directly).
        ['name' => 'meeting#lifecycle', 'url' => '/api/meetings/{id}/lifecycle', 'verb' => 'POST'],


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

        // Board portal — @spec openspec/changes/board-meeting-resolutions/tasks.md
        // Board CRUD.
        ['name' => 'board#index',  'url' => '/api/boards',      'verb' => 'GET'],
        ['name' => 'board#create', 'url' => '/api/boards',      'verb' => 'POST'],
        ['name' => 'board#show',   'url' => '/api/boards/{id}', 'verb' => 'GET'],
        ['name' => 'board#update', 'url' => '/api/boards/{id}', 'verb' => 'PUT'],

        // Board member management.
        ['name' => 'boardMember#index',     'url' => '/api/boards/{boardId}/members',                'verb' => 'GET'],
        ['name' => 'boardMember#invite',    'url' => '/api/boards/{boardId}/members',                'verb' => 'POST'],
        ['name' => 'boardMember#remove',    'url' => '/api/board-members/{id}',                      'verb' => 'DELETE'],
        ['name' => 'boardMember#changeRole', 'url' => '/api/board-members/{id}/role',                'verb' => 'PUT'],

        // Board meeting lifecycle.
        ['name' => 'boardMeeting#schedule',  'url' => '/api/boards/{boardId}/meetings',            'verb' => 'POST'],
        ['name' => 'boardMeeting#sendNotice', 'url' => '/api/board-meetings/{id}/send-notice',     'verb' => 'POST'],
        ['name' => 'boardMeeting#transition', 'url' => '/api/board-meetings/{id}/lifecycle',       'verb' => 'POST'],

        // Resolutions.
        ['name' => 'resolution#propose',  'url' => '/api/board-meetings/{meetingId}/resolutions', 'verb' => 'POST'],
        ['name' => 'resolution#amend',    'url' => '/api/resolutions/{id}',                       'verb' => 'PUT'],
        ['name' => 'resolution#openVote', 'url' => '/api/resolutions/{id}/open-vote',             'verb' => 'POST'],
        ['name' => 'resolution#conclude', 'url' => '/api/resolutions/{id}/conclude',              'verb' => 'POST'],

        // Board votes.
        ['name' => 'boardVote#cast',  'url' => '/api/resolutions/{resolutionId}/votes', 'verb' => 'POST'],
        ['name' => 'boardVote#tally', 'url' => '/api/resolutions/{resolutionId}/tally', 'verb' => 'GET'],
        ['name' => 'boardVote#audit', 'url' => '/api/resolutions/{resolutionId}/audit', 'verb' => 'GET'],

        // Board materials.
        ['name' => 'boardMaterial#index',    'url' => '/api/boards/{boardId}/materials', 'verb' => 'GET'],
        ['name' => 'boardMaterial#show',     'url' => '/api/board-materials/{id}',       'verb' => 'GET'],
        ['name' => 'boardMaterial#download', 'url' => '/api/board-materials/{id}/download', 'verb' => 'POST'],

        // Conflict-of-interest declarations.
        ['name' => 'conflictOfInterest#declare',     'url' => '/api/conflicts',                  'verb' => 'POST'],
        ['name' => 'conflictOfInterest#forMember',   'url' => '/api/board-members/{id}/conflicts', 'verb' => 'GET'],
        ['name' => 'conflictOfInterest#recordAction', 'url' => '/api/conflicts/{id}/action',     'verb' => 'PUT'],

        // Audit log (secretary/admin only — enforced inside controller).
        ['name' => 'auditLog#index',  'url' => '/api/audit-log',                'verb' => 'GET'],
        ['name' => 'auditLog#verify', 'url' => '/api/audit-log/{id}/verify',    'verb' => 'GET'],
        ['name' => 'auditLog#export', 'url' => '/api/audit-log/export',         'verb' => 'GET'],

        // Board portal Phase 4 — eIDAS QES integration (task-3.3).
        ['name' => 'EIDASSignature#initiate',     'url' => '/api/minutes/{minutesId}/eidas/initiate',  'verb' => 'POST'],
        ['name' => 'EIDASSignature#verify',       'url' => '/api/minutes/{minutesId}/eidas/verify',    'verb' => 'POST'],
        ['name' => 'EIDASSignature#finalize',     'url' => '/api/minutes/{minutesId}/eidas/finalize',  'verb' => 'POST'],
        ['name' => 'EIDASSignature#validateCert', 'url' => '/api/eidas/validate-cert',                 'verb' => 'POST'],

        // Board portal Phase 5 — Proxy voting (task-5.1).
        ['name' => 'proxyVote#register', 'url' => '/api/proxies',               'verb' => 'POST'],
        ['name' => 'proxyVote#index',    'url' => '/api/proxies',               'verb' => 'GET'],
        ['name' => 'proxyVote#suspend',  'url' => '/api/proxies/{id}/suspend',  'verb' => 'PUT'],
        ['name' => 'proxyVote#revoke',   'url' => '/api/proxies/{id}',          'verb' => 'DELETE'],

        // Board portal Phase 5 — Governance reporting (task-5.4).
        ['name' => 'governanceReport#generate', 'url' => '/api/governance-reports',                       'verb' => 'POST'],
        ['name' => 'governanceReport#index',    'url' => '/api/governance-reports',                       'verb' => 'GET'],
        ['name' => 'governanceReport#show',     'url' => '/api/governance-reports/{id}',                  'verb' => 'GET'],
        ['name' => 'governanceReport#export',   'url' => '/api/governance-reports/{id}/export/{format}',  'verb' => 'GET'],

        // Board portal Phase 6 — Regulator export (task-6.1).
        ['name' => 'regulatorExport#generate', 'url' => '/api/regulator-exports',         'verb' => 'POST'],
        ['name' => 'regulatorExport#download', 'url' => '/api/regulator-exports/{id}',    'verb' => 'GET'],

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

        // Workspace member management.
        ['name' => 'workspace#addMember',    'url' => '/api/workspaces/{id}/members',            'verb' => 'POST'],
        ['name' => 'workspace#removeMember', 'url' => '/api/workspaces/{id}/members/{personId}', 'verb' => 'DELETE'],

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
