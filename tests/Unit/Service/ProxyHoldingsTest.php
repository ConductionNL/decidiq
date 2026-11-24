<?php

/**
 * Unit tests for ProxyHoldings.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\ProxyHoldings;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Decidiq\Service\ProxyHoldings
 *
 * @spec openspec/specs/voting-system/spec.md#requirement-proxy-voting
 */
class ProxyHoldingsTest extends TestCase {

	/**
	 * A Proxy note from one participant to another.
	 *
	 * @param string $from The grantor
	 * @param string $to   The holder
	 *
	 * @return array<string, string>
	 */
	private static function grant(string $from, string $to): array {
		return ['title' => 'Proxy', 'body' => (string)json_encode(['fromParticipantId' => $from, 'toParticipantId' => $to])];
	}//end grant()

	/**
	 * Holdings over the given objects, keyed by schema/id.
	 *
	 * @param array<string, array<string, mixed>> $objects The stored objects
	 *
	 * @return ProxyHoldings
	 */
	private function holdings(array $objects): ProxyHoldings {
		$objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?ObjectFindOptions $options = null) use ($objects): ?ObjectEntity {
				$row = ($objects[(string)$options?->schema . '/' . (string)$id] ?? null);
				if ($row === null) {
					return null;
				}

				$entity = $this->createMock(originalClassName: ObjectEntity::class);
				$entity->method('jsonSerialize')->willReturn($row);
				return $entity;
			}
		);

		return new ProxyHoldings(objectService: $objectService, logger: new NullLogger());
	}//end holdings()

	/**
	 * The proxies held are named, the one given is reported, others are ignored (vot-10, #1377).
	 *
	 * @return void
	 */
	public function testProxiesForListsHeldAndGranted(): void {
		$holdings = $this->holdings(
			objects: [
				'voting-round/round-1' => [
					'notes' => [
						self::grant(from: 'a', to: 'me'),
						self::grant(from: 'me', to: 'x'),
						self::grant(from: 'b', to: 'other'),
						['title' => 'Note', 'body' => 'x'],
						'junk',
					],
				],
				'participant/a' => ['displayName' => 'Anna'],
			]
		);

		$result = $holdings->proxiesFor(votingRoundId: 'round-1', participantId: 'me');

		self::assertSame(expected: 'me', actual: $result['participantId']);
		self::assertSame(expected: [['participantId' => 'a', 'displayName' => 'Anna']], actual: $result['held']);
		self::assertSame(expected: 'x', actual: $result['granted']);
	}//end testProxiesForListsHeldAndGranted()

	/**
	 * A delegator whose name cannot be read is shown by UUID; nothing held or given reads empty.
	 *
	 * @return void
	 */
	public function testUnknownDelegatorFallsBackToUuidAndNoGrantsReadEmpty(): void {
		$holdings = $this->holdings(
			objects: [
				'voting-round/round-1' => ['notes' => [self::grant(from: 'ghost', to: 'me')]],
				'voting-round/round-2' => ['notes' => 'none'],
			]
		);

		$ghost = $holdings->proxiesFor(votingRoundId: 'round-1', participantId: 'me');
		self::assertSame(expected: [['participantId' => 'ghost', 'displayName' => 'ghost']], actual: $ghost['held']);
		$empty = $holdings->proxiesFor(votingRoundId: 'round-2', participantId: 'me');
		self::assertSame(expected: ['participantId' => 'me', 'held' => [], 'granted' => null], actual: $empty);
	}//end testUnknownDelegatorFallsBackToUuidAndNoGrantsReadEmpty()

	/**
	 * An unknown round is refused.
	 *
	 * @return void
	 */
	public function testAnUnknownRoundThrows(): void {
		$this->expectException(exception: RuntimeException::class);
		$this->holdings(objects: [])->proxiesFor(votingRoundId: 'nope', participantId: 'me');
	}//end testAnUnknownRoundThrows()
}//end class
