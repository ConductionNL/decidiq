<?php

declare(strict_types=1);

return [
    'routes' => [
        // Dashboard + Settings.
        ['name' => 'dashboard#page', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'settings#index', 'url' => '/api/settings', 'verb' => 'GET'],
        ['name' => 'settings#create', 'url' => '/api/settings', 'verb' => 'POST'],
        ['name' => 'settings#load',  'url' => '/api/settings/load', 'verb' => 'POST'],

        // Agenda lifecycle routes (task-1.3) — specific routes BEFORE wildcard catch-all.
        ['name' => 'agenda#publish',             'url' => '/api/agendas/{meetingId}/publish',      'verb' => 'POST'],
        ['name' => 'agenda#advanceBobPhase',     'url' => '/api/agenda-items/{id}/bob-phase',      'verb' => 'PUT'],
        ['name' => 'agenda#processHamerstukken', 'url' => '/api/agendas/{meetingId}/hamerstukken', 'verb' => 'POST'],
        ['name' => 'agenda#reorder',             'url' => '/api/agendas/{meetingId}/reorder',      'verb' => 'PUT'],

        // SPA catch-all — same controller as the index route; must use a distinct route name
        // (duplicate names replace the earlier route in Symfony, which breaks GET /).
        ['name' => 'dashboard#catchAll', 'url' => '/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+'], 'defaults' => ['path' => '']],
    ],
];
