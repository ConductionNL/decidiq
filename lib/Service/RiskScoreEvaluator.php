<?php

/**
 * Decidiq RiskScoreEvaluator
 *
 * Scores a subject against a declarative risk expression: a list of rules
 * that each add points when one of the subject's own properties matches, and
 * a list of bands that name the total. No model and no service call: the
 * expression is configuration of the decision type, and the same subject and
 * expression always give the same score.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md#requirement-req-dwp-008-a-risk-score-is-computed-before-approval
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use InvalidArgumentException;

/**
 * Evaluates a `riskScore` expression over a subject's properties.
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md#requirement-req-dwp-008-a-risk-score-is-computed-before-approval
 */
final class RiskScoreEvaluator {

	/**
	 * The operators that compare as numbers.
	 */
	public const NUMERIC_OPERATORS = ['gt', 'gte', 'lt', 'lte'];

	/**
	 * Every operator a rule may name.
	 */
	public const OPERATORS = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'in', 'notIn', 'present'];

	/**
	 * The score of a subject, or null when the expression declares no rules.
	 *
	 * The basis lists only the rules that matched, in their declared order,
	 * so the approver sees the properties that produced the score.
	 *
	 * @param mixed                $expression The type's `riskScore`.
	 * @param array<string, mixed> $subject    The subject's properties.
	 *
	 * @return array{score: float|int, band: string|null, basis: array<int, array{property: string, value: string, points: float|int}>}|null
	 *
	 * @throws InvalidArgumentException When a rule or band is malformed; the message names it.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md#requirement-req-dwp-008-a-risk-score-is-computed-before-approval
	 */
	public function evaluate(mixed $expression, array $subject): ?array {
		$rules = self::listOf(expression: $expression, key: 'rules');
		if ($rules === []) {
			return null;
		}

		$score = 0;
		$basis = [];
		foreach ($rules as $index => $rule) {
			$checked = self::checkedRule(rule: $rule, index: $index);
			$value   = self::read(subject: $subject, path: $checked['property']);
			if (self::matches(rule: $checked, value: $value) === false) {
				continue;
			}

			$score  += $checked['points'];
			$basis[] = ['property' => $checked['property'], 'value' => self::asText(value: $value), 'points' => $checked['points']];
		}

		return [
			'score' => $score,
			'band'  => $this->bandFor(bands: self::listOf(expression: $expression, key: 'bands'), score: $score),
			'basis' => $basis,
		];
	}//end evaluate()

	/**
	 * The properties an expression reads, in declared order without repeats.
	 *
	 * @param mixed $expression The type's `riskScore`.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md#requirement-req-dwp-008-a-risk-score-is-computed-before-approval
	 */
	public function readProperties(mixed $expression): array {
		$read = [];
		foreach (self::listOf(expression: $expression, key: 'rules') as $rule) {
			if (is_array($rule) === false) {
				continue;
			}

			$property = trim((string)($rule['property'] ?? ''));
			if ($property !== '' && in_array($property, $read, true) === false) {
				$read[] = $property;
			}
		}

		return $read;
	}//end readProperties()

	/**
	 * The band a score falls in: the band with the highest minimum the score reaches.
	 *
	 * @param array<int|string, mixed> $bands The declared bands.
	 * @param float|int                $score The score.
	 *
	 * @return string|null Null when no band is declared or the score is below every band.
	 *
	 * @throws InvalidArgumentException When a band has no name or no numeric minimum.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md#requirement-req-dwp-008-a-risk-score-is-computed-before-approval
	 */
	public function bandFor(array $bands, float|int $score): ?string {
		$band = null;
		$best = null;
		foreach ($bands as $index => $declared) {
			if (is_array($declared) === false) {
				$declared = [];
			}

			$name = trim((string)($declared['band'] ?? ''));
			$min  = ($declared['min'] ?? null);
			if ($name === '' || is_numeric($min) === false) {
				throw new InvalidArgumentException('Risk band ' . ((int)$index + 1) . ' needs a name and a numeric minimum.');
			}

			$min = $min + 0;
			if ($score >= $min && ($best === null || $min > $best)) {
				$band = $name;
				$best = $min;
			}
		}

		return $band;
	}//end bandFor()

	/**
	 * A rule with its property, operator and points checked.
	 *
	 * @param mixed      $rule  The declared rule.
	 * @param int|string $index Its place in the list, for the message.
	 *
	 * @return array{property: string, operator: string, points: float|int, value: string, values: array<int, string>}
	 *
	 * @throws InvalidArgumentException When the rule is malformed.
	 */
	private static function checkedRule(mixed $rule, int|string $index): array {
		$label = 'Risk rule ' . ((int)$index + 1);
		if (is_array($rule) === false) {
			throw new InvalidArgumentException($label . ' is not an object.');
		}

		$property = trim((string)($rule['property'] ?? ''));
		$operator = (string)($rule['operator'] ?? '');
		$points   = ($rule['points'] ?? null);
		if ($property === '' || in_array($operator, self::OPERATORS, true) === false || is_numeric($points) === false) {
			throw new InvalidArgumentException($label . ' needs a property, a known operator and numeric points.');
		}

		$value = ($rule['value'] ?? '');
		if (in_array($operator, self::NUMERIC_OPERATORS, true) === true && is_numeric($value) === false) {
			throw new InvalidArgumentException($label . ' compares as a number, so its value must be a number.');
		}

		$values = [];
		if (is_array($rule['values'] ?? null) === true) {
			$values = array_map(self::asText(...), array_values($rule['values']));
		}

		return [
			'property' => $property,
			'operator' => $operator,
			'points'   => $points + 0,
			'value'    => self::asText(value: $value),
			'values'   => $values,
		];
	}//end checkedRule()

	/**
	 * Whether a property's value matches a checked rule.
	 *
	 * A property the subject does not carry matches only `neq` and `notIn`
	 * (it is not the value) and never a numeric comparison.
	 *
	 * @param array{property: string, operator: string, points: float|int, value: string, values: array<int, string>} $rule  The rule.
	 * @param mixed                                                                                                   $value The property's value.
	 *
	 * @return bool
	 */
	private static function matches(array $rule, mixed $value): bool {
		$operator = $rule['operator'];
		if ($operator === 'present') {
			return self::asText(value: $value) !== '';
		}

		if (in_array($operator, self::NUMERIC_OPERATORS, true) === true) {
			return self::comparesAsNumber(operator: $operator, value: $value, against: (float)$rule['value']);
		}

		$text = self::asText(value: $value);

		return match ($operator) {
			'eq'    => $text === $rule['value'],
			'neq'   => $text !== $rule['value'],
			'in'    => in_array($text, $rule['values'], true),
			default => in_array($text, $rule['values'], true) === false,
		};
	}//end matches()

	/**
	 * Whether a value compares to a number as the operator says.
	 *
	 * @param string $operator One of the numeric operators.
	 * @param mixed  $value    The property's value; a value that is no number never matches.
	 * @param float  $against  The rule's value.
	 *
	 * @return bool
	 */
	private static function comparesAsNumber(string $operator, mixed $value, float $against): bool {
		if (is_numeric($value) === false) {
			return false;
		}

		$number = (float)$value;

		return match ($operator) {
			'gt'    => $number > $against,
			'gte'   => $number >= $against,
			'lt'    => $number < $against,
			default => $number <= $against,
		};
	}//end comparesAsNumber()

	/**
	 * A property of the subject; a dotted path reads into nested objects.
	 *
	 * @param array<string, mixed> $subject The subject.
	 * @param string               $path    The property or dotted path.
	 *
	 * @return mixed Null when the subject does not carry it.
	 */
	private static function read(array $subject, string $path): mixed {
		if (array_key_exists($path, $subject) === true) {
			return $subject[$path];
		}

		$value = $subject;
		foreach (explode('.', $path) as $part) {
			if (is_array($value) === false || array_key_exists($part, $value) === false) {
				return null;
			}

			$value = $value[$part];
		}

		return $value;
	}//end read()

	/**
	 * A value as the text a rule compares and the basis shows.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string '' for null, an array or an object.
	 */
	private static function asText(mixed $value): string {
		if ($value === true) {
			return 'true';
		}

		if ($value === false) {
			return 'false';
		}

		if (is_scalar($value) === false) {
			return '';
		}

		return trim((string)$value);
	}//end asText()

	/**
	 * A list the expression declares under a key; empty when it declares none.
	 *
	 * @param mixed  $expression The expression.
	 * @param string $key        `rules` or `bands`.
	 *
	 * @return array<int|string, mixed>
	 */
	private static function listOf(mixed $expression, string $key): array {
		if (is_array($expression) === false || is_array($expression[$key] ?? null) === false) {
			return [];
		}

		return $expression[$key];
	}//end listOf()
}//end class
