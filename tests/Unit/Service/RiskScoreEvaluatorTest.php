<?php

/**
 * A decision type's risk expression scores a decision from its own properties.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md#requirement-req-dwp-008-a-risk-score-is-computed-before-approval
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Decidiq\Service\RiskScoreEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Task 8.4: PHPUnit on the expression evaluator.
 */
class RiskScoreEvaluatorTest extends TestCase {

	/**
	 * The expression of the spec's scenario: over `amount` and `category`.
	 *
	 * @var array<string, mixed>
	 */
	private const EXPRESSION = [
		'rules' => [
			['property' => 'amount', 'operator' => 'gte', 'value' => '100000', 'points' => 3],
			['property' => 'category', 'operator' => 'in', 'values' => ['subsidy', 'grant'], 'points' => 2],
			['property' => 'urgent', 'operator' => 'eq', 'value' => 'true', 'points' => 1],
		],
		'bands' => [
			['band' => 'low', 'min' => 0],
			['band' => 'high', 'min' => 5],
			['band' => 'medium', 'min' => 3],
		],
	];

	/**
	 * A high amount in a risky category: the approver sees the band and the
	 * two properties that produced it, and nothing about the rule that did not match.
	 *
	 * @return void
	 */
	public function testAHighAmountInARiskyCategoryScoresHighAndSaysWhy(): void {
		$result = (new RiskScoreEvaluator())->evaluate(
			expression: self::EXPRESSION,
			subject: ['amount' => 250000, 'category' => 'subsidy', 'urgent' => false]
		);

		self::assertSame(
			expected: [
				'score' => 5,
				'band'  => 'high',
				'basis' => [
					['property' => 'amount', 'value' => '250000', 'points' => 3],
					['property' => 'category', 'value' => 'subsidy', 'points' => 2],
				],
			],
			actual: $result
		);
	}//end testAHighAmountInARiskyCategoryScoresHighAndSaysWhy()

	/**
	 * The band is the one with the highest minimum the score reaches, whatever
	 * order the bands are declared in.
	 *
	 * @return void
	 */
	public function testTheBandIsTheHighestReached(): void {
		$evaluator = new RiskScoreEvaluator();

		self::assertSame(expected: 'low', actual: $evaluator->evaluate(expression: self::EXPRESSION, subject: ['amount' => '500'])['band']);
		self::assertSame(expected: 'medium', actual: $evaluator->evaluate(expression: self::EXPRESSION, subject: ['amount' => '100000'])['band']);
		self::assertNull(actual: $evaluator->bandFor(bands: [['band' => 'high', 'min' => 5]], score: 2));
		self::assertNull(actual: $evaluator->bandFor(bands: [], score: 9));
	}//end testTheBandIsTheHighestReached()

	/**
	 * Each operator, including a missing property and a dotted path.
	 *
	 * @return void
	 */
	public function testEveryOperator(): void {
		$cases = [
			[['operator' => 'eq', 'value' => 'a'], 'a', true],
			[['operator' => 'eq', 'value' => 'a'], 'b', false],
			[['operator' => 'neq', 'value' => 'a'], 'b', true],
			[['operator' => 'neq', 'value' => 'a'], null, true],
			[['operator' => 'gt', 'value' => '10'], 11, true],
			[['operator' => 'gt', 'value' => '10'], 10, false],
			[['operator' => 'gte', 'value' => '10'], '10', true],
			[['operator' => 'lt', 'value' => '10'], 9.5, true],
			[['operator' => 'lte', 'value' => '10'], 11, false],
			[['operator' => 'lt', 'value' => '10'], null, false],
			[['operator' => 'gt', 'value' => '10'], 'many', false],
			[['operator' => 'in', 'values' => ['x', 'y']], 'y', true],
			[['operator' => 'in', 'values' => ['x', 'y']], 'z', false],
			[['operator' => 'notIn', 'values' => ['x']], 'z', true],
			[['operator' => 'notIn', 'values' => ['x']], 'x', false],
			[['operator' => 'present'], 'anything', true],
			[['operator' => 'present'], '  ', false],
			[['operator' => 'present'], null, false],
		];

		foreach ($cases as $index => [$rule, $value, $expected]) {
			$expression = ['rules' => [array_merge(['property' => 'p', 'points' => 1], $rule)]];
			$result     = (new RiskScoreEvaluator())->evaluate(expression: $expression, subject: ['p' => $value]);
			self::assertSame(expected: $expected, actual: $result['score'] === 1, message: 'case ' . $index);
		}

		$nested = ['rules' => [['property' => 'applicant.kind', 'operator' => 'eq', 'value' => 'company', 'points' => 2]]];
		$evaluator = new RiskScoreEvaluator();
		self::assertSame(expected: 2, actual: $evaluator->evaluate(expression: $nested, subject: ['applicant' => ['kind' => 'company']])['score']);
		self::assertSame(expected: 0, actual: $evaluator->evaluate(expression: $nested, subject: ['applicant' => 'company'])['score']);
	}//end testEveryOperator()

