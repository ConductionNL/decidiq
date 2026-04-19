<?php

declare(strict_types=1);

return [
    'routes' => [
        // Dashboard + Settings.
        ['name' => 'dashboard#page', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'settings#index', 'url' => '/api/settings', 'verb' => 'GET'],
        ['name' => 'settings#create', 'url' => '/api/settings', 'verb' => 'POST'],
        ['name' => 'settings#load',  'url' => '/api/settings/load', 'verb' => 'POST'],

        // Minutes endpoints — specific routes must precede the wildcard catch-all.
        // @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
        ['name' => 'minutes#generateDraft', 'url' => '/api/minutes/{minutesId}/generate-draft', 'verb' => 'POST'],
        ['name' => 'minutes#transition',    'url' => '/api/minutes/{minutesId}/transition',      'verb' => 'POST'],

        // Decision endpoints — server-side publish enforces governance access control.
        // @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6.2
        ['name' => 'decision#publish', 'url' => '/api/decisions/{decisionId}/publish', 'verb' => 'POST'],

        // Decision portal publication (T2).
        // @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-4
        ['name' => 'decision#publishPortal', 'url' => '/api/decisions/{decisionId}/publish-portal', 'verb' => 'POST'],
        ['name' => 'decision#getShareLink', 'url' => '/api/decisions/{decisionId}/share-link', 'verb' => 'GET'],

        // Decision public endpoint (T2) — must precede wildcard routes.
        // @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-4
        ['name' => 'decisionPublic#getPublicDecision', 'url' => '/api/decisions/{id}/public', 'verb' => 'GET'],
        ['name' => 'decisionPublic#optionsPublicDecision', 'url' => '/api/decisions/{id}/public', 'verb' => 'OPTIONS'],

        // Decision search (Smart Picker).
        // @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-6
        ['name' => 'decisionSearch#search', 'url' => '/api/decisions/search', 'verb' => 'GET'],

        // Notification subscriptions (T2).
        // @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-1
        ['name' => 'notificationSubscription#getSubscription', 'url' => '/api/notifications/{objectType}/{id}/subscriptions', 'verb' => 'GET'],
        ['name' => 'notificationSubscription#subscribe', 'url' => '/api/notifications/{objectType}/{id}/subscriptions', 'verb' => 'POST'],
        ['name' => 'notificationSubscription#unsubscribe', 'url' => '/api/notifications/{objectType}/{id}/subscriptions', 'verb' => 'DELETE'],

        // Minutes version endpoints (T2).
        // @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-2
        ['name' => 'minutesVersion#getVersionHistory', 'url' => '/api/minutes/{id}/versions', 'verb' => 'GET'],
        ['name' => 'minutesVersion#getVersionContent', 'url' => '/api/minutes/{id}/versions/{version}', 'verb' => 'GET'],
        ['name' => 'minutesVersion#diffVersions', 'url' => '/api/minutes/{id}/versions/{versionA}/diff/{versionB}', 'verb' => 'GET'],

        // Minutes approval endpoints (T2).
        // @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-3
        ['name' => 'minutesApproval#approve', 'url' => '/api/minutes/{id}/approve', 'verb' => 'POST'],
        ['name' => 'minutesApproval#sign', 'url' => '/api/minutes/{id}/sign', 'verb' => 'POST'],
        ['name' => 'minutesApproval#publish', 'url' => '/api/minutes/{id}/publish', 'verb' => 'POST'],
        ['name' => 'minutesApproval#getApprovalStatus', 'url' => '/api/minutes/{id}/approval-status', 'verb' => 'GET'],

        // Meeting lifecycle transitions.
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

        // SPA catch-all — same controller as the index route; must use a distinct route name
        // (duplicate names replace the earlier route in Symfony, which breaks GET /).
        ['name' => 'dashboard#catchAll', 'url' => '/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+'], 'defaults' => ['path' => '']],
    ],
];
