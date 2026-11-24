<?php

/**
 * Ends the wait of a lifted confidentiality once its target is published.
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
 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-006-geheimhoudingenregister-overview-and-awaiting-bekrachtiging-kpi
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * When an agenda item or decision becomes public, every lifted restriction on
 * it that was waiting for that stops waiting: `awaitingPublication` goes to
 * false and `targetPublishedAt` records the moment. The Dashboard tile
 * "Lifted, awaiting publication" counts the restrictions still waiting.
 *
 * Restrictions are read and written in system context: the person publishing
 * may not be allowed to edit a restriction. One that cannot be read or saved
 * is left as it is and logged; the flag feeds a counter and guards nothing.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-006-geheimhoudingenregister-overview-and-awaiting-bekrachtiging-kpi
 */
class ConfidentialityPublishedListener implements IEventListener {

	/**
	 * Agenda items are one kind of target.
	 *
	 * @var string
	 */
	public const SCHEMA_AGENDA_ITEM = 'agenda-item';

	/**
	 * Decisions are the other.
	 *
	 * @var string
	 */
	public const SCHEMA_DECISION = 'decision';

	/**
	 * Per target kind: the field that reads `public` once published, and the restriction field naming the target.
	 *
	 * @var array<string, array{classifier: string, target: string}>
	 */
	private const KINDS = [
		'item'     => ['classifier' => 'confidentiality', 'target' => 'targetAgendaItem'],
		'decision' => ['classifier' => 'isPublished', 'target' => 'targetDecision'],
	];

	/**
	 * Wire the object service, the clock and the log.
	 *
	 * @param ObjectServiceInterface $objectService Reads and writes the restrictions.
	 * @param ITimeFactory           $clock         Says when the target was published.
	 * @param LoggerInterface        $logger        Records a restriction that could not be read or marked.
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-006-geheimhoudingenregister-overview-and-awaiting-bekrachtiging-kpi
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly ITimeFactory $clock,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * React to a saved agenda item or decision.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-006-geheimhoudingenregister-overview-and-awaiting-bekrachtiging-kpi
	 */
	public function handle(Event $event): void {
		$after = null;
		if ($event instanceof ObjectUpdatedEvent) {
			$after = $event->getNewObject();
		}

		if ($after === null) {
			return;
		}

		$kind = $this->justPublished(
			new: (array)$after->getObject(),
			old: (array)($event->getOldObject()?->getObject() ?? [])
		);
		if ($kind !== null) {
			$this->publishedOn(kind: $kind, targetId: (string)$after->getUuid());
		}
	}//end handle()

	/**
	 * Which kind of target just became public, or null.
	 *
	 * The two classifiers never sit on one object, so the first that flipped
	 * to `public` names the kind.
	 *
	 * @param array<string, mixed> $new The object after the save.
	 * @param array<string, mixed> $old The object before it.
	 *
	 * @return string|null `item`, `decision` or null.
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-006-geheimhoudingenregister-overview-and-awaiting-bekrachtiging-kpi
	 */
	public function justPublished(array $new, array $old): ?string {
		foreach (self::KINDS as $kind => $fields) {
			$field = $fields['classifier'];
			if (($new[$field] ?? null) === 'public' && ($old[$field] ?? null) !== 'public') {
				return $kind;
			}
		}

		return null;
	}//end justPublished()

	/**
	 * Mark every lifted restriction on the target that still waits as published.
	 *
	 * @param string $kind     `item` or `decision`.
	 * @param string $targetId The agenda item or decision.
	 *
	 * @return int How many restrictions were marked.
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-006-geheimhoudingenregister-overview-and-awaiting-bekrachtiging-kpi
	 */
	public function publishedOn(string $kind, string $targetId): int {
		if ($targetId === '' || isset(self::KINDS[$kind]) === false) {
			return 0;
		}

		$field  = self::KINDS[$kind]['target'];
		$stamp  = $this->clock->getDateTime()->format('c');
		$marked = 0;
		foreach ($this->restrictionsOn(field: $field, targetId: $targetId) as $restriction) {
			if ((string)($restriction[$field] ?? '') === $targetId && ($restriction['awaitingPublication'] ?? null) === true) {
				$marked += $this->mark(restriction: $restriction, stamp: $stamp);
			}
		}

		return $marked;
	}//end publishedOn()

	/**
	 * The restrictions naming the target, read in system context; none when they cannot be read.
	 *
	 * @param string $field    The restriction field naming the target.
	 * @param string $targetId The target.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function restrictionsOn(string $field, string $targetId): array {
		try {
			$found = $this->objectService->findAll(
				config: ['filters' => ['register' => 'decidiq', 'schema' => 'confidentiality-restriction', $field => $targetId]],
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Decidiq: lifted restrictions could not be read; none is marked published',
				['target' => $targetId, 'error' => $e->getMessage()]
			);
			return [];
		}

		$rows = [];
		foreach (($found['results'] ?? $found) as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$row = $row->jsonSerialize();
			}

			if (is_array($row) === true) {
				$rows[] = $row;
			}
		}

		return $rows;
	}//end restrictionsOn()

	/**
	 * Write the publication onto one restriction.
	 *
	 * @param array<string, mixed> $restriction The restriction.
	 * @param string               $stamp       When its target was published (ISO 8601).
	 *
	 * @return int 1 when saved, 0 when not.
	 */
	private function mark(array $restriction, string $stamp): int {
		$uuid = (string)($restriction['id'] ?? ($restriction['@self']['id'] ?? ''));
		unset($restriction['@self']);
		$restriction['awaitingPublication'] = false;
		$restriction['targetPublishedAt']   = $stamp;

		try {
			$this->objectService->saveObject(
				object: $restriction,
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(register: 'decidiq', schema: 'confidentiality-restriction'),
					uuid: $uuid
				),
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Decidiq: a lifted restriction could not be marked published',
				['restriction' => $uuid, 'error' => $e->getMessage()]
			);
			return 0;
		}

		return 1;
	}//end mark()
}//end class