	/**
	 * Without rules there is no score at all, so nothing reads as "no risk".
	 *
	 * @return void
	 */
	public function testAnExpressionWithoutRulesGivesNoScore(): void {
		$evaluator = new RiskScoreEvaluator();

		self::assertNull(actual: $evaluator->evaluate(expression: null, subject: ['amount' => 1]));
		self::assertNull(actual: $evaluator->evaluate(expression: ['rules' => []], subject: ['amount' => 1]));
		self::assertNull(actual: $evaluator->evaluate(expression: 'amount > 5', subject: ['amount' => 9]));
	}//end testAnExpressionWithoutRulesGivesNoScore()

	/**
	 * A malformed rule or band is refused, naming which one.
	 *
	 * @return void
	 */
	public function testAMalformedExpressionIsRefusedNamingThePart(): void {
		$malformed = [
			[['rules' => ['amount']], 'Risk rule 1'],
			[['rules' => [['property' => 'amount', 'operator' => 'between', 'points' => 1]]], 'Risk rule 1'],
			[['rules' => [['property' => '', 'operator' => 'eq', 'points' => 1]]], 'Risk rule 1'],
			[
				['rules' => [['property' => 'a', 'operator' => 'present', 'points' => 1], ['property' => 'a', 'operator' => 'eq', 'points' => 'many']]],
				'Risk rule 2',
			],
			[['rules' => [['property' => 'a', 'operator' => 'gte', 'value' => 'lots', 'points' => 1]]], 'Risk rule 1'],
			[['rules' => [['property' => 'a', 'operator' => 'present', 'points' => 1]], 'bands' => [['band' => 'high']]], 'Risk band 1'],
		];

		foreach ($malformed as [$expression, $named]) {
			try {
				(new RiskScoreEvaluator())->evaluate(expression: $expression, subject: ['a' => 'x', 'amount' => 1]);
				self::fail(message: 'A malformed expression was evaluated: ' . json_encode($expression));
			} catch (InvalidArgumentException $e) {
				self::assertStringStartsWith(prefix: $named, string: $e->getMessage());
			}
		}
	}//end testAMalformedExpressionIsRefusedNamingThePart()

	/**
	 * The properties an expression reads, once each, in declared order.
	 *
	 * @return void
	 */
	public function testThePropertiesTheExpressionReads(): void {
		$expression = self::EXPRESSION;
		$expression['rules'][] = ['property' => 'amount', 'operator' => 'gte', 'value' => '1000000', 'points' => 2];

		self::assertSame(expected: ['amount', 'category', 'urgent'], actual: (new RiskScoreEvaluator())->readProperties(expression: $expression));
		self::assertSame(expected: [], actual: (new RiskScoreEvaluator())->readProperties(expression: null));
	}//end testThePropertiesTheExpressionReads()
}//end class
