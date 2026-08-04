<?php
/**
 * Decidesk AppHost Registrar
 *
 * AppHost boilerplate adoption (ADR-040 / ADR-022): re-points the mechanical,
 * fleet-standard plumbing at the OpenRegister AppHost generics — keeping
 * decidesk's existing URLs unchanged — while leaving every domain-entangled
 * class bespoke.
 *
 * Extracted from {@see \OCA\Decidesk\AppInfo\Application} so the bootstrap
 * class stops accumulating a class reference for every registration it makes
 * (PHPMD CouplingBetweenObjects); the imports move with the registrations.
 *
 * @category AppInfo
 * @package  OCA\Decidesk\AppInfo\Registrar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/adopt-apphost/tasks.md#task-2
 * @spec openspec/specs/apphost-adoption/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\AppInfo\Registrar;

use OCA\Decidesk\AppInfo\Application;
use OCA\OpenRegister\Event\DeepLinkRegistrationEvent;
use OCP\App\IAppManager;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Log\LoggerInterface;

/**
 * Registers the AppHost-generic plumbing decidesk adopts.
 *
 * Deliberately NOT adopted (kept bespoke — domain behaviour the generics
 * cannot express, per the "don't force" rule):
 *   - `Controller\SettingsController` + `Service\SettingsService`
 *     (decidesk-register import, publication-config CRUD).
 *   - `Settings\AdminSettings` (domain initial state: publication config,
 *     transcript-retention defaults) and `Sections\SettingsSection`,
 *     `Settings\PersonalSettings`, `Sections\PersonalSection`.
 *   - `Repair\InitializeSettings` (voter_token_secret seeding + OR
 *     configuration import).
 *   - `Controller\PreferencesController` — the AppHost has no
 *     `GenericPreferencesController` in OpenRegister development, so the
 *     bespoke per-user preferences controller is retained as-is.
 *
 * @spec openspec/specs/apphost-adoption/spec.md
 */
class AppHostRegistrar
{

    /**
     * FQCN of the AppHost's manifest-driven deep-link registration listener.
     *
     * Referenced as a string, never imported: the class only exists when
     * openregister is installed.
     *
     * @var string
     */
    private const DEEP_LINK_LISTENER = 'OCA\\OpenRegister\\AppHost\\Listener\\GenericDeepLinkRegistrationListener';

    /**
     * Register the adopted AppHost boilerplate.
     *
     * The dashboard / metrics / health route targets are bespoke decidesk
     * controllers (`Controller\DashboardController`,
     * `Controller\MetricsController`, `Controller\HealthController`) that adopt
     * the AppHost by COMPOSITION, not inheritance — concrete classes so the
     * route targets stay reachable (gate-5 / gate-14), and free of any
     * `extends`/import of an OpenRegister class so they still reflect when
     * openregister is absent. They pull the engine's ManifestLoader /
     * MetricsEngine / HealthCheckExecutor out of the container by FQCN string
     * at dispatch time, so no explicit binding is needed here.
     *
     * ⚠️ Do NOT "simplify" those three back into subclasses of the AppHost
     * generics. Nextcloud's router `ReflectionClass()`es every file in
     * `lib/Controller/` while MATCHING a route, so an unresolvable parent
     * returns HTTP 500 for EVERY route in decidesk, and `extends` is resolved
     * by the autoloader — lazy DI cannot rescue it. See decidesk#377.
     *
     * Lazy by construction: the binding is a `registerService` closure, so a
     * disabled OpenRegister never loads an AppHost class at bootstrap (the
     * closure only resolves when a route is dispatched), matching the AppHost
     * fatal-free invariant.
     *
     * @param IRegistrationContext $context The registration context
     *
     * @return void
     *
     * @spec openspec/changes/adopt-apphost/tasks.md#task-2
     * @spec openspec/specs/apphost-adoption/spec.md
     */
    public function register(IRegistrationContext $context): void
    {
        // Generic, manifest-driven deep-link listener replaces the former
        // hand-written listener. Patterns now live in the manifest `deepLinks`
        // block. Fires only when OpenRegister dispatches the event.
        $context->registerService(
            self::DEEP_LINK_LISTENER,
            static function ($c): object {
                $class = self::DEEP_LINK_LISTENER;
                return new $class(
                    appId: Application::APP_ID,
                    appManager: $c->get(IAppManager::class),
                    logger: $c->get(LoggerInterface::class),
                );
            }
        );

        // The listener is an OpenRegister AppHost class that only exists when
        // openregister is installed, so PHPStan cannot verify it is a
        // class-string<IEventListener>. It is registered as a service just above
        // and is only instantiated when OpenRegister dispatches the event.
        $listenerClass = self::DEEP_LINK_LISTENER;
        // @phpstan-ignore-next-line
        $context->registerEventListener(event: DeepLinkRegistrationEvent::class, listener: $listenerClass);

    }//end register()
}//end class
