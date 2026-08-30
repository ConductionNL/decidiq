<?php

/**
 * Decidiq Minutes Draft Service
 *
 * Thin orchestration over the Nextcloud TaskProcessing/AI provider abstraction
 * that turns an aligned transcript into a structured DRAFT for the existing
 * resolution-minutes editor. Always a draft: never written into a lifecycle-
 * bearing Minutes object, never auto-approved or auto-published.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
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

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DomainException;
use OCA\Decidiq\Exception\MissingObjectException;
use OCP\TaskProcessing\Task;
use OCP\TaskProcessing\TaskTypes\TextToText;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Generates AI-assisted draft minutes from an aligned transcript.
 *
 * Provider ABSENCE hides the action: {@see self::isProviderAvailable()} returns
 * false and callers do not offer "Generate draft minutes". When present, a
 * per-agenda-item prompt is assembled (item title + aligned segments + recorded
 * votes/decisions) and run through TaskProcessing; flat transcripts fall back to
 * a whole-meeting summary. Every produced section carries provenance
 * (aiGenerated/provider/generatedAt). Suggested decisions/action items are
 * cross-checked against the recorded voting outcomes/decisions: matches link,
 * non-matches are flagged unverified.
 *
 * @spec openspec/specs/meeting-transcription/spec.md
 */
class MinutesDraftService {
	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container (lazy OR + TaskProcessing).
	 * @param LoggerInterface $logger The logger.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether a TaskProcessing/AI provider is available on this instance.
	 *
	 * Provider absence is a first-class state that HIDES the generate action.
	 *
	 * @return bool True when at least one TaskProcessing provider is registered.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function isProviderAvailable(): bool {
		try {
			$manager = $this->container->get(\OCP\TaskProcessing\IManager::class);
		} catch (\Throwable $e) {
			$this->logger->debug(
				'Decidiq MinutesDraftService: TaskProcessing manager unavailable',
				['error' => $e->getMessage()]
			);
			return false;
		}

		try {
			return $manager->hasProviders();
		} catch (\Throwable $e) {
			$this->logger->debug(
				'Decidiq MinutesDraftService: hasProviders() failed',
				['error' => $e->getMessage()]
			);
			return false;
		}

	}//end isProviderAvailable()

