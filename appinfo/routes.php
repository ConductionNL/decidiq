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

        // Motion lifecycle routes.
        ['name' => 'motion#transition', 'url' => '/api/motions/{id}/transition', 'verb' => 'POST'],
        ['name' => 'motion#coSignRequest', 'url' => '/api/motions/{id}/co-sign-request', 'verb' => 'POST'],
        ['name' => 'motion#coSignConfirm', 'url' => '/api/motions/{id}/co-sign-confirm', 'verb' => 'POST'],
        ['name' => 'motion#budgetImpact', 'url' => '/api/motions/{id}/budget-impact', 'verb' => 'POST'],
        ['name' => 'motion#amendmentTransition', 'url' => '/api/amendments/{id}/transition', 'verb' => 'POST'],

        // Voting round routes.
        ['name' => 'voting#open', 'url' => '/api/voting-rounds', 'verb' => 'POST'],
        ['name' => 'voting#cast', 'url' => '/api/voting-rounds/{id}/cast', 'verb' => 'POST'],
        ['name' => 'voting#close', 'url' => '/api/voting-rounds/{id}/close', 'verb' => 'POST'],
        ['name' => 'voting#publish', 'url' => '/api/voting-rounds/{id}/publish', 'verb' => 'POST'],
        ['name' => 'voting#grantProxy', 'url' => '/api/voting-rounds/{id}/proxy', 'verb' => 'POST'],
        ['name' => 'voting#revokeProxy', 'url' => '/api/voting-rounds/{id}/proxy', 'verb' => 'DELETE'],

        // SPA catch-all — same controller as the index route; must use a distinct route name
        // (duplicate names replace the earlier route in Symfony, which breaks GET /).
        ['name' => 'dashboard#catchAll', 'url' => '/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+'], 'defaults' => ['path' => '']],
    ],
];
