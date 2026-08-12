<?php

/**
 * Decidesk Transcription Background Job
 *
 * Asynchronous job that runs a queued meeting transcription through the
 * Nextcloud SpeechToText provider abstraction via TranscriptionService.
 *
 * @category BackgroundJob
 * @package  OCA\Decidesk\BackgroundJob
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

namespace OCA\Decidesk\BackgroundJob;

use OCA\Decidesk\Service\TranscriptionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * One-shot queued job that transcribes a single Transcript object.
 *
 * Enqueued (with the transcript id as argument) when the secretary requests a
 * transcription. Delegates the whole pending → processing → done|failed
 * lifecycle to {@see TranscriptionService::process()}, which is itself guarded
 * against provider absence and provider errors (failure is a first-class
 * stored state, not an uncaught exception).
 *
 * @spec openspec/specs/meeting-transcription/spec.md
 */
class TranscriptionJob extends QueuedJob {
	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time NC time factory (injected by QueuedJob).
	 * @param TranscriptionService $transcriptionService The transcription orchestration service.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly TranscriptionService $transcriptionService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);

	}//end __construct()

	/**
	 * Run the transcription for the enqueued transcript id.
	 *
	 * @param mixed $argument Expected shape: ['transcriptId' => string].
	 *
	 * @return void
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	protected function run(mixed $argument): void {
		$transcriptId = '';
		if (is_array($argument) === true) {
			$transcriptId = (string)($argument['transcriptId'] ?? '');
		}

		if ($transcriptId === '') {
			$this->logger->warning('Decidesk TranscriptionJob: missing transcriptId argument, skipping.');
			return;
		}

		try {
			$this->transcriptionService->process(transcriptId: $transcriptId);
		} catch (\Throwable $e) {
			// Process() already marks the Transcript failed for provider errors;
			// this catch covers infrastructure faults (e.g. OR briefly down) so
			// the cron worker never crashes on a single bad job.
			$this->logger->error(
				'Decidesk TranscriptionJob: transcription run failed',
				['transcriptId' => $transcriptId, 'exception' => $e->getMessage()]
			);
		}

	}//end run()
}//end class
