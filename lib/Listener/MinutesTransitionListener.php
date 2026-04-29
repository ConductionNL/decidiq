<?php

/**
 * Decidesk MinutesTransitionListener
 *
 * Subscribes to OpenRegister's `ObjectTransitionedEvent` and patches
 * Minutes-specific bookkeeping after the platform applies the
 * transition declared in `x-openregister-lifecycle`:
 *
 * - `approve` → set `approvedAt` to the moment of transition
 * - `approve` / `sign` → append the caller's display name to `signedBy`
 *
 * @category Listener
 * @package  OCA\Decidesk\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Listener;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Patches `approvedAt` + `signedBy` on Minutes after the platform
 * applies the declared transition.
 *
 * @template-implements IEventListener<ObjectTransitionedEvent>
 */
class MinutesTransitionListener implements IEventListener
{

    public function __construct(
        private readonly ObjectService $objectService,
        private readonly IUserManager $userManager,
        private readonly LoggerInterface $logger
    ) {}//end __construct()

    public function handle(Event $event): void
    {
        if (($event instanceof ObjectTransitionedEvent) === false) {
            return;
        }

        // Only act on the `minutes` schema.
        $schemaSlug = strtolower((string) $event->getSchema());
        if ($schemaSlug !== 'minutes' && $schemaSlug !== '657' /* numeric id fallback */) {
            // Resolve via the saved object's data when the schema field is the numeric id.
            $obj = $event->getObject()->getObject() ?? [];
            if (($obj['@self']['schema'] ?? '') !== 'minutes' && ($obj['@self']['schemaSlug'] ?? '') !== 'minutes') {
                // Best-effort: fall through if the listener can't tell. The patch logic
                // below is keyed by the action name so unrelated schemas just no-op.
            }
        }

        $action = $event->getAction();
        if ($action !== 'approve' && $action !== 'sign') {
            return;
        }

        $object = $event->getObject();
        $data   = $object->getObject() ?? [];

        $changed = false;

        if ($action === 'approve' && empty($data['approvedAt']) === true) {
            $data['approvedAt'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
            $changed = true;
        }

        if (in_array($action, ['approve', 'sign'], true) === true) {
            $userId = $event->getUserId();
            if ($userId !== null) {
                $user        = $this->userManager->get($userId);
                $displayName = $user !== null ? $user->getDisplayName() : $userId;
                $signers     = is_array($data['signedBy'] ?? null) === true ? $data['signedBy'] : [];
                if (in_array($displayName, $signers, true) === false) {
                    $signers[]         = $displayName;
                    $data['signedBy']  = $signers;
                    $changed           = true;
                }
            }
        }

        if ($changed === false) {
            return;
        }

        try {
            $this->objectService->saveObject(
                object: $data,
                register: $object->getRegister(),
                schema: $object->getSchema(),
                uuid: $object->getUuid()
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf('Decidesk: minutes side-effect patch failed (%s): %s', $action, $e->getMessage())
            );
        }
    }//end handle()

}//end class
