<?php

/**
 * Decidiq Approval Stage Lapse Job
 *
 * The clock behind a declared silence. Hourly, because a term can end at any
 * hour of the day and a daily sweep would make every deadline mean "some time
 * tomorrow"; and hourly is cheap, because a stage that is not due is one
 * comparison.
 *
 * It decides nothing itself. Everything it does is in ApprovalStageLapseService,
 * which is safe to run twice.
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
 * @spec openspec/changes/approval-routes-resolve-a-manager-and-declare-silence/specs/approval-routes/spec.md (REQ-AR-017)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\BackgroundJob;

use OCA\Decidiq\Service\ApprovalStageLapseService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Hourly sweep of approval stages whose term has passed (ADR-069).
 *
 * @spec openspec/changes/approval-routes-resolve-a-manager-and-declare-silence/specs/approval-routes/spec.md (REQ-AR-017)
 */
class ApprovalStageLapseJob extends TimedJob {
	/**
	 * Interval between job runs: 3600 seconds = 1 hour.
	 *
	 * @var int
	 */
	private const INTERVAL_SECONDS = 3600;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Nextcloud time factory (injected by TimedJob).
	 * @param ApprovalStageLapseService $lapseService The sweep.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ApprovalStageLapseService $lapseService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
	}//end __construct()

	/**
	 * Run the sweep.
	 *
	 * @param mixed $argument Unused job argument.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/approval-routes-resolve-a-manager-and-declare-silence/specs/approval-routes/spec.md (REQ-AR-017)
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $argument is mandated by the
	 * abstract OCP\BackgroundJob\Job::run() signature; this job is scheduled with
	 * no argument, so the parameter cannot be removed.
	 */
	protected function run(mixed $argument): void {
		try {
			$result = $this->lapseService->sweep();
		} catch (\Throwable $e) {
			$this->logger->error(
				'Decidiq: the approval stage lapse sweep failed',
				['reason' => $e->getMessage()]
			);
			return;
		}

		if ($result['lapsed'] === 0 && $result['substitutesAsked'] === 0) {
			return;
		}

		$this->logger->info(
			'Decidiq: approval stage lapse sweep finished',
			['lapsed' => $result['lapsed'], 'substitutesAsked' => $result['substitutesAsked']]
		);
	}//end run()
}//end class
