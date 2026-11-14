<?php

/**
 * Writes the effective date of a governing-document version just put in effect onto its document.
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

use OCA\Decidiq\Kernel\Contracts\GoverningDocumentConsolidationServiceInterface;
use OCA\Decidiq\Service\GoverningDocumentConsolidationService;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Keeps GoverningDocument.currentEffectiveDate, which the register lists, in
 * step with the version in effect (REQ-GDR-006). Fail-soft: the version is
 * already saved, so a failure is logged and the column stays as it was.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/governing-documents-register
 */
class GoverningDocumentCurrentDateListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param GoverningDocumentConsolidationServiceInterface $consolidation Writes the date.
	 * @param LoggerInterface                                $logger        The log.
	 */
	public function __construct(
		private readonly GoverningDocumentConsolidationServiceInterface $consolidation,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record the effective date of a version that was just put in effect.
	 *
	 * @param Event $event The after-save event.
	 *
	 * @return void
	 *
	 * @listener-placement inline cheap-bounded — one find of the document, and
	 *     one save only when its date changes. Deferred, the register would
	 *     list the old date right after the version took effect.
	 *
	 * @spec openspec/changes/governing-documents-register
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatedEvent === false && $event instanceof ObjectUpdatedEvent === false) {
			return;
		}

		$entity = null;
		if ($event instanceof ObjectUpdatedEvent) {
			$entity = $event->getNewObject();
		} else {
			$entity = $event->getObject();
		}

		if (is_object($entity) === false) {
			return;
		}

		$row = (new EntityPayloadReader())->payloadOf(entity: $entity);
		$schema = ($row['_schemaSlug'] ?? ($row['_schema'] ?? null));
		if (is_string($schema) === true && $schema !== '' && ctype_digit($schema) === false
			&& strtolower($schema) !== GoverningDocumentConsolidationService::VERSION_SCHEMA
		) {
			return;
		}

		try {
			$this->consolidation->recordCurrentEffectiveDate(version: $row);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: 'Decidiq: the current effective date of a governing document could not be updated',
				context: ['error' => $e->getMessage()]
			);
		}
	}//end handle()
}//end class
