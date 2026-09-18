<?php

/**
 * Legal Remedy Resolver
 *
 * Every decision has to tell the person it affects how to contest it: which
 * remedy is open, how long they have, and where to lodge it. That is declared
 * once on the decision type and resolved onto each decision as it is taken.
 *
 * WHY IT IS RESOLVED AND STAMPED RATHER THAN READ LIVE
 * -----------------------------------------------------
 * A type whose term changes next year must not silently change what a decision
 * told somebody last year. The clause on a decision is a record of what that
 * person was told, so it is copied at the moment the decision is taken and
 * never re-read afterwards. This is the opposite of how the type's other
 * settings work, and it is deliberate.
 *
 * WHY PUBLISHING A TYPE WITH NO DECLARATION IS REFUSED
 * -----------------------------------------------------
 * A decision that tells nobody how to contest it is the failure this exists to
 * prevent, and it is invisible: the decision looks complete, it is sent, and
 * the omission surfaces when somebody's term has already expired. `geen` is a
 * legitimate declaration and is how a type says "no remedy" on purpose, which
 * is what makes the refusal of an UNSET declaration safe to enforce.
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
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-007)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use InvalidArgumentException;

/**
 * Resolves a decision type's remedy declaration onto a decision.
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-007)
 */
final class LegalRemedyResolver {
	/**
	 * The remedies a type may declare.
	 *
	 * @var array<int, string>
	 */
	public const KINDS = ['bezwaar', 'beroep', 'administratief-beroep', 'geen'];

	/**
	 * How each remedy is named in the sentence a decision carries.
	 *
	 * @var array<string, string>
	 */
	private const DUTCH_NAMES = [
		'bezwaar' => 'bezwaar maken',
		'beroep' => 'beroep instellen',
		'administratief-beroep' => 'administratief beroep instellen',
	];

	/**
	 * Refuse a decision type that does not say how its decisions are contested.
	 *
	 * @param array<string, mixed> $type The decision type about to be published.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the declaration is missing or malformed.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-007)
	 */
	public function assertPublishable(array $type): void {
		$remedies = ($type['legalRemedies'] ?? null);

		if (is_array($remedies) === false || $remedies === []) {
			throw new InvalidArgumentException(
				'This decision type does not declare its legal remedies, so a decision taken under it '
				.'would tell nobody how to contest it. Declare a remedy, or declare "geen" if none is open.'
			);
		}

		foreach ($remedies as $remedy) {
			$this->assertRemedyIsUsable(remedy: $remedy);
		}
	}//end assertPublishable()

	/**
	 * Refuse one declared remedy that could not be acted on.
	 *
	 * @param mixed $remedy The declared remedy.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the remedy is malformed or incomplete.
	 */
	private function assertRemedyIsUsable(mixed $remedy): void {
		if (is_array($remedy) === false) {
			throw new InvalidArgumentException('Each declared remedy is an object with at least a kind.');
		}

		$kind = (string)($remedy['kind'] ?? '');
		if (in_array($kind, self::KINDS, true) === false) {
			throw new InvalidArgumentException(
				sprintf('Unknown remedy "%s"; expected one of %s.', $kind, implode(', ', self::KINDS))
			);
		}

		if ($kind === 'geen') {
			// Nothing else to declare: "geen" is the statement that no remedy
			// is open, so a term and a body would have nothing to describe.
			return;
		}

		$term = ($remedy['termDays'] ?? null);
		if (is_numeric($term) === false || (int)$term < 1) {
			throw new InvalidArgumentException(
				sprintf('The %s remedy needs the term in days; a remedy with no term cannot be acted on in time.', $kind)
			);
		}

		if (trim((string)($remedy['body'] ?? '')) === '') {
			throw new InvalidArgumentException(
				sprintf('The %s remedy needs the body it is lodged with.', $kind)
			);
		}
	}//end assertRemedyIsUsable()

