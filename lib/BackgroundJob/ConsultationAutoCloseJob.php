<?php
/**
 * Decidesk Consultation Auto-Close Background Job
 *
 * Scheduled job that auto-transitions open PublicConsultations past their
 * submissionDeadline to 'closed' (citizen-participation).
 *
 * @category BackgroundJob
 * @package  OCA\Decidesk\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\BackgroundJob;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Hourly background job that closes open consultations whose submissionDeadline
 * has passed. Reuses ParticipationLifecycleService::transitionConsultation so
 * the same state-machine guard applies as a staff-driven transition.
 *
 * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
 */
class ConsultationAutoCloseJob extends TimedJob
{

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
     * @param ITimeFactory       $time      Nextcloud time factory (injected by TimedJob)
     * @param ContainerInterface $container The DI container (lazy-loads services)
     * @param LoggerInterface    $logger    The logger
     *
     * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
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
     * @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
     */
    protected function run(mixed $argument): void
    {
        $this->logger->info('Decidesk: ConsultationAutoCloseJob started');

        try {
            $objectService    = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $lifecycleService = $this->container->get(\OCA\Decidesk\Service\ParticipationLifecycleService::class);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Decidesk ConsultationAutoCloseJob: dependencies unavailable, skipping.',
                ['exception' => $e->getMessage()]
            );
            return;
        }

        $now         = time();
        $closedCount = 0;
        $errorCount  = 0;
        $offset      = 0;

        while (true) {
            try {
                $objectService->setRegister('decidesk');
                $objectService->setSchema('public-consultation');
                $entities = $objectService->findAll(
                    [
                        'filters' => [
                            'register' => 'decidesk',
                            'schema'   => 'public-consultation',
                            'status'   => 'open',
                        ],
                        'limit'   => self::PAGE_SIZE,
                        'offset'  => $offset,
                    ]
                );
            } catch (\Throwable $e) {
                $this->logger->error(
                    'Decidesk ConsultationAutoCloseJob: failed to fetch consultations',
                    ['offset' => $offset, 'exception' => $e->getMessage()]
                );
                break;
            }//end try

            $batchCount = 0;
            foreach ($entities as $entity) {
                $batchCount++;
                $consultation = $entity->jsonSerialize();
                $deadline     = ($consultation['submissionDeadline'] ?? null);
                if ($deadline === null || $deadline === '') {
                    continue;
                }

                $deadlineTs = strtotime((string) $deadline);
                if ($deadlineTs === false || $deadlineTs > $now) {
                    continue;
                }

                $uuid = ($consultation['id'] ?? $consultation['uuid'] ?? null);
                if ($uuid === null || $uuid === '') {
                    continue;
                }

                try {
                    $lifecycleService->transitionConsultation(consultationId: (string) $uuid, newStatus: 'closed');
                    $closedCount++;
                } catch (\Throwable $e) {
                    $errorCount++;
                    $this->logger->error(
                        'Decidesk ConsultationAutoCloseJob: failed to close consultation',
                        ['uuid' => $uuid, 'exception' => $e->getMessage()]
                    );
                }
            }//end foreach

            $offset += $batchCount;
            if ($batchCount < self::PAGE_SIZE) {
                break;
            }
        }//end while

        $this->logger->info(
            sprintf('Decidesk ConsultationAutoCloseJob: closed %d consultations (%d errors)', $closedCount, $errorCount)
        );

    }//end run()
}//end class
