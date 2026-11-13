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
use OCA\OpenRegister\Event\ObjectCreatingEvent;
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

		try {
			$this->consolidation->assertAcceptable(version: $row);
		} catch (GoverningDocumentVersionRefusedException $e) {
			$this->refuse(event: $event, message: $e->getMessage());
		} catch (Throwable $e) {
			$this->logger->warning(message: 'Decidiq: a governing-document version could not be checked', context: ['error' => $e->getMessage()]);
			$this->refuse(
				event: $event,
				message: 'The version was not saved: it could not be checked against the other versions of its document. Try again in a moment.'
			);
		}
	}//end handle()

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
	 * Whether the row clearly names a schema other than the version.
	 *
	 * @param array<string, mixed> $row The row being saved.
	 *
	 * @return bool True when it is clearly another schema.
	 */
	private function isAnotherSchema(array $row): bool {
		foreach (['_schemaSlug', '_schema', 'schema'] as $key) {
			$candidate = ($row[$key] ?? null);
			if (is_string($candidate) === true && $candidate !== '' && ctype_digit($candidate) === false) {
				return strtolower($candidate) !== GoverningDocumentConsolidationService::VERSION_SCHEMA;
			}
		}

		return false;
	}//end isAnotherSchema()
}//end class
