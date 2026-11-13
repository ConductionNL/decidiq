<?php

/**
 * Fills the deciding body of existing decisions from their meeting, once.
 *
 * @category Repair
 * @package  OCA\Decidiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/insight-decisions-report-by-period-and-body/specs/dashboard/spec.md#requirement-req-drp-001-a-decision-names-the-body-that-took-it
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Repair;

use OCA\Decidiq\Listener\DecisionDecidingBodyListener;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Every decision with a meeting and no `decidingBody` gets its meeting's
 * governance body. A decision that already names a body is left alone, so a
 * second run changes nothing. Decisions without a meeting stay empty.
 *
 * @spec openspec/changes/insight-decisions-report-by-period-and-body/specs/dashboard/spec.md#requirement-req-drp-001-a-decision-names-the-body-that-took-it
 */
class FillDecisionDecidingBody implements IRepairStep {

	/**
	 * The register slug.
	 *
	 * @var string
	 */
	private const REGISTER = 'decidiq';

	/**
	 * How many decisions are read per page.
	 *
	 * @var int
	 */
	private const PAGE = 500;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService The OpenRegister object service.
	 * @param LoggerInterface        $logger        Logger.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Step name shown by `occ maintenance:repair`.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/insight-decisions-report-by-period-and-body/specs/dashboard/spec.md#requirement-req-drp-001-a-decision-names-the-body-that-took-it
	 */
	public function getName(): string {
		return 'Fill the deciding body of decisions from their meeting';
	}//end getName()

	/**
	 * Fill every decision that has a meeting and no deciding body.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/insight-decisions-report-by-period-and-body/specs/dashboard/spec.md#requirement-req-drp-001-a-decision-names-the-body-that-took-it
	 */
	public function run(IOutput $output): void {
		$filled  = 0;
		$bodies  = [];
		$offset  = 0;
		do {
			$page = $this->page(offset: $offset);
			foreach ($page as $decision) {
				$filled += $this->fill(decision: $decision, bodies: $bodies);
			}

			$offset += self::PAGE;
		} while (count($page) === self::PAGE);

		$output->info(sprintf('FillDecisionDecidingBody: %d decision(s) got their deciding body.', $filled));
	}//end run()

	/**
	 * One page of decisions, as arrays; none when they cannot be read.
	 *
	 * @param int $offset Where the page starts.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function page(int $offset): array {
		try {
			$found = $this->objectService->findAll(
				config: [
					'filters' => ['register' => self::REGISTER, 'schema' => DecisionDecidingBodyListener::SCHEMA],
					'limit' => self::PAGE,
					'offset' => $offset,
				],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->info('Decidiq: FillDecisionDecidingBody found no decisions', ['exception' => $e->getMessage()]);
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
	}//end page()

	/**
	 * Fill one decision; 1 when it was written, 0 otherwise.
	 *
	 * @param array<string, mixed>       $decision The decision.
	 * @param array<string, string|null> $bodies   Meeting bodies already read, by meeting id.
	 *
	 * @return int
	 */
	private function fill(array $decision, array &$bodies): int {
		if (DecisionDecidingBodyListener::referenceId(value: ($decision['decidingBody'] ?? null)) !== null) {
			return 0;
		}

		$meetingId = DecisionDecidingBodyListener::referenceId(value: ($decision['meeting'] ?? null));
		$id        = (string)($decision['id'] ?? ($decision['@self']['id'] ?? ''));
		if ($meetingId === null || $id === '') {
			return 0;
		}

		if (array_key_exists($meetingId, $bodies) === false) {
			$bodies[$meetingId] = $this->bodyOf(meetingId: $meetingId);
		}

		if ($bodies[$meetingId] === null) {
			return 0;
		}

		unset($decision['@self']);
		$decision['decidingBody'] = $bodies[$meetingId];
		try {
			$this->objectService->saveObject(object: $decision, register: self::REGISTER, schema: DecisionDecidingBodyListener::SCHEMA, uuid: $id);
		} catch (Throwable $e) {
			$this->logger->warning('Decidiq: a decision could not get its deciding body', ['decision' => $id, 'error' => $e->getMessage()]);
			return 0;
		}

		return 1;
	}//end fill()

	/**
	 * The governance body of a meeting, null when there is none or it cannot be read.
	 *
	 * @param string $meetingId The meeting.
	 *
	 * @return string|null
	 */
	private function bodyOf(string $meetingId): ?string {
		try {
			$meeting = $this->objectService->find(id: $meetingId, register: self::REGISTER, schema: 'meeting', _rbac: false);
		} catch (Throwable) {
			return null;
		}

		if ($meeting === null) {
			return null;
		}

		return DecisionDecidingBodyListener::referenceId(value: (((array)$meeting->getObject())['governanceBody'] ?? null));
	}//end bodyOf()
}//end class
