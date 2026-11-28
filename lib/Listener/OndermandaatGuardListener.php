<?php

/**
 * Refuses a bevoegdheidstoedeling that names no delegans or no delegataris, or whose
 * ondermandaat chain is not allowed, before it is saved.
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
 * @spec openspec/changes/delegatie-mandaatregister/specs/delegatie-mandaatregister/spec.md#requirement-req-dmr-003-ondermandaat-chains-are-permitted-displayed-and-cycle-free
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use OCA\Decidiq\Exception\OndermandaatRefusedException;
use OCA\Decidiq\Service\AnyOfFieldsGuard;
use OCA\Decidiq\Service\OndermandaatGuardService;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The toedeling write path's call into OndermandaatGuardService.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/delegatie-mandaatregister/specs/delegatie-mandaatregister/spec.md#requirement-req-dmr-003-ondermandaat-chains-are-permitted-displayed-and-cycle-free
 */
class OndermandaatGuardListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param OndermandaatGuardService $guard  The chain check.
	 * @param LoggerInterface          $logger The log.
	 * @param AnyOfFieldsGuard         $fields The check that a delegans and a delegataris are named.
	 */
	public function __construct(
		private readonly OndermandaatGuardService $guard,
		private readonly LoggerInterface $logger,
		private readonly AnyOfFieldsGuard $fields=new AnyOfFieldsGuard(),
	) {
	}//end __construct()

	/**
	 * Stop the save of a toedeling the guard refuses; any failure refuses.
	 *
	 * @param Event $event The before-save event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/delegatie-mandaatregister/specs/delegatie-mandaatregister/spec.md#requirement-req-dmr-003-ondermandaat-chains-are-permitted-displayed-and-cycle-free
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === false && $event instanceof ObjectUpdatingEvent === false) {
			return;
		}

		$entity = null;
		if ($event instanceof ObjectUpdatingEvent) {
			$entity = $event->getNewObject();
		} else {
			$entity = $event->getObject();
		}

		if (is_object($entity) === false) {
			return;
		}

		$row = (new EntityPayloadReader())->payloadOf(entity: $entity);
		if ($this->isAnotherSchema(row: $row) === true) {
			return;
		}

		if (isset($row['id']) === false && method_exists($entity, 'getUuid') === true && is_string($entity->getUuid()) === true) {
			$row['id'] = $entity->getUuid();
		}

		$missing = $this->missingParts(row: $row, event: $event);
		if ($missing !== null) {
			$this->refuse(event: $event, message: $missing);
			return;
		}

		try {
			$this->guard->assertAllowed(toedeling: $row);
		} catch (OndermandaatRefusedException $e) {
			$this->refuse(event: $event, message: $e->getMessage());
		} catch (Throwable $e) {
			$this->logger->warning(message: 'Decidiq: the ondermandaat chain could not be checked', context: ['error' => $e->getMessage()]);
			$this->refuse(event: $event, message: 'The toedeling was not saved: its ondermandaat chain could not be checked. Try again in a moment.');
		}
	}//end handle()

	/**
	 * Why the save lacks something it must carry, or null when it is complete.
	 *
	 * A toedeling names both parties; a withdrawal names the decision that
	 * revoked it.
	 *
	 * @param array<string, mixed>                    $row   The row being saved.
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The before-save event.
	 *
	 * @return string|null The refusal, or null.
	 *
	 * @spec openspec/changes/delegatie-mandaatregister/specs/delegatie-mandaatregister/spec.md#requirement-req-dmr-002-declarative-status-lifecycle-with-intrekking-traced-to-a-revoking-decision
	 */
	private function missingParts(array $row, ObjectCreatingEvent|ObjectUpdatingEvent $event): ?string {
		$empty = $this->fields->emptyGroups(row: $row, groups: OndermandaatGuardService::PARTY_GROUPS);
		if ($empty !== []) {
			return 'The toedeling was not saved: name ' . implode(' and ', $empty) . '.';
		}

		if ($event instanceof ObjectUpdatingEvent && $this->isUntracedWithdrawal(row: $row, old: $event->getOldObject()) === true) {
			return 'The delegation was not withdrawn: name the decision that revoked it (withdrawnBy).';
		}

		return null;
	}//end missingParts()

	/**
	 * Whether this update withdraws the delegation without naming the revoking decision.
	 *
	 * Only the move into `withdrawn` is judged: a row already withdrawn
	 * before this save, such as one carried across by the rename, is left
	 * as it is stored.
	 *
	 * @param array<string, mixed> $row The row being saved.
	 * @param mixed                $old The stored object before the save.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/delegatie-mandaatregister/specs/delegatie-mandaatregister/spec.md#requirement-req-dmr-002-declarative-status-lifecycle-with-intrekking-traced-to-a-revoking-decision
	 */
	private function isUntracedWithdrawal(array $row, mixed $old): bool {
		if (($row['status'] ?? null) !== 'withdrawn') {
			return false;
		}

		$before = [];
		if (is_object($old) === true) {
			$before = (new EntityPayloadReader())->payloadOf(entity: $old);
		}

		if (($before['status'] ?? null) === 'withdrawn') {
			return false;
		}

		$revokedBy = ($row['withdrawnBy'] ?? null);
		if (is_array($revokedBy) === true) {
			$revokedBy = ($revokedBy['id'] ?? ($revokedBy['uuid'] ?? null));
		}

		return is_string($revokedBy) === false || trim($revokedBy) === '';
	}//end isUntracedWithdrawal()

	/**
	 * Stop a save, telling the person why.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event   The before-save event.
	 * @param string                                  $message The refusal.
	 *
	 * @return void
	 */
	private function refuse(ObjectCreatingEvent|ObjectUpdatingEvent $event, string $message): void {
		$event->setErrors(['message' => $message]);
		$event->stopPropagation();
	}//end refuse()

	/**
	 * Whether the row clearly names a schema other than the toedeling.
	 *
	 * The subscription routes only toedeling saves here, so a row whose schema
	 * cannot be read is treated as the toedeling it was routed as.
	 *
	 * @param array<string, mixed> $row The row being saved.
	 *
	 * @return bool True when it is clearly another schema.
	 */
	private function isAnotherSchema(array $row): bool {
		foreach (['_schemaSlug', '_schema', 'schema'] as $key) {
			$candidate = ($row[$key] ?? null);
			if (is_string($candidate) === true && $candidate !== '' && ctype_digit($candidate) === false) {
				return in_array(strtolower($candidate), OndermandaatGuardService::SCHEMAS, true) === false;
			}
		}

		return false;
	}//end isAnotherSchema()
}//end class
