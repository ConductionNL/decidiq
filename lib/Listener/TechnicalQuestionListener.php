<?php

/**
 * Decidiq Technical Question Listener
 *
 * A technical question is an agenda item whose type declares an `assignedTo`
 * field (the municipality profile seeds one, `Technische vraag`). When the
 * griffier assigns the question to an official, the official is told, with the
 * answer deadline and a link. When the official fills in the answer, the
 * member who asked (the item's owner) is told. Nothing else about an agenda
 * item save sends a notice from here. Fail-soft: a notice never breaks the
 * write.
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
 * @spec openspec/specs/motion-management/spec.md#requirement-req-mtq-001-technical-questions-go-to-an-official-with-a-deadline
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use OCA\Decidiq\Service\ListenerSchemaResolver;
use OCA\Decidiq\Service\NotificationPreferenceService;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Tells the official about an assigned question and the member about its answer.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/motion-management/spec.md#requirement-req-mtq-001-technical-questions-go-to-an-official-with-a-deadline
 */
class TechnicalQuestionListener implements IEventListener {

	/**
	 * Schema slug of an agenda item.
	 */
	public const SCHEMA_AGENDA_ITEM = 'agenda-item';

	/**
	 * The notice rides the member's "task assigned" switch: for the official
	 * the question is a task, for the member its answer closes one.
	 */
	private const EVENT_TYPE = 'taskAssigned';

	/**
	 * Constructor.
	 *
	 * @param NotificationPreferenceService $notifications  Preference-aware dispatch
	 * @param ListenerSchemaResolver        $schemaResolver Resolves the entity's schema slug
	 * @param IL10N                         $l10n           Translations
	 * @param LoggerInterface               $logger         Logger
	 */
	public function __construct(
		private readonly NotificationPreferenceService $notifications,
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an agenda item create or update.
	 *
	 * @param Event $event The event to handle
	 *
	 * @return void
	 *
	 * @spec openspec/specs/motion-management/spec.md#requirement-req-mtq-001-technical-questions-go-to-an-official-with-a-deadline
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatedEvent) === false && ($event instanceof ObjectUpdatedEvent) === false) {
			return;
		}

		try {
			$entity = $event->getObject();
			$row = $this->extractRow(entity: $entity);
			if ($this->schemaResolver->matchesSchema(entity: $entity, expectedSlug: self::SCHEMA_AGENDA_ITEM, row: $row) === false) {
				return;
			}

			$oldFields = [];
			if ($event instanceof ObjectUpdatedEvent && $event->getOldObject() !== null) {
				$oldFields = $this->typeFields(row: $this->extractRow(entity: $event->getOldObject()));
			}

			$fields = $this->typeFields(row: $row);
			$link = '/agenda-items/' . (string)$entity->getUuid();
			$question = trim((string)($fields['question'] ?? ($row['title'] ?? '')));

			$assignee = trim((string)($fields['assignedTo'] ?? ''));
			if ($assignee !== '' && $assignee !== trim((string)($oldFields['assignedTo'] ?? ''))) {
				$this->notifications->dispatch(
					$assignee,
					self::EVENT_TYPE,
					$this->l10n->t('A technical question was assigned to you'),
					$this->l10n->t('Please answer by %1$s: %2$s', [$this->deadline(fields: $fields), $question]),
					$link
				);
			}

			$answer = trim((string)($fields['answer'] ?? ''));
			$asker = (string)($entity->getOwner() ?? '');
			if ($answer !== '' && trim((string)($oldFields['answer'] ?? '')) === '' && $asker !== '') {
				$this->notifications->dispatch(
					$asker,
					self::EVENT_TYPE,
					$this->l10n->t('Your technical question was answered'),
					$this->l10n->t('The answer is on the agenda item: %1$s', [$question]),
					$link
				);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Decidiq: technical question notice failed', ['exception' => $e->getMessage()]);
		}//end try
	}//end handle()

	/**
	 * The answer deadline as written, or a note that none was set.
	 *
	 * @param array<string, mixed> $fields The type field values.
	 *
	 * @return string
	 */
	private function deadline(array $fields): string {
		$deadline = trim((string)($fields['answerDeadline'] ?? ''));
		if ($deadline === '') {
			return $this->l10n->t('no deadline set');
		}

		return substr($deadline, 0, 10);
	}//end deadline()

	/**
	 * The item's type field values.
	 *
	 * @param array<string, mixed> $row The stored payload.
	 *
	 * @return array<string, mixed>
	 */
	private function typeFields(array $row): array {
		$fields = ($row['typeFields'] ?? []);

		return is_array($fields) === true ? $fields : [];
	}//end typeFields()

	/**
	 * The entity's stored payload.
	 *
	 * @param object $entity The object entity.
	 *
	 * @return array<string, mixed>
	 */
	private function extractRow(object $entity): array {
		$row = [];
		if (method_exists($entity, 'getObject') === true) {
			$row = (array)$entity->getObject();
		}

		if ($row === [] && method_exists($entity, 'jsonSerialize') === true) {
			$row = (array)$entity->jsonSerialize();
		}

		return $row;
	}//end extractRow()
}//end class
