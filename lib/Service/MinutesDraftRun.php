<?php

/**
 * Decidiq Minutes Draft Run
 *
 * One run of the minutes draft: the transcript segments and recorded
 * outcomes in scope, the provenance to stamp and the prompt runner.
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

/**
 * The material, provenance and prompt runner of one minutes draft run.
 *
 * @spec openspec/specs/meeting-transcription/spec.md
 */
final class MinutesDraftRun {
	/**
	 * The prompt runner.
	 *
	 * @var callable(string): string
	 */
	private $runner;

	/**
	 * Constructor.
	 *
	 * @param array<int,mixed>         $segments   Aligned transcript segments
	 * @param MinutesOutcomes          $outcomes   Recorded outcomes in scope
	 * @param DraftProvenance          $provenance Provider and generation time
	 * @param callable(string): string $runner     Prompt runner
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function __construct(
		private readonly array $segments,
		private readonly MinutesOutcomes $outcomes,
		private readonly DraftProvenance $provenance,
		callable $runner,
	) {
		$this->runner = $runner;
	}//end __construct()

	/**
	 * The transcript segments in scope.
	 *
	 * @return array<int,mixed>
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function segments(): array {
		return $this->segments;
	}//end segments()

	/**
	 * The recorded outcomes in scope.
	 *
	 * @return MinutesOutcomes
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function outcomes(): MinutesOutcomes {
		return $this->outcomes;
	}//end outcomes()

	/**
	 * The provenance to stamp on a section.
	 *
	 * @return array{aiGenerated: true, providerId: string, generatedAt: string}
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function provenance(): array {
		return $this->provenance->toArray();
	}//end provenance()

	/**
	 * Run one prompt and return the summary.
	 *
	 * @param string $prompt The prompt
	 *
	 * @return string
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function summarise(string $prompt): string {
		return ($this->runner)($prompt);
	}//end summarise()

	/**
	 * This run narrowed to the segments and outcomes of one agenda item.
	 *
	 * @param string $agendaItemId Agenda item id
	 *
	 * @return self
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function forItem(string $agendaItemId): self {
		$segments = [];
		foreach ($this->segments as $segment) {
			if (is_array($segment) === true && (string)($segment['agendaItem'] ?? '') === $agendaItemId) {
				$segments[] = $segment;
			}
		}

		return new self(
			segments: $segments,
			outcomes: $this->outcomes->forItem(agendaItemId: $agendaItemId),
			provenance: $this->provenance,
			runner: $this->runner
		);
	}//end forItem()
}//end class
