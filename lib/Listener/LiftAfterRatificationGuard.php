<?php

/**
 * Refuses lifting a confidentiality restriction straight from imposed when
 * its ground requires ratification.
 *
 * Subscribed to `confidentiality-restriction` updates only (SaveGuardSubscriptions).
 *
 * The restriction's lifecycle map allows `imposed → dissolved` because some
 * grounds need no ratification. A transition guard cannot read the referenced
 * ground, so the map alone would let a restriction whose ground does require
 * ratification be lifted without it ever having been ratified. This save
 * guard reads the ground and refuses that one transition. It fails closed: a
 * ground that cannot be read refuses the lift, so confidentiality is never
 * lost to a failed lookup.
 *
 * @category Listener
 * @package  OCA\Decidiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A restriction whose ground requires ratification is lifted only after it was ratified.
 *
 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground
 *
 * @template-implements IEventListener<Event>
 */
class LiftAfterRatificationGuard implements IEventListener {

	/**
	 * The schema this guard reads.
	 *
	 * @var string
	 */
	public const SCHEMA = 'confidentiality-restriction';

	/**
	 * The refusal when the ground requires ratification.
	 *
	 * @var string
	 */
	public const NEEDS_RATIFICATION = 'The confidentiality was not lifted: its ground requires ratification, '
		. 'so it can only be lifted once it has been ratified.';

	/**
	 * The refusal when the ground cannot be read.
	 *
	 * @var string
	 */
	public const GROUND_UNREADABLE = 'The confidentiality was not lifted: its ground could not be read, '
		. 'so whether it needs ratification first is unknown.';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, to read the ground.
	 * @param LoggerInterface        $logger        Logs a failed ground lookup.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Refuse the save of a restriction lifted straight from imposed when its ground requires ratification.
	 *
	 * @param Event $event The before-update event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectUpdatingEvent === false || $event->getOldObject() === null) {
			return;
		}

		$new = (new EntityPayloadReader())->payloadOf(entity: $event->getNewObject());
		$old = (new EntityPayloadReader())->payloadOf(entity: $event->getOldObject());
		if (self::liftsStraightFromImposed(old: $old, new: $new) === false) {
			return;
		}

		$message = $this->refusal(groundId: (new RelationReference())->idOf(value: ($new['ground'] ?? ($old['ground'] ?? null))));
		if ($message !== null) {
			$event->setErrors(['message' => $message]);
			$event->stopPropagation();
		}
	}//end handle()

	/**
	 * Whether the save moves the restriction from imposed to dissolved.
	 *
	 * @param array<string, mixed> $old The stored restriction.
	 * @param array<string, mixed> $new The restriction as saved.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground
	 */
	public static function liftsStraightFromImposed(array $old, array $new): bool {
		return ($old['lifecycle'] ?? null) === 'imposed' && ($new['lifecycle'] ?? null) === 'dissolved';
	}//end liftsStraightFromImposed()

	/**
	 * Why the lift is refused, or null when the ground lets it through.
	 *
	 * @param string|null $groundId The restriction's ground.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground
	 */
	public function refusal(?string $groundId): ?string {
		if ($groundId === null) {
			return self::GROUND_UNREADABLE;
		}

		try {
			$entity = $this->objectService->find(
				id: $groundId,
				options: new ObjectFindOptions(register: 'decidiq', schema: 'confidentiality-ground'),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('Decidiq: the ground of a confidentiality could not be read; the lift is refused', ['exception' => $e->getMessage()]);
			return self::GROUND_UNREADABLE;
		}

		if ($entity === null) {
			return self::GROUND_UNREADABLE;
		}

		$ground = (new EntityPayloadReader())->payloadOf(entity: $entity);
		if (($ground['requiresRatification'] ?? false) === true) {
			return self::NEEDS_RATIFICATION;
		}

		return null;
	}//end refusal()
}//end class
