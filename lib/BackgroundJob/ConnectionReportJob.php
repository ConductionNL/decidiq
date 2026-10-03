<?php

/**
 * Decidiq connection report job.
 *
 * Once a day, tells integriq's connection registry which eIDAS signing
 * service and which translation adapter answer. A binding changes with an
 * install or a deploy, so a daily look keeps the Integrations page current
 * without reporting on any request (ADR-076).
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
 * @spec openspec/changes/adopt-connection-registry/specs/admin-settings/spec.md#requirement-req-adm-conn-002-decidiq-reports-which-signing-and-translation-services-answer
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\BackgroundJob;

use OCA\Decidiq\Service\ConnectionReportService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

/**
 * Daily connection report to integriq.
 *
 * @spec openspec/changes/adopt-connection-registry/specs/admin-settings/spec.md#requirement-req-adm-conn-002-decidiq-reports-which-signing-and-translation-services-answer
 */
class ConnectionReportJob extends TimedJob {

	/**
	 * One day, in seconds.
	 *
	 * @var int
	 */
	public const INTERVAL_SECONDS = 86400;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory            $time     Nextcloud time factory.
	 * @param ConnectionReportService $reporter Sends the reports.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ConnectionReportService $reporter,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);
	}//end __construct()

	/**
	 * Report the signing and translation bindings.
	 *
	 * @param mixed $argument Unused job argument.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is TimedJob's.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-settings/spec.md#requirement-req-adm-conn-002-decidiq-reports-which-signing-and-translation-services-answer
	 */
	protected function run(mixed $argument): void {
		$this->reporter->reportBindings();
	}//end run()
}//end class
