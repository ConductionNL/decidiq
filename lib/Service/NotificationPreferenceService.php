<?php
/**
 * Decidesk Notification Preference Service
 *
 * Stateless service handling per-person notification preferences. Stores
 * preferences as OpenRegister objects (one per Person) so other services
 * can query them server-side before dispatching alerts.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p4-collaboration/tasks.md#task-7
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use DateTimeImmutable;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for reading, updating, and consulting NotificationPreference objects.
 *
 * @spec openspec/changes/p4-collaboration/tasks.md#task-7.1
 */
class NotificationPreferenceService
{

    /**
     * Default preferences when no record exists for a person.
     *
     * @var array<string, mixed>
     */
    public const DEFAULTS = [
        'meetingCreated'        => true,
        'votingOpened'          => true,
        'decisionPublished'     => true,
        'taskAssigned'          => true,
        'commentMention'        => true,
        'meetingReminder'       => true,
        'reminderTimes'         => ['24h', '1h'],
        'deliveryMethod'        => 'in-app',
        'delegate'              => null,
        'delegationFrom'        => null,
        'delegationUntil'       => null,
        'governanceEmail'       => null,
        'urgentPhone'           => null,
        'communicationLanguage' => null,
    ];

    /**
     * Construct the NotificationPreferenceService.
     *
     * @param ContainerInterface $container DI container (lazy-loads OR services)
     * @param LoggerInterface    $logger    Logger interface
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-7.1
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the OpenRegister ObjectService from the container.
     *
     * @return object
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-7.1
     */
    private function getObjectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');

    }//end getObjectService()

    /**
     * Find a NotificationPreference object for a person.
     *
     * @param string $personId Person UUID or user ID
     *
     * @return array<string, mixed>|null
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-7.1
     */
    public function findPreference(string $personId): ?array
    {
        try {
            $objectService = $this->getObjectService();
            $objectService->setRegister('decidesk');
            $objectService->setSchema('notification-preference');

            // ObjectService::findAll() takes a single $config array — the
            // named-argument form (limit:/offset:/filters:) threw
            // "Unknown named parameter" and was swallowed by the catch below,
            // leaving this lookup permanently returning null.
            $results = $objectService->findAll(
                [
                    'filters' => ['person' => $personId],
                    'limit'   => 1,
                    'offset'  => 0,
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->debug(
                'Decidesk: findPreference failed',
                ['personId' => $personId, 'error' => $e->getMessage()]
            );
            return null;
        }//end try

        foreach ($results as $entity) {
            if (is_object($entity) === true && method_exists($entity, 'getObject') === true) {
                return $entity->getObject();
            }

            if (is_array($entity) === true) {
                return $entity;
            }
        }

        return null;

    }//end findPreference()

    /**
     * Create or update a NotificationPreference object for a person.
     *
     * @param string               $personId    Person UUID or user ID
     * @param array<string, mixed> $preferences Preference fields
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-7.1
     */
    public function updatePreference(string $personId, array $preferences): array
    {
        $existing         = $this->findPreference(personId: $personId);
        $merged           = array_merge(self::DEFAULTS, ($existing ?? []), $preferences);
        $merged['person'] = $personId;

        $objectService = $this->getObjectService();
        $saved         = $objectService->saveObject(
            object: $merged,
            register: 'decidesk',
            schema: 'notification-preference',
        );

        $this->logger->info('Decidesk: NotificationPreference updated', ['personId' => $personId]);

        if (is_array($saved) === true) {
            return $saved;
        }

        if (is_object($saved) === true && method_exists($saved, 'getObject') === true) {
            return (array) $saved->getObject();
        }

        return (array) $saved;

    }//end updatePreference()

    // Removed: createPreference() was a self-described "(alias)" whose whole
    // body delegated to updatePreference(), which is itself an upsert ("Create
    // or update"). It had no callers anywhere in lib/, src/ or tests/, so
    // nothing wrote through it and nothing can be orphaned by its removal —
    // the one storage path is unchanged and still reached by everything that
    // was already using it, including NotificationPreferenceController.
    // Two names for one write is how the two drift apart later.

    /**
     * Determine if a given event type should produce a notification for the person.
     *
     * @param string $personId  Person UUID or user ID
     * @param string $eventType One of: meetingCreated, votingOpened, decisionPublished,
     *                          taskAssigned, commentMention
     *
     * @return bool
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-7.1
     */
    public function shouldNotify(string $personId, string $eventType): bool
    {
        $pref   = $this->findPreference(personId: $personId);
        $merged = array_merge(self::DEFAULTS, ($pref ?? []));

        return (bool) ($merged[$eventType] ?? false);

    }//end shouldNotify()

    /**
     * Get the person's preference merged over the defaults.
     *
     * Always returns every known preference field so REST consumers (the
     * settings UI) see the effective configuration, not a sparse object.
     *
     * @param string $personId Person UUID or user ID
     *
     * @return array<string, mixed>
     *
     * @spec openspec/specs/user-settings/spec.md
     */
    public function getPreferenceWithDefaults(string $personId): array
    {
        $pref   = $this->findPreference(personId: $personId);
        $merged = array_merge(self::DEFAULTS, ($pref ?? []));
        $merged['person'] = $personId;

        return $merged;

    }//end getPreferenceWithDefaults()

    /**
     * Resolve the person's active absence delegate, if any.
     *
     * A delegation is active only while `delegationFrom <= today <=
     * delegationUntil` (dates inclusive; a missing `delegationFrom` means
     * "already started"). Expiry is therefore automatic — every consult is a
     * date comparison against today, no cron or cleanup job involved.
     *
     * @param string                 $personId Person UUID or user ID of the absent user
     * @param DateTimeImmutable|null $today    Clock override for tests (defaults to today)
     *
     * @return string|null The delegate identifier (NC UID), or null when no delegation is active
     *
     * @spec openspec/specs/user-settings/spec.md
     */
    public function getActiveDelegate(string $personId, ?DateTimeImmutable $today=null): ?string
    {
        $pref     = $this->findPreference(personId: $personId);
        $delegate = ($pref['delegate'] ?? null);
        if (is_string($delegate) === false || $delegate === '') {
            return null;
        }

        $until = ($pref['delegationUntil'] ?? null);
        if (is_string($until) === false || $until === '') {
            // No expiry configured — treat as not active (the spec requires
            // delegation to expire automatically, so an unbounded delegation
            // is never honoured).
            return null;
        }

        $todayStr = ($today ?? new DateTimeImmutable())->format('Y-m-d');

        $from = ($pref['delegationFrom'] ?? null);
        if (is_string($from) === true && $from !== '' && substr($from, 0, 10) > $todayStr) {
            return null;
        }

        if (substr($until, 0, 10) < $todayStr) {
            return null;
        }

        return $delegate;

    }//end getActiveDelegate()

    /**
     * Check whether $delegatorId currently has an active absence delegation to $delegateId.
     *
     * Used by the voting gate (delegation never grants voting rights — a
     * formal proxy/volmacht is required). The delegate is matched on the
     * stored identifier, which is the NC UID picked in the settings UI;
     * callers may pass either the NC UID or a participant UUID.
     *
     * @param string                 $delegatorId The absent user (preference owner)
     * @param string                 $delegateId  The acting user (NC UID or participant UUID)
     * @param DateTimeImmutable|null $today       Clock override for tests
     *
     * @return bool
     *
     * @spec openspec/specs/user-settings/spec.md
     */
    public function hasActiveDelegationTo(string $delegatorId, string $delegateId, ?DateTimeImmutable $today=null): bool
    {
        if ($delegateId === '') {
            return false;
        }

        $active = $this->getActiveDelegate(personId: $delegatorId, today: $today);

        return $active !== null && $active === $delegateId;

    }//end hasActiveDelegationTo()

    /**
     * Expand a notification recipient to include their active delegate.
     *
     * Per the user-settings spec, the delegate receives all of the absent
     * member's Decidesk notifications during the configured period (and can
     * thereby view the same pending votes and action items via the deep
     * links) — read-only coverage, never voting rights.
     *
     * @param string $personId Person UUID or user ID
     *
     * @return string[] The person plus (when active) their delegate
     *
     * @spec openspec/specs/user-settings/spec.md
     */
    public function getNotificationRecipients(string $personId): array
    {
        $recipients = [$personId];

        $delegate = $this->getActiveDelegate(personId: $personId);
        if ($delegate !== null && $delegate !== $personId) {
            $recipients[] = $delegate;
        }

        return $recipients;

    }//end getNotificationRecipients()

    /**
     * Resolve the e-mail address governance communications should go to.
     *
     * The preference's `governanceEmail` override wins; otherwise the
     * Nextcloud account e-mail is used (the spec default).
     *
     * @param string $personId Person UUID or user ID (NC UID for the account fallback)
     *
     * @return string|null The e-mail address, or null when neither is configured
     *
     * @spec openspec/specs/user-settings/spec.md
     */
    public function getGovernanceEmail(string $personId): ?string
    {
        $pref     = $this->findPreference(personId: $personId);
        $override = ($pref['governanceEmail'] ?? null);
        if (is_string($override) === true && $override !== '') {
            return $override;
        }

        try {
            $userManager = $this->container->get(\OCP\IUserManager::class);
            $user        = $userManager->get($personId);
            $email       = $user?->getEMailAddress();
            if (is_string($email) === true && $email !== '') {
                return $email;
            }
        } catch (\Throwable $e) {
            $this->logger->debug(
                'Decidesk: account email lookup failed',
                ['personId' => $personId, 'error' => $e->getMessage()]
            );
        }

        return null;

    }//end getGovernanceEmail()

    /**
     * Preference-aware, delegation-aware notification dispatch.
     *
     * 1. Event filter — the originating person's own toggle decides whether
     *    the event notifies at all (their delegate inherits exactly what the
     *    absent member would have received).
     * 2. Recipient expansion — the person plus their active delegate.
     * 3. Channel selection per recipient — each recipient's own
     *    `deliveryMethod` decides in-app (OpenRegister notification service)
     *    and/or e-mail (IMailer, to governanceEmail ?? account email).
     *
     * Fail-soft by design: a failing channel is logged and never breaks the
     * calling flow (publishing a decision / opening a voting round must not
     * depend on notification delivery).
     *
     * @param string $personId  The originating recipient (NC UID)
     * @param string $eventType One of the DEFAULTS event-toggle keys
     * @param string $title     Notification title
     * @param string $message   Notification body
     * @param string $deepLink  In-app deep link (app-relative, e.g. /decisions/{id})
     *
     * @return int Number of channel deliveries performed
     *
     * @spec openspec/specs/user-settings/spec.md
     */
    public function dispatch(string $personId, string $eventType, string $title, string $message, string $deepLink=''): int
    {
        if ($this->shouldNotify(personId: $personId, eventType: $eventType) === false) {
            return 0;
        }

        $sent = 0;
        foreach ($this->getNotificationRecipients(personId: $personId) as $recipientId) {
            $method = (string) ($this->getPreferenceWithDefaults(personId: $recipientId)['deliveryMethod'] ?? 'in-app');

            if ($method === 'in-app' || $method === 'both') {
                $sent += $this->sendInApp(recipientId: $recipientId, title: $title, message: $message, deepLink: $deepLink);
            }

            if ($method === 'email' || $method === 'both') {
                $sent += $this->sendEmail(recipientId: $recipientId, title: $title, message: $message);
            }
        }

        return $sent;

    }//end dispatch()

    /**
     * Send one in-app Nextcloud notification (fail-soft).
     *
     * @param string $recipientId Recipient NC UID
     * @param string $title       Notification title
     * @param string $message     Notification body
     * @param string $deepLink    App-relative deep link
     *
     * @return int 1 on success, 0 on failure
     *
     * @spec openspec/specs/user-settings/spec.md
     */
    private function sendInApp(string $recipientId, string $title, string $message, string $deepLink): int
    {
        try {
            $notificationService = $this->container->get('OpenRegisterNotificationService');
            $notificationService->sendNotification(
                userId: $recipientId,
                title: $title,
                message: $message,
                deepLink: $deepLink
            );
            return 1;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Decidesk: in-app notification failed',
                ['recipientId' => $recipientId, 'error' => $e->getMessage()]
            );
            return 0;
        }

    }//end sendInApp()

    /**
     * Send one e-mail to the recipient's governance address (fail-soft).
     *
     * @param string $recipientId Recipient NC UID
     * @param string $title       E-mail subject
     * @param string $message     E-mail plain-text body
     *
     * @return int 1 on success, 0 on failure (or no address available)
     *
     * @spec openspec/specs/user-settings/spec.md
     */
    private function sendEmail(string $recipientId, string $title, string $message): int
    {
        try {
            $address = $this->getGovernanceEmail(personId: $recipientId);
            if ($address === null) {
                $this->logger->debug('Decidesk: no governance email for recipient', ['recipientId' => $recipientId]);
                return 0;
            }

            $mailer       = $this->container->get(\OCP\Mail\IMailer::class);
            $emailMessage = $mailer->createMessage();
            $emailMessage->setTo([$address]);
            $emailMessage->setSubject($title);
            $emailMessage->setPlainBody($message);
            $mailer->send($emailMessage);
            return 1;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Decidesk: notification email failed',
                ['recipientId' => $recipientId, 'error' => $e->getMessage()]
            );
            return 0;
        }//end try

    }//end sendEmail()
}//end class
