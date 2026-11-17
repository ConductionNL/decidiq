<?php

/**
 * Refuses a governing-document version the register may not hold, before it is saved.
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
 * @spec openspec/changes/governing-documents-register
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use OCA\Decidiq\Exception\GoverningDocumentVersionRefusedException;
use OCA\Decidiq\Kernel\Contracts\GoverningDocumentConsolidationServiceInterface;
use OCA\Decidiq\Service\GoverningDocumentConsolidationService;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The version write path's call into GoverningDocumentConsolidationService.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/governing-documents-register
 */
class GoverningDocumentVersionGuardListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param GoverningDocumentConsolidationServiceInterface $consolidation The version rules.
	 * @param LoggerInterface                                $logger        The log.
	 */
	public function __construct(
		private readonly GoverningDocumentConsolidationServiceInterface $consolidation,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Stop the save of a version the rules refuse; any failure refuses.
	 *
	 * @param Event $event The before-save event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/governing-documents-register
	 */
	public function handle(Event $event): void {
		// The new object on an update, the object on a create, null on any other event.
		$entity = SaveGuardEvent::entityOf(event: $event);
		if ($entity === null) {
			return;
		}

		$row = (new EntityPayloadReader())->payloadOf(entity: $entity);
		if ($this->isAnotherSchema(row: $row) === true) {
			return;
		}

		$refusal = $this->refusal(event: $event, row: self::withId(row: $row, entity: $entity));
		if ($refusal !== null) {
			SaveGuardEvent::refuse(event: $event, message: $refusal);
		}
	}//end handle()

	/**
	 * The row with the entity's uuid as its id when the payload carries none.
	 *
	 * @param array<string, mixed> $row    The version being saved.
	 * @param object               $entity The saved entity.
	 *
	 * @return array<string, mixed>
	 */
	private static function withId(array $row, object $entity): array {
		if (isset($row['id']) === false && method_exists($entity, 'getUuid') === true && is_string($entity->getUuid()) === true) {
			$row['id'] = $entity->getUuid();
		}

		return $row;
	}//end withId()

	/**
	 * Why the version may not be saved, or null when it may; any failure refuses.
	 *
	 * @param Event                $event The before-save event.
	 * @param array<string, mixed> $row   The version being saved.
	 *
	 * @return string|null The refusal.
	 */
	private function refusal(Event $event, array $row): ?string {
		try {
			if ($event instanceof ObjectUpdatingEvent && is_object($event->getOldObject()) === true) {
				$this->consolidation->assertEditable(
					before: (new EntityPayloadReader())->payloadOf(entity: $event->getOldObject()),
					after: $row
				);
			}

			$this->consolidation->assertAcceptable(version: $row);
		} catch (GoverningDocumentVersionRefusedException $e) {
			return $e->getMessage();
		} catch (Throwable $e) {
			$this->logger->warning(message: 'Decidiq: a governing-document version could not be checked', context: ['error' => $e->getMessage()]);
			return 'The version was not saved: it could not be checked against the other versions of its document. Try again in a moment.';
		}

		return null;
	}//end refusal()

	/**
	 * Whether the row clearly names a schema other than the version.
	 *
	 * @param array<string, mixed> $row The row being saved.
	 *
	 * @return bool True when it is clearly another schema.
	 */
	private function isAnotherSchema(array $row): bool {
		// The first slug the row names decides; a numeric id or no slug at all
		// is the version it was routed as.
		$slugs = array_filter(
			[($row['_schemaSlug'] ?? null), ($row['_schema'] ?? null), ($row['schema'] ?? null)],
			static fn (mixed $candidate): bool => is_string($candidate) === true && $candidate !== '' && ctype_digit($candidate) === false
		);
		if ($slugs === []) {
			return false;
		}

		return strtolower((string) reset($slugs)) !== GoverningDocumentConsolidationService::VERSION_SCHEMA;
	}//end isAnotherSchema()
}//end class