	/**
	 * Generate a draft from a transcript.
	 *
	 * Builds the per-agenda-item draft structure (or a flat whole-meeting
	 * section when no timeline exists), runs each prompt through the AI
	 * provider, cross-checks suggestions against the recorded record, stamps
	 * provenance, and records draftGeneration provenance back on the Transcript.
	 *
	 * @param string $transcriptId Transcript UUID.
	 *
	 * @return array<string,mixed> The draft structure for the minutes editor.
	 *
	 * @throws MissingObjectException When the Transcript cannot be found.
	 * @throws DomainException When no AI provider is available (code 503).
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function generate(string $transcriptId): array {
		if ($this->isProviderAvailable() === false) {
			throw new DomainException('No TaskProcessing/AI provider is available on this instance.', 503);
		}

		$transcript = $this->fetchObject(id: $transcriptId, schema: 'transcript');
		if ($transcript === null) {
			throw new MissingObjectException(message: sprintf('Transcript "%s" not found.', $transcriptId));
		}

		$meetingId = $this->resolveMeetingId(transcript: $transcript);
		$segments = ($transcript['segments'] ?? []);
		if (is_array($segments) === false) {
			$segments = [];
		}

		$agendaItems = [];
		$votingRounds = [];
		$decisions = [];
		if ($meetingId !== null) {
			$agendaItems = $this->fetchRelated(meetingId: $meetingId, schema: 'agenda-item');
			$votingRounds = $this->fetchRelated(meetingId: $meetingId, schema: 'voting-round');
			$decisions = $this->fetchRelated(meetingId: $meetingId, schema: 'decision');
		}

		$providerId = $this->preferredProviderId();
		$generatedAt = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);

		$sections = $this->buildSections(
			segments: $segments,
			agendaItems: $agendaItems,
			votingRounds: $votingRounds,
			decisions: $decisions,
			providerId: $providerId,
			generatedAt: $generatedAt
		);

		$draft = [
			'sections' => $sections,
			'provenance' => [
				'aiGenerated' => true,
				'providerId' => $providerId,
				'generatedAt' => $generatedAt,
			],
		];

		// Record provenance on the Transcript (never on a Minutes object — no lifecycle touch).
		$transcript['draftGeneration'] = [
			'aiGenerated' => true,
			'providerId' => $providerId,
			'generatedAt' => $generatedAt,
		];
		$this->saveTranscript(transcript: $transcript);

		return $draft;
	}//end generate()

	/**
	 * Build the per-section draft (or a flat whole-meeting section).
	 *
	 * Exposed (public) so the section-assembly + cross-check logic can be
	 * unit-tested without OR/TaskProcessing by injecting a runner. The default
	 * runner uses the real TaskProcessing provider.
	 *
	 * @param array<int,array<string,mixed>> $segments Aligned transcript segments.
	 * @param array<int,array<string,mixed>> $agendaItems Agenda items.
	 * @param array<int,array<string,mixed>> $votingRounds Recorded voting rounds.
	 * @param array<int,array<string,mixed>> $decisions Recorded decisions.
	 * @param string $providerId AI provider id (provenance).
	 * @param string $generatedAt Generation timestamp (provenance).
	 * @param callable|null $runner Optional prompt runner (test seam).
	 *
	 * @return array<int,array<string,mixed>> The draft sections.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function buildSections(
		array $segments,
		array $agendaItems,
		array $votingRounds,
		array $decisions,
		string $providerId,
		string $generatedAt,
		?callable $runner = null,
	): array {
		$run = ($runner ?? function (string $prompt): string {
			return $this->runPrompt(prompt: $prompt);
		});

		return $this->getComposer()->buildSections(
			segments: $segments,
			agendaItems: $agendaItems,
			votingRounds: $votingRounds,
			decisions: $decisions,
			providerId: $providerId,
			generatedAt: $generatedAt,
			run: $run
		);

	}//end buildSections()

	/**
	 * Cross-check the AI summary's suggested outcomes against the recorded record.
	 *
	 * Exposed (public) for unit testing. For each recorded decision/voting
	 * outcome whose title appears in the summary, emit a `matched` suggestion
	 * that links to the record; when the summary mentions an outcome with no
	 * recorded match it is emitted `unverified`. The heuristic is intentionally
	 * conservative — unverified is the safe default.
	 *
	 * @param string $summary The AI summary text.
	 * @param array<int,array<string,mixed>> $votingRounds Recorded voting rounds.
	 * @param array<int,array<string,mixed>> $decisions Recorded decisions.
	 *
	 * @return array<int,array<string,mixed>> Suggestions with match/unverified flags.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function crossCheck(string $summary, array $votingRounds, array $decisions): array {
		return $this->getComposer()->crossCheck(
			summary: $summary,
			votingRounds: $votingRounds,
			decisions: $decisions
		);

	}//end crossCheck()

	/**
	 * Assemble the prompt for one agenda item (or the whole meeting).
	 *
	 * @param string $title Section title.
	 * @param array<int,array<string,mixed>> $segments Segments in scope.
	 * @param array<int,array<string,mixed>> $votes Voting rounds in scope.
	 * @param array<int,array<string,mixed>> $decisions Decisions in scope.
	 *
	 * @return string The assembled prompt.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function assemblePrompt(string $title, array $segments, array $votes, array $decisions): string {
		return $this->getComposer()->assemblePrompt(
			title: $title,
			segments: $segments,
			votes: $votes,
			decisions: $decisions
		);

	}//end assemblePrompt()

	/**
	 * Get the MinutesDraftComposer from the container.
	 *
	 * @return MinutesDraftComposer
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	private function getComposer(): MinutesDraftComposer {
		return $this->container->get(MinutesDraftComposer::class);
	}//end getComposer()

	/**
	 * Run a single text-to-text prompt through TaskProcessing synchronously.
	 *
	 * @param string $prompt The prompt.
	 *
	 * @return string The provider output text (empty on failure).
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	private function runPrompt(string $prompt): string {
		try {
			$manager = $this->container->get(\OCP\TaskProcessing\IManager::class);
			$task = new Task(TextToText::ID, ['input' => $prompt], 'decidesk', null);
			$result = $manager->runTask($task);
			$output = $result->getOutput();
			if (is_array($output) === true) {
				return (string)($output['output'] ?? '');
			}

			return '';
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Decidiq MinutesDraftService: prompt run failed',
				['error' => $e->getMessage()]
			);
			return '';
		}

	}//end runPrompt()

	/**
	 * Resolve the preferred TaskProcessing provider id for provenance.
	 *
	 * @return string The provider id, or '' when not resolvable.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	private function preferredProviderId(): string {
		try {
			$manager = $this->container->get(\OCP\TaskProcessing\IManager::class);
			$provider = $manager->getPreferredProvider(TextToText::ID);
			if (is_object($provider) === true && method_exists($provider, 'getId') === true) {
				return (string)$provider->getId();
			}
		} catch (\Throwable) {
			return '';
		}

		return '';
	}//end preferredProviderId()

	/**
	 * Fetch objects related to a meeting via OR findAll.
	 *
	 * @param string $meetingId Meeting UUID.
	 * @param string $schema Schema slug.
	 *
	 * @return array<int,array<string,mixed>> Object data arrays.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	private function fetchRelated(string $meetingId, string $schema): array {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$entities = $objectService->findAll(
				[
					'register' => 'decidiq',
					'schema' => $schema,
					'filters' => [
						'register' => 'decidiq',
						'schema' => $schema,
						'_relations.meeting' => $meetingId,
					],
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Decidiq MinutesDraftService: related fetch failed',
				['schema' => $schema, 'error' => $e->getMessage()]
			);
			return [];
		}

		$result = [];
		foreach ($entities as $entity) {
			if (is_array($entity) === true) {
				$result[] = $entity;
			} elseif (method_exists($entity, 'getObject') === true) {
				$result[] = $entity->getObject();
			} elseif (method_exists($entity, 'jsonSerialize') === true) {
				$result[] = (array)$entity->jsonSerialize();
			}
		}

		return $result;
	}//end fetchRelated()

	/**
	 * Fetch a single object as an array (or null).
	 *
	 * @param string $id Object UUID.
	 * @param string $schema Schema slug.
	 *
	 * @return array<string,mixed>|null The object data.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	private function fetchObject(string $id, string $schema): ?array {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$entity = $objectService->find(id: $id, register: 'decidiq', schema: $schema);
		} catch (\Throwable) {
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return (array)$entity->jsonSerialize();
	}//end fetchObject()

	/**
	 * Persist a Transcript object (for draftGeneration provenance only).
	 *
	 * @param array<string,mixed> $transcript The Transcript object.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	private function saveTranscript(array $transcript): void {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$id = ($transcript['id'] ?? ($transcript['@self']['id'] ?? null));
			$uuid = null;
			if ($id !== null) {
				$uuid = (string)$id;
			}

			$objectService->saveObject(
				object: $transcript,
				register: 'decidiq',
				schema: 'transcript',
				uuid: $uuid
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Decidiq MinutesDraftService: provenance save failed',
				['error' => $e->getMessage()]
			);
		}

	}//end saveTranscript()

	/**
	 * Resolve the linked meeting UUID from a Transcript object.
	 *
	 * @param array<string,mixed> $transcript The Transcript object.
	 *
	 * @return string|null The meeting UUID.
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	private function resolveMeetingId(array $transcript): ?string {
		$relation = ($transcript['relations']['meeting'] ?? ($transcript['meeting'] ?? null));
		if (is_array($relation) === true) {
			$relation = ($relation['id'] ?? ($relation[0] ?? null));
		}

		if ($relation === null || $relation === '') {
			return null;
		}

		return (string)$relation;
	}//end resolveMeetingId()
}//end class
