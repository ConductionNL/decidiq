<?php

declare(strict_types=1);

return [
    'routes' => [
        // Dashboard + Settings.
        ['name' => 'dashboard#page', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'settings#index', 'url' => '/api/settings', 'verb' => 'GET'],
        ['name' => 'settings#create', 'url' => '/api/settings', 'verb' => 'POST'],
        ['name' => 'settings#load',  'url' => '/api/settings/load', 'verb' => 'POST'],

        // Prometheus metrics endpoint.
        ['name' => 'metrics#index', 'url' => '/api/metrics', 'verb' => 'GET'],
        // Health check endpoint.
        ['name' => 'health#index', 'url' => '/api/health', 'verb' => 'GET'],

        // Motion lifecycle and co-signature endpoints — specific routes BEFORE wildcard.
        // @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.3
        ['name' => 'motion#transition',      'url' => '/api/motions/{id}/transition',     'verb' => 'POST'],
        ['name' => 'motion#co_sign_request', 'url' => '/api/motions/{id}/co-sign-request', 'verb' => 'POST'],
        ['name' => 'motion#co_sign_confirm', 'url' => '/api/motions/{id}/co-sign-confirm', 'verb' => 'POST'],
        ['name' => 'motion#budget_impact',   'url' => '/api/motions/{id}/budget-impact',   'verb' => 'POST'],

        // Amendment lifecycle endpoint.
        ['name' => 'motion#amendment_transition', 'url' => '/api/amendments/{id}/transition', 'verb' => 'POST'],

        // Voting round endpoints — specific routes BEFORE generic.
        // @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.3
        ['name' => 'voting#open',        'url' => '/api/voting-rounds',              'verb' => 'POST'],
        ['name' => 'voting#cast',        'url' => '/api/voting-rounds/{id}/cast',    'verb' => 'POST'],
        ['name' => 'voting#close',       'url' => '/api/voting-rounds/{id}/close',   'verb' => 'POST'],
        ['name' => 'voting#publish',     'url' => '/api/voting-rounds/{id}/publish', 'verb' => 'POST'],
        ['name' => 'voting#proxy',       'url' => '/api/voting-rounds/{id}/proxy',   'verb' => 'POST'],
        ['name' => 'voting#revoke_proxy','url' => '/api/voting-rounds/{id}/proxy',   'verb' => 'DELETE'],

        // SPA catch-all — same controller as the index route; must use a distinct route name
        // (duplicate names replace the earlier route in Symfony, which breaks GET /).
        ['name' => 'dashboard#catchAll', 'url' => '/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+'], 'defaults' => ['path' => '']],
    ],
];
