<?php

/**
 * Decidiq MoveAgendaItemStatusToPhase Repair Step
 *
 * The phase of a discussion or decision item (beeldvorming, oordeelsvorming,
 * besluitvorming) used to be written to `status`, which AgendaItem never
 * declared. It is now the declared `phase` (agenda-item-phase-is-declared,
 * REQ-LIV-010). This step copies a phase still held in `status` into `phase`,
 * once: an item that already has a phase is left alone, so a second run
 * changes nothing. `status` itself is not touched. Each item is written with
 * a phase-only patch, never a full save.
 *
 * @category Repair
 * @package  OCA\Decidiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agenda-item-phase-is-declared/specs/agenda-live-management/spec.md#requirement-req-liv-010-the-agenda-item-declares-its-phase
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Repair;

use OCA\Decidiq\Service\AgendaRules;
use OCA\Decidiq\Service\ObjectResultNormaliser;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Copies an agenda item's phase from the undeclared `status` to `phase`, once.
 *
 * @spec openspec/changes/agenda-item-phase-is-declared/specs/agenda-live-management/spec.md#requirement-req-liv-010-the-agenda-item-declares-its-phase
 */
class MoveAgendaItemStatusToPhase implements IRepairStep {

	private const REGISTER = 'decidiq';

	private const SCHEMA = 'agenda-item';

	/**
	 * Items read per page.
	 *
	 * @var int
	 */
	private const PAGE_SIZE = 500;

	/**
	 * Pages read at most, so a listing that never ends cannot hang an upgrade.
	 *
	 * @var int
	 */
	private const MAX_PAGES = 200;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
	 * @param LoggerInterface        $logger        The logger
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/agenda-item-phase-is-declared/specs/agenda-live-management/spec.md#requirement-req-liv-010-the-agenda-item-declares-its-phase
	 */
	public function getName(): string {
		return 'Move the agenda item phase from status to phase';
	}//end getName()

	/**
	 * Copy every phase still held in `status` into an empty `phase`.
	 *
	 * @param IOutput $output The repair output
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agenda-item-phase-is-declared/specs/agenda-live-management/spec.md#requirement-req-liv-010-the-agenda-item-declares-its-phase
	 */
	public function run(IOutput $output): void {
		$moved = 0;
		$failed = 0;
		for ($page = 0; $page < self::MAX_PAGES; $page++) {
			$items = $this->page(offset: ($page * self::PAGE_SIZE));
			if ($items === null) {
				$output->info('MoveAgendaItemStatusToPhase: no agenda items could be read; nothing to do.');
				return;
			}

			foreach ($items as $entity) {
				$outcome = $this->move(item: (new ObjectResultNormaliser())->toArray(entity: $entity));
				$moved += (int)($outcome === true);
				$failed += (int)($outcome === false);
			}

			if (count($items) < self::PAGE_SIZE) {
				break;
			}
		}

		$output->info('MoveAgendaItemStatusToPhase: ' . $moved . ' moved, ' . $failed . ' failed.');
	}//end run()

	/**
	 * One page of agenda items, or null when they cannot be read.
	 *
	 * @param int $offset The first item
	 *
	 * @return array<int, mixed>|null The items
	 */
	private function page(int $offset): ?array {
		try {
			return $this->objectService->findAll(
				[
					'filters' => ['register' => self::REGISTER, 'schema' => self::SCHEMA],
					'limit' => self::PAGE_SIZE,
					'offset' => $offset,
				]
			);
		} catch (Throwable $e) {
			$this->logger->info('Decidiq: MoveAgendaItemStatusToPhase could not list agenda items', ['exception' => $e->getMessage()]);
			return null;
		}
	}//end page()

	/**
	 * Move one item's phase.
	 *
	 * @param array<string, mixed>|null $item The item
	 *
	 * @return bool|null True when moved, false when the write failed, null when there was nothing to move
	 */
	private function move(?array $item): ?bool {
		$phase = $this->phaseToMove(item: $item);
		if ($phase === null) {
			return null;
		}

		$id = (string)($item['id'] ?? ($item['@self']['id'] ?? ($item['uuid'] ?? '')));
		try {
			$this->objectService->patchObject(objectId: $id, data: ['phase' => $phase], register: self::REGISTER, schema: self::SCHEMA);
			return true;
		} catch (Throwable $e) {
			$this->logger->warning(
				'Decidiq: MoveAgendaItemStatusToPhase could not write one agenda item',
				['agendaItem' => $id, 'exception' => $e->getMessage()]
			);
			return false;
		}
	}//end move()

	/**
	 * The phase to write, or null: only for an item with an id, an empty
	 * `phase`, and a `status` that is one of the declared phases.
	 *
	 * @param array<string, mixed>|null $item The item
	 *
	 * @return string|null The phase
	 */
	private function phaseToMove(?array $item): ?string {
		if ($item === null) {
			return null;
		}

		$id = ($item['id'] ?? ($item['@self']['id'] ?? ($item['uuid'] ?? null)));
		$current = ($item['phase'] ?? null);
		$status = ($item['status'] ?? null);
		if ($id === null || $id === '' || ($current !== null && $current !== '')) {
			return null;
		}

		if (is_string($status) === false || in_array($status, AgendaRules::PHASES, true) === false) {
			return null;
		}

		return $status;
	}//end phaseToMove()
}//end class
