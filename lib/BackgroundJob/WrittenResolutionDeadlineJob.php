<?php

/**
 * Closes written voting rounds whose deadline has passed.
 *
 * @category BackgroundJob
 * @package  OCA\Decidiq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-004-the-round-closes-as-soon-as-its-outcome-is-known-and-at-the-latest-at-its-deadline
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\BackgroundJob;

use DateTimeImmutable;
use OCA\Decidiq\Service\WrittenRoundCloser;
use OCA\Decidiq\Service\WrittenRoundStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Every 15 minutes: each open written round past its `votingDeadline` closes
 * with reason `deadline`, silence counting as not agreeing. A closed round is
 * skipped, so a second run changes nothing; an in-meeting round is never read
 * as written, whatever deadline it carries.
 *
 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-004-the-round-closes-as-soon-as-its-outcome-is-known-and-at-the-latest-at-its-deadline
 */
class WrittenResolutionDeadlineJob extends TimedJob {

	/**
	 * Run interval in seconds.
	 *
	 * @var int
	 */
	private const INTERVAL_SECONDS = 900;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory       $time   Nextcloud time factory.
	 * @param WrittenRoundStore  $store  Reads the written rounds.
	 * @param WrittenRoundCloser $closer Closes a round at its deadline.
	 * @param LoggerInterface    $logger Logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly WrittenRoundStore $store,
		private readonly WrittenRoundCloser $closer,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
	}//end __construct()

	/**
	 * Close every open written round past its deadline.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-004-the-round-closes-as-soon-as-its-outcome-is-known-and-at-the-latest-at-its-deadline
	 */
	protected function run(mixed $argument): void {
		$now    = (new DateTimeImmutable())->setTimestamp($this->time->getTime());
		$closed = 0;
		try {
			$rounds = $this->store->findAll(schema: 'voting-round', filters: ['procedure' => 'written']);
		} catch (Throwable $e) {
			$this->logger->error('Decidiq: written rounds could not be read for the deadline close', ['exception' => $e->getMessage()]);
			return;
		}

		foreach ($rounds as $round) {
			try {
				if ($this->closer->closeAtDeadline(round: $round, now: $now) === true) {
					$closed++;
				}
			} catch (Throwable $e) {
				$this->logger->error(
					'Decidiq: a written round could not be closed at its deadline',
					['votingRoundId' => ($round['id'] ?? null), 'exception' => $e->getMessage()]
				);
			}
		}

		if ($closed > 0) {
			$this->logger->info('Decidiq: written rounds closed at their deadline', ['closed' => $closed]);
		}
	}//end run()
}//end class
