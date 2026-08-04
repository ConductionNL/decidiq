<?php
/**
 * Decidesk Object Listener Registrar
 *
 * Subscribes decidesk's OpenRegister object-lifecycle listeners with their
 * schema interest declared up front, so an uninterested listener is neither
 * constructed nor invoked.
 *
 * Extracted from {@see \OCA\Decidesk\AppInfo\Application} so the bootstrap
 * class stops accumulating a class reference for every listener and event it
 * subscribes (PHPMD CouplingBetweenObjects); the imports move with the
 * subscriptions.
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
 * @spec openspec/specs/nextcloud-integration/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\AppInfo\Registrar;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Listener\GovernanceRoleProjectionListener;
use OCA\Decidesk\Listener\MeetingFolderListener;
use OCA\Decidesk\Listener\SubmissionDeadlineListener;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;

/**
 * Filtered object-lifecycle listener subscriptions.
 *
 * MUST be driven from boot(), never from register(). Nextcloud enables each
 * app's own autoloader immediately before calling that app's register(), so
 * during register() OpenRegister's classes are only autoloadable to apps that
 * happen to be registered after it — the class_exists() guard below would then
 * resolve differently purely by app load order and silently fall back to an
 * unfiltered registration. boot() runs only after every app's register() has
 * completed, so the guard is order-independent there.
 *
 * @spec openspec/specs/nextcloud-integration/spec.md
 */
class ObjectListenerRegistrar
{

    /**
     * FQCN of OpenRegister's filtered-subscription helper.
     *
     * Referenced as a string, never imported: the class only exists when
     * openregister is installed.
     *
     * @var string
     */
    private const SUBSCRIPTION = '\\OCA\\OpenRegister\\Event\\ObjectEventSubscription';

    /**
     * Construct the ObjectListenerRegistrar.
     *
     * @param LoggerInterface $logger Logger used for the unfiltered-fallback warning
     *
     * @spec openspec/specs/nextcloud-integration/spec.md
     */
    public function __construct(private readonly LoggerInterface $logger)
    {
    }//end __construct()

    /**
     * Subscribe every decidesk object-lifecycle listener.
     *
     * @param IEventDispatcher $dispatcher The live event dispatcher
     *
     * @return void
     *
     * @spec openspec/specs/nextcloud-integration/spec.md
     */
    public function register(IEventDispatcher $dispatcher): void
    {
        // Declares its schema interest at subscription time instead of
        // re-deriving it on every dispatch: the handler's first guard is
        // `resolveSchemaSlug(...) !== MeetingFolderListener::SCHEMA_MEETING`
        // (= the `meeting` schema slug). Registered globally it was
        // constructed and invoked on every object create on the instance —
        // a larpingapp character create reached `handle()` and bailed at that
        // guard. No register is declared: schema-only is exactly the guard
        // the handler already applies, and stays correct if a Decidesk
        // deployment ever splits meetings across registers.
        $this->subscribe(
            dispatcher: $dispatcher,
            event: ObjectCreatedEvent::class,
            listener: MeetingFolderListener::class,
            registers: null,
            schemas: ['meeting']
        );

        // Same narrowing as MeetingFolderListener above. The declared slug list
        // is `GovernanceRoleProjectionListener::ROSTER_SCHEMAS` verbatim — the
        // handler bails on
        // `in_array($slug, self::ROSTER_SCHEMAS, true) === false` — so the
        // declaration cannot be narrower than the guard it fronts.
        foreach ([ObjectCreatedEvent::class, ObjectUpdatedEvent::class, ObjectDeletedEvent::class] as $rosterEvent) {
            $this->subscribe(
                dispatcher: $dispatcher,
                event: $rosterEvent,
                listener: GovernanceRoleProjectionListener::class,
                registers: null,
                schemas: ['participant', 'membership']
            );
        }

        // Submission deadline gate (motion-amendment spec). Declared interest is
        // the handler's own literal guard verbatim —
        // `in_array($slug, ['motion', 'amendment'], true) === false` — so the
        // declaration cannot be narrower than the guard it fronts.
        $this->subscribe(
            dispatcher: $dispatcher,
            event: ObjectCreatingEvent::class,
            listener: SubmissionDeadlineListener::class,
            registers: null,
            schemas: ['motion', 'amendment']
        );

    }//end register()

    /**
     * Register an object-lifecycle listener that declares its interest up front.
     *
     * OpenRegister's `ObjectEventSubscription` records the register/schema slugs
     * a listener reacts to and routes dispatches through a single shared proxy,
     * so an uninterested listener is neither constructed nor invoked. When
     * OpenRegister is absent — Decidesk carries no hard dependency on it — this
     * degrades to the plain global registration it replaced, which is exactly
     * the behaviour every listener had before.
     *
     * @param IEventDispatcher       $dispatcher The live event dispatcher.
     * @param string                 $event      OpenRegister event class name
     * @param string                 $listener   Listener class name
     * @param array<int,string>|null $registers  Register slugs the listener reacts to, or null for all
     * @param array<int,string>|null $schemas    Schema slugs the listener reacts to, or null for all
     *
     * @return void
     *
     * @spec openspec/specs/nextcloud-integration/spec.md
     */
    private function subscribe(
        IEventDispatcher $dispatcher,
        string $event,
        string $listener,
        ?array $registers,
        ?array $schemas
    ): void {
        $subscription = self::SUBSCRIPTION;
        if (class_exists($subscription) === true) {
            $subscription::subscribe(
                dispatcher: $dispatcher,
                event: $event,
                listener: $listener,
                registers: $registers,
                schemas: $schemas
            );
            return;
        }

        // Loud on purpose. This fallback is correct but UNFILTERED, and while it
        // was silent it was indistinguishable from a working narrowing.
        $this->logger->warning(
            'OpenRegister ObjectEventSubscription unavailable: '.$listener
            .' fell back to an UNFILTERED registration for '.$event
            .' and will be invoked on every object write instance-wide.',
            ['app' => Application::APP_ID]
        );

        $dispatcher->addServiceListener($event, $listener);

    }//end subscribe()
}//end class
