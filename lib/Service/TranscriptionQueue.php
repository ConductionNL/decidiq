<?php
/**
 * Decidesk Transcription Queue
 *
 * Owns the asynchronous transcription hand-off: which background job runs a
 * submitted Transcript and what arguments it carries. Extracted from
 * TranscriptionController so the HTTP layer no longer needs to know the job
 * class or the job-list API.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/meeting-transcription/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Service;

use OCA\Decidesk\BackgroundJob\TranscriptionJob;
use OCP\BackgroundJob\IJobList;

/**
 * Enqueues the asynchronous transcription background job.
 *
 * @spec openspec/specs/meeting-transcription/spec.md
 */
class TranscriptionQueue
{
    /**
     * Construct the queue.
     *
     * @param IJobList $jobList Background job queue.
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    public function __construct(
        private readonly IJobList $jobList,
    ) {
        // All state is injected; nothing to initialise.
    }//end __construct()

    /**
     * Schedule asynchronous transcription for a Transcript.
     *
     * @param string $transcriptId Transcript UUID.
     *
     * @return void
     *
     * @spec openspec/specs/meeting-transcription/spec.md
     */
    public function enqueue(string $transcriptId): void
    {
        $this->jobList->add(TranscriptionJob::class, ['transcriptId' => $transcriptId]);

    }//end enqueue()
}//end class
