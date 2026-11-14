<?php

/**
 * Daily: puts the lifting of confidentiality restrictions on the agenda before their end date.
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
 * @spec openspec/changes/confidentiality-term-and-lapse/specs/embargo-geheimhouding/spec.md#requirement-req-emb-022-the-planned-end-date-puts-the-lifting-on-the-agenda
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\BackgroundJob;

use DateTimeImmutable;
use OCA\Decidiq\Service\ConfidentialityLiftByService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Runs the lifting sweep once a day.
 *
 * @spec openspec/changes/confidentiality-term-and-lapse/tasks.md#task-3
 */
class ConfidentialityLiftByJob extends TimedJob {
	/**
	 * Once a day.
	 *
	 * @var int
	 */
	private const INTERVAL_SECONDS = 86400;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory                 $time    The clock.
	 * @param ConfidentialityLiftByService $liftBy  The lifting sweep.
	 * @param LoggerInterface              $logger  Logs a failed sweep.
	 *
	 * @return void
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ConfidentialityLiftByService $liftBy,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
	}//end __construct()

	/**
	 * Run the sweep for today.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The TimedJob signature.
	 *
	 * @spec openspec/changes/confidentiality-term-and-lapse/tasks.md#task-3
	 */
	protected function run(mixed $argument): void {
		try {
			$result = $this->liftBy->sweep(today: new DateTimeImmutable('@' . $this->time->getTime()));
		} catch (\Throwable $e) {
			$this->logger->error('Decidiq: the confidentiality lifting sweep failed', ['reason' => $e->getMessage()]);
			return;
		}

		if ($result['placed'] > 0 || $result['overdue'] > 0) {
			$this->logger->info('Decidiq: confidentiality lifting sweep finished', $result);
		}
	}//end run()
}//end class
