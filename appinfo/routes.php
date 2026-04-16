<?php

declare(strict_types=1);

return [
    'routes' => [
        // Dashboard + Settings.
        ['name' => 'dashboard#page', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'settings#index', 'url' => '/api/settings', 'verb' => 'GET'],
        ['name' => 'settings#create', 'url' => '/api/settings', 'verb' => 'POST'],
        ['name' => 'settings#load',  'url' => '/api/settings/load', 'verb' => 'POST'],

        // Meeting endpoints — CalDAV VEVENT storage (ADR-002).
        ['name' => 'meeting#index',     'url' => '/api/meetings',               'verb' => 'GET'],
        ['name' => 'meeting#create',    'url' => '/api/meetings',               'verb' => 'POST'],
        ['name' => 'meeting#show',      'url' => '/api/meetings/{id}',          'verb' => 'GET'],
        ['name' => 'meeting#lifecycle', 'url' => '/api/meetings/{id}/lifecycle', 'verb' => 'POST'],

        // Minutes endpoints — specific routes must precede the wildcard catch-all.
        // @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
        ['name' => 'minutes#generateDraft', 'url' => '/api/minutes/{minutesId}/generate-draft', 'verb' => 'POST'],
        ['name' => 'minutes#transition',    'url' => '/api/minutes/{minutesId}/transition',      'verb' => 'POST'],

        // Motion lifecycle, co-signature, budget impact, and publish routes.
        // Decision publish is on Motion (ADR-001: Decision merged into Motion, Popolo alignment).
        ['name' => 'motion#transition',     'url' => '/api/motions/{id}/transition',      'verb' => 'POST'],
        ['name' => 'motion#publish',        'url' => '/api/motions/{id}/publish',          'verb' => 'POST'],
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
