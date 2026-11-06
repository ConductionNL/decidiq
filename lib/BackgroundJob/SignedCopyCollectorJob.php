<?php

/**
 * Decidiq Signed Copy Collector Job
 *
 * Every fifteen minutes, asks the signing service about every record that is
 * out for signature and stores the signed copy on the record once it is
 * signed, so nobody has to open the record to fetch it.
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
 * @spec openspec/specs/p2-minutes-and-decisions-core-t3/spec.md#requirement-req-ses-001-send-for-signature-in-a-chosen-order-and-store-the-signed-copy
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\BackgroundJob;

use OCA\Decidiq\Service\SigningRoundService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Collect signed copies of records out for signature.
 *
 * @spec openspec/specs/p2-minutes-and-decisions-core-t3/spec.md#requirement-req-ses-001-send-for-signature-in-a-chosen-order-and-store-the-signed-copy
 */
class SignedCopyCollectorJob extends TimedJob {
	private const INTERVAL_SECONDS = 900;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory
	 * @param SigningRoundService $rounds Collects signed copies
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly SigningRoundService $rounds,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
	}//end __construct()

	/**
	 * Collect every round that is out.
	 *
	 * @param mixed $argument Unused
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $argument is mandated by the
	 * abstract OCP\BackgroundJob\Job::run() signature; this job is scheduled with
	 * no argument.
	 *
	 * @spec openspec/specs/p2-minutes-and-decisions-core-t3/spec.md#requirement-req-ses-001-send-for-signature-in-a-chosen-order-and-store-the-signed-copy
	 */
	protected function run(mixed $argument): void {
		try {
			$stored = $this->rounds->collectAllSent();
		} catch (\Throwable $e) {
			$this->logger->error('Decidiq: collecting signed copies failed', ['reason' => $e->getMessage()]);
			return;
		}

		if ($stored > 0) {
			$this->logger->info('Decidiq: signed copies stored', ['stored' => $stored]);
		}
	}//end run()
}//end class
