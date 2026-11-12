<?php

/**
 * Decidiq Consultation Auto-Close Background Job
 *
 * Scheduled job that auto-transitions open PublicConsultations past their
 * submissionDeadline to 'closed' (citizen-participation).
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
 * @spec openspec/specs/citizen-participation/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\BackgroundJob;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Hourly background job that closes open consultations whose submissionDeadline
 * has passed. Reuses ParticipationLifecycleService::transitionConsultation so
 * the same state-machine guard applies as a staff-driven transition.
 *
 * @spec openspec/specs/citizen-participation/spec.md
 */
class ConsultationAutoCloseJob extends TimedJob {

	/**
	 * Interval between job runs: 3600 seconds = 1 hour.
	 */
	private const INTERVAL_SECONDS = 3600;

	/**
	 * Page size for offset-based pagination.
	 */
	private const PAGE_SIZE = 100;

	/**
	 * Constructor for ConsultationAutoCloseJob.
	 *
	 * @param ITimeFactory $time Nextcloud time factory (injected by TimedJob)
	 * @param ContainerInterface $container The DI container (lazy-loads services)
	 * @param LoggerInterface $logger The logger
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	public function __construct(
		ITimeFactory $time,
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
	}//end __construct()

	/**
	 * Auto-close open consultations past their submissionDeadline.
	 *
	 * @param mixed $argument Not used; required by TimedJob interface.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $argument is mandated by the
	 * abstract OCP\BackgroundJob\Job::run() signature; this job is scheduled with
	 * no argument, so the parameter cannot be removed.
	 */
	protected function run(mixed $argument): void {
		$this->logger->info('Decidiq: ConsultationAutoCloseJob started');

		$run = $this->startRun();
		if ($run === null) {
			return;
		}

		$this->closeExpiredPages(run: $run);

		$this->logger->info(
			sprintf('Decidiq ConsultationAutoCloseJob: closed %d consultations (%d errors)', $run->closed, $run->errors)
		);

	}//end run()

	/**
	 * Resolve the services a sweep needs and start it, or log and return null
	 * when they are unavailable.
	 *
	 * @return ConsultationCloseRun|null The started sweep, or null to skip.
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	private function startRun(): ?ConsultationCloseRun {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$lifecycleService = $this->container->get(\OCA\Decidiq\Service\ParticipationLifecycleService::class);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Decidiq ConsultationAutoCloseJob: dependencies unavailable, skipping.',
				['exception' => $e->getMessage()]
			);
			return null;
		}

		return new ConsultationCloseRun(objectService: $objectService, lifecycleService: $lifecycleService, now: time());
	}//end startRun()

	/**
	 * Walk the open consultations page by page, closing the expired ones,
	 * until a page is short or a fetch fails.
	 *
	 * @param ConsultationCloseRun $run The sweep.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	private function closeExpiredPages(ConsultationCloseRun $run): void {
		$offset = 0;

		while (true) {
			$entities = $this->fetchOpenConsultations(objectService: $run->objectService, offset: $offset);
			if ($entities === null) {
				return;
			}

			$batchCount = $this->closeExpiredIn(run: $run, entities: $entities);
			$offset += $batchCount;
			if ($batchCount < self::PAGE_SIZE) {
				return;
			}
		}
	}//end closeExpiredPages()

	/**
	 * Close the expired consultations of one page.
	 *
	 * @param ConsultationCloseRun $run      The sweep.
	 * @param array<int, mixed>    $entities The page of entities.
	 *
	 * @return int How many entities the page held.
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	private function closeExpiredIn(ConsultationCloseRun $run, array $entities): int {
		$batchCount = 0;
		foreach ($entities as $entity) {
			$batchCount++;
			$this->closeIfExpired(run: $run, consultation: $entity->jsonSerialize());
		}

		return $batchCount;
	}//end closeExpiredIn()

	/**
	 * Fetch one page of open consultations, or null when the fetch failed.
	 *
	 * @param object $objectService The OpenRegister ObjectService
	 * @param int $offset Page offset
	 *
	 * @return array<int, mixed>|null The page of entities, or null on failure
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	private function fetchOpenConsultations(object $objectService, int $offset): ?array {
		try {
			$objectService->setRegister('decidiq');
			$objectService->setSchema('public-consultation');
			return $objectService->findAll(
				[
					'filters' => [
						'register' => 'decidiq',
						'schema' => 'public-consultation',
						'status' => 'open',
					],
					'limit' => self::PAGE_SIZE,
					'offset' => $offset,
				]
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Decidiq ConsultationAutoCloseJob: failed to fetch consultations',
				['offset' => $offset, 'exception' => $e->getMessage()]
			);
			return null;
		}//end try

	}//end fetchOpenConsultations()

	/**
	 * Close a single consultation when its submission deadline has passed,
	 * counting it as closed or as an error on the sweep.
	 *
	 * @param ConsultationCloseRun $run          The sweep.
	 * @param array<string, mixed> $consultation The serialised consultation
	 *
	 * @return void
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	private function closeIfExpired(ConsultationCloseRun $run, array $consultation): void {
		$uuid = $this->expiredConsultationId(consultation: $consultation, now: $run->now);
		if ($uuid === null) {
			return;
		}

		try {
			$run->lifecycleService->transitionConsultation(consultationId: $uuid, newStatus: 'closed');
			$run->closed++;
		} catch (\Throwable $e) {
			$run->errors++;
			$this->logger->error(
				'Decidiq ConsultationAutoCloseJob: failed to close consultation',
				['uuid' => $uuid, 'exception' => $e->getMessage()]
			);
		}

	}//end closeIfExpired()

	/**
	 * Resolve the identifier of a consultation whose deadline has passed, or
	 * null when it has no usable deadline, is not yet due, or has no id.
	 *
	 * @param array<string, mixed> $consultation The serialised consultation
	 * @param int $now Current unix timestamp
	 *
	 * @return string|null The consultation id, or null when it must be skipped
	 *
	 * @spec openspec/specs/citizen-participation/spec.md
	 */
	private function expiredConsultationId(array $consultation, int $now): ?string {
		$deadline = (string)($consultation['submissionDeadline'] ?? '');
		if ($deadline === '') {
			return null;
		}

		$deadlineTs = strtotime($deadline);
		if ($deadlineTs === false || $deadlineTs > $now) {
			return null;
		}

		$uuid = (string)($consultation['id'] ?? $consultation['uuid'] ?? '');
		if ($uuid === '') {
			return null;
		}

		return $uuid;
	}//end expiredConsultationId()
}//end class
