<?php

/**
 * Runs the document embargo release every fifteen minutes.
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
 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-005-member-facing-embargo-with-scheduled-timed-release
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\BackgroundJob;

use DateTimeImmutable;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * The interval is the release granularity: a document opens to the wider
 * membership at most fifteen minutes after its `embargoUntil`.
 *
 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-005-member-facing-embargo-with-scheduled-timed-release
 */
class DocumentEmbargoReleaseJob extends TimedJob {

	/**
	 * Every fifteen minutes.
	 *
	 * @var int
	 */
	public const INTERVAL_SECONDS = 900;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory         $time    The clock.
	 * @param DocumentEmbargoSweep $release The release sweep.
	 * @param LoggerInterface      $logger  Logs a failed sweep.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-005-member-facing-embargo-with-scheduled-timed-release
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly DocumentEmbargoSweep $release,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
	}//end __construct()

	/**
	 * Release what is due now.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The TimedJob signature.
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-005-member-facing-embargo-with-scheduled-timed-release
	 */
	protected function run(mixed $argument): void {
		try {
			$result = $this->release->sweep(now: new DateTimeImmutable('@' . $this->time->getTime()));
		} catch (\Throwable $e) {
			$this->logger->error('Decidiq: the document embargo release failed', ['reason' => $e->getMessage()]);
			return;
		}

		if ($result['released'] > 0 || $result['failed'] > 0) {
			$this->logger->info('Decidiq: document embargo release finished', $result);
		}
	}//end run()
}//end class
