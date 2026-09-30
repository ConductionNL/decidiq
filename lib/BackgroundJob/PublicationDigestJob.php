<?php

/**
 * Decidiq Publication Digest Job
 *
 * Every fifteen minutes, sends each subscriber the published and changed
 * agendas, papers, decisions and minutes that match their subscription:
 * straight away (after a fifteen minute wait), daily at 07:00 or weekly on
 * Monday at 07:00 (change publication-subscriptions-and-daily-digest).
 *
 * @category BackgroundJob
 * @package  OCA\Decidiq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/public-publication/spec.md#requirement-req-psd-003-subscribers-receive-matching-events-immediately-daily-or-weekly
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\BackgroundJob;

use OCA\Decidiq\Service\PublicationDigestService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs the publication digest every fifteen minutes.
 *
 * @spec openspec/specs/public-publication/spec.md#requirement-req-psd-003-subscribers-receive-matching-events-immediately-daily-or-weekly
 */
class PublicationDigestJob extends TimedJob {

	/**
	 * Fifteen minutes, the same as the wait for immediate subscribers.
	 *
	 * @var integer
	 */
	private const INTERVAL_SECONDS = 900;

	/**
	 * Construct the job.
	 *
	 * @param ITimeFactory             $time    Nextcloud time factory
	 * @param PublicationDigestService $digest  The digest service
	 * @param LoggerInterface          $logger  PSR-3 logger
	 *
	 * @spec openspec/specs/public-publication/spec.md#requirement-req-psd-003-subscribers-receive-matching-events-immediately-daily-or-weekly
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly PublicationDigestService $digest,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
	}//end __construct()

	/**
	 * Send the digests that are due now.
	 *
	 * @param mixed $argument Unused
	 *
	 * @return void
	 *
	 * @spec openspec/specs/public-publication/spec.md#requirement-req-psd-003-subscribers-receive-matching-events-immediately-daily-or-weekly
	 */
	protected function run(mixed $argument): void {
		try {
			$sent = $this->digest->run(now: $this->time->getTime());
		} catch (Throwable $e) {
			$this->logger->error('Decidiq: the publication digest run failed', ['reason' => $e->getMessage()]);
			return;
		}

		if ($sent > 0) {
			$this->logger->info('Decidiq: publication digests sent', ['sent' => $sent]);
		}
	}//end run()
}//end class
