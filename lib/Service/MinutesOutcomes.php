<?php

/**
 * Decidiq Minutes Outcomes
 *
 * The recorded outcomes a minutes draft is checked against: the voting
 * rounds and the decisions, for the whole meeting or one agenda item.
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
 * The voting rounds and decisions in scope of a minutes draft section.
 *
 * @spec openspec/specs/meeting-transcription/spec.md
 */
final class MinutesOutcomes {
	/**
	 * Constructor.
	 *
	 * @param array<int,mixed> $votingRounds Recorded voting rounds
	 * @param array<int,mixed> $decisions    Recorded decisions
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function __construct(
		private readonly array $votingRounds,
		private readonly array $decisions,
	) {
	}//end __construct()

	/**
	 * The recorded voting rounds.
	 *
	 * @return array<int,mixed>
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function votingRounds(): array {
		return $this->votingRounds;
	}//end votingRounds()

	/**
	 * The recorded decisions.
	 *
	 * @return array<int,mixed>
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function decisions(): array {
		return $this->decisions;
	}//end decisions()

	/**
	 * The outcomes linked to one agenda item.
	 *
	 * @param string $agendaItemId Agenda item id
	 *
	 * @return self
	 *
	 * @spec openspec/specs/meeting-transcription/spec.md
	 */
	public function forItem(string $agendaItemId): self {
		return new self(
			votingRounds: self::linkedTo(records: $this->votingRounds, agendaItemId: $agendaItemId),
			decisions: self::linkedTo(records: $this->decisions, agendaItemId: $agendaItemId)
		);
	}//end forItem()

	/**
	 * The records whose agenda-item link resolves to the given id.
	 *
	 * @param array<int,mixed> $records      Records
	 * @param string           $agendaItemId Agenda item id
	 *
	 * @return array<int,mixed>
	 */
	private static function linkedTo(array $records, string $agendaItemId): array {
		$result = [];
		foreach ($records as $record) {
			$linked = ($record['agendaItem'] ?? ($record['relations']['agendaItem'] ?? ($record['relations']['agenda-item'] ?? null)));
			if (is_array($linked) === true) {
				$linked = ($linked['id'] ?? ($linked[0] ?? null));
			}

			if ((string)$linked === $agendaItemId) {
				$result[] = $record;
			}
		}

		return $result;
	}//end linkedTo()
}//end class