	/**
	 * The clause to stamp on a decision of this type, at the moment it is taken.
	 *
	 * @param array<string, mixed> $type The decision type.
	 *
	 * @return array<string, mixed> The clause.
	 *
	 * @throws InvalidArgumentException When the type declares no remedies.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-007)
	 */
	public function resolve(array $type): array {
		$this->assertPublishable(type: $type);

		$remedy = $type['legalRemedies'][0];
		$kind = (string)$remedy['kind'];
		$term = (int)($remedy['termDays'] ?? 0);
		$body = trim((string)($remedy['body'] ?? ''));
		$text = trim((string)($remedy['text'] ?? ''));

		$clause = $text;
		if ($clause === '') {
			$clause = $this->compose(kind: $kind, termDays: $term, body: $body);
		}

		return [
			'kind' => $kind,
			'termDays' => $term,
			'body' => $body,
			'text' => $clause,
		];
	}//end resolve()

	/**
	 * Stamp the clause onto a decision, leaving an already-stamped one alone.
	 *
	 * Already-stamped is left alone on purpose: re-resolving would quietly
	 * rewrite what a decision told somebody, which is the whole thing this
	 * resolver exists to prevent.
	 *
	 * @param array<string, mixed> $decision The decision being taken.
	 * @param array<string, mixed> $type Its decision type.
	 *
	 * @return array<string, mixed> The decision, carrying its clause.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-007)
	 */
	public function stamp(array $decision, array $type): array {
		if (is_array($decision['legalRemedyClause'] ?? null) === true && $decision['legalRemedyClause'] !== []) {
			return $decision;
		}

		$decision['legalRemedyClause'] = $this->resolve(type: $type);

		return $decision;
	}//end stamp()

	/**
	 * Compose the sentence a decision carries when the type does not write its
	 * own.
	 *
	 * Written in weeks when the term divides into them, because that is how a
	 * term is given in Dutch administrative law and how the person reading it
	 * will count.
	 *
	 * @param string $kind The remedy.
	 * @param int $termDays Its term in days.
	 * @param string $body The body it is lodged with.
	 *
	 * @return string The sentence.
	 */
	private function compose(string $kind, int $termDays, string $body): string {
		if ($kind === 'geen') {
			return 'Tegen dit besluit staat geen bezwaar of beroep open.';
		}

		$verb = (self::DUTCH_NAMES[$kind] ?? 'bezwaar maken');
		$term = $this->describeTerm(termDays: $termDays);

		return sprintf(
			'Bent u het niet eens met dit besluit? Dan kunt u binnen %s %s bij %s.',
			$term,
			$verb,
			$body
		);
	}//end compose()

	/**
	 * A term in weeks where it divides evenly, and in days otherwise, with small
	 * numbers written out.
	 *
	 * Written out because that is how a term is given in a Dutch besluit and how
	 * the person reading it will read it back. "6 weken" in a sentence somebody
	 * has six weeks to act on reads like a field that was not filled in.
	 *
	 * @param int $termDays The term.
	 *
	 * @return string The term in words.
	 */
	private function describeTerm(int $termDays): string {
		if ($termDays > 0 && ($termDays % 7) === 0) {
			$weeks = intdiv($termDays, 7);

			if ($weeks === 1) {
				return 'een week';
			}

			return sprintf('%s weken', $this->inWords(number: $weeks));
		}

		if ($termDays === 1) {
			return 'een dag';
		}

		return sprintf('%s dagen', $this->inWords(number: $termDays));
	}//end describeTerm()

	/**
	 * Small numbers in words, larger ones as digits.
	 *
	 * @param int $number The number.
	 *
	 * @return string The number as it is read.
	 */
	private function inWords(int $number): string {
		$words = [
			2 => 'twee',
			3 => 'drie',
			4 => 'vier',
			5 => 'vijf',
			6 => 'zes',
			7 => 'zeven',
			8 => 'acht',
			9 => 'negen',
			10 => 'tien',
			11 => 'elf',
			12 => 'twaalf',
		];

		return ($words[$number] ?? (string)$number);
	}//end inWords()
}//end class
