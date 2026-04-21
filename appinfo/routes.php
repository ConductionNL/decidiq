<?php

declare(strict_types=1);

return [
    'routes' => [
        // Dashboard + Settings.
        ['name' => 'dashboard#page', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'settings#index', 'url' => '/api/settings', 'verb' => 'GET'],
        ['name' => 'settings#create', 'url' => '/api/settings', 'verb' => 'POST'],
        ['name' => 'settings#load',  'url' => '/api/settings/load', 'verb' => 'POST'],

        // Analytics endpoints — action item metrics and completion rates.
        // @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1
        ['name' => 'analytics#getSummary',             'url' => '/api/analytics/action-items',                 'verb' => 'GET'],
        ['name' => 'analytics#getCompletionRates',     'url' => '/api/analytics/action-items/completion-rates', 'verb' => 'GET'],
        ['name' => 'analytics#getMyItems',             'url' => '/api/analytics/action-items/my-items',         'verb' => 'GET'],

        // Live meeting endpoints — live decision recording during active meetings.
        // @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2
        ['name' => 'livemeeting#recordLiveDecision', 'url' => '/api/meetings/{meetingId}/live-decisions', 'verb' => 'POST'],

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

        // Meeting CRUD operations (task 1.4).
        ['name' => 'meeting#index',   'url' => '/api/meetings',      'verb' => 'GET'],
        ['name' => 'meeting#create',  'url' => '/api/meetings',      'verb' => 'POST'],
        ['name' => 'meeting#show',    'url' => '/api/meetings/{id}', 'verb' => 'GET'],
        ['name' => 'meeting#update',  'url' => '/api/meetings/{id}', 'verb' => 'PUT'],
        ['name' => 'meeting#destroy', 'url' => '/api/meetings/{id}', 'verb' => 'DELETE'],

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
