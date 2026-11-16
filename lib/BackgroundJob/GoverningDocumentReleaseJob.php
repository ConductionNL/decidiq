<?php

/**
 * Decidiq Governing Document Release Job
 *
 * Once a day, lets the approved governing documents whose planned effective
 * date has arrived take effect, and reports those that are due but not
 * approved yet.
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
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md#requirement-req-dwp-006-a-document-takes-effect-on-a-date-it-was-given
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\BackgroundJob;

use OCA\Decidiq\Kernel\Contracts\GoverningDocumentReleaseInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Runs the governing document release once a day.
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md#requirement-req-dwp-006-a-document-takes-effect-on-a-date-it-was-given
 */
class GoverningDocumentReleaseJob extends TimedJob {

	/**
	 * Once a day.
	 *
	 * @var int
	 */
	private const INTERVAL_SECONDS = 86400;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory                      $time    The clock.
	 * @param GoverningDocumentReleaseInterface $release The release.
	 * @param LoggerInterface                   $logger  The logger.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md#requirement-req-dwp-006-a-document-takes-effect-on-a-date-it-was-given
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly GoverningDocumentReleaseInterface $release,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
	}//end __construct()

	/**
	 * Release what is due; report what is overdue.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md#requirement-req-dwp-006-a-document-takes-effect-on-a-date-it-was-given
	 */
	protected function run(mixed $argument): void {
		try {
			$result = $this->release->sweep();
		} catch (\Throwable $e) {
			$this->logger->error('Decidiq: the governing document release failed', ['reason' => $e->getMessage()]);
			return;
		}

		if ($result['overdue'] !== []) {
			$this->logger->warning(
				'Decidiq: governing documents passed their planned effective date without being approved',
				['documents' => $result['overdue']]
			);
		}

		if ($result['released'] !== [] || $result['failed'] !== []) {
			$this->logger->info(
				'Decidiq: governing document release finished',
				['released' => count($result['released']), 'failed' => count($result['failed'])]
			);
		}
	}//end run()
}//end class
