<?php

/**
 * Decidiq Theme Refresh Job
 *
 * Nightly: republishes every active public theme whose timeline changed
 * since its last published version (REQ-THP-004). Runs as the system when
 * nobody is signed in, as background jobs do.
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
 * @spec openspec/changes/publication-theme-pages/specs/public-publication/spec.md#requirement-req-thp-004-an-active-published-theme-stays-current
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\BackgroundJob;

use OCA\Decidiq\Kernel\Contracts\SystemWhenNobodySignedInInterface;
use OCA\Decidiq\Service\ThemeRefreshService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The nightly theme refresh.
 *
 * @spec openspec/changes/publication-theme-pages/specs/public-publication/spec.md#requirement-req-thp-004-an-active-published-theme-stays-current
 */
class ThemeRefreshJob extends TimedJob {
	/**
	 * Interval between runs: one day.
	 *
	 * @var int
	 */
	private const INTERVAL_SECONDS = 86400;

	/**
	 * Construct the job and set its daily interval.
	 *
	 * @param ITimeFactory                      $time    Time factory for the TimedJob base.
	 * @param ThemeRefreshService               $refresh The refresh.
	 * @param SystemWhenNobodySignedInInterface $system  Runs the refresh as the system in the background.
	 * @param LoggerInterface                   $logger  Logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ThemeRefreshService $refresh,
		private readonly SystemWhenNobodySignedInInterface $system,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
		$this->setTimeSensitivity(sensitivity: self::TIME_INSENSITIVE);
	}//end __construct()

	/**
	 * Refresh the published themes.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publication-theme-pages/specs/public-publication/spec.md#requirement-req-thp-004-an-active-published-theme-stays-current
	 */
	protected function run(mixed $argument): void {
		try {
			$outcome = $this->system->run(fn (): array => $this->refresh->refresh());
		} catch (Throwable $e) {
			$this->logger->warning('Decidiq theme refresh failed', ['exception' => $e->getMessage()]);
			return;
		}

		$this->logger->info(
			'Decidiq theme refresh done',
			[
				'republished' => count($outcome['republished'] ?? []),
				'unchanged' => count($outcome['unchanged'] ?? []),
				'failed' => count($outcome['failed'] ?? []),
			]
		);
	}//end run()
}//end class
