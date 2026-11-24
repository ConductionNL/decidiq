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
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?ObjectFindOptions $options = null) use ($objects): ?ObjectEntity {
				$row = ($objects[(string)$options?->schema . '/' . (string)$id] ?? null);
				if ($row === null) {
					return null;
				}

				$entity = $this->createMock(ObjectEntity::class);
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
			[
				'voting-round/round-1' => ['notes' => [self::grant('a', 'me'), self::grant('me', 'x'), self::grant('b', 'other'), ['title' => 'Note', 'body' => 'x'], 'junk']],
				'participant/a' => ['displayName' => 'Anna'],
			]
		);

		$result = $holdings->proxiesFor(votingRoundId: 'round-1', participantId: 'me');

		self::assertSame('me', $result['participantId']);
		self::assertSame([['participantId' => 'a', 'displayName' => 'Anna']], $result['held']);
		self::assertSame('x', $result['granted']);
	}//end testProxiesForListsHeldAndGranted()

	/**
	 * A delegator whose name cannot be read is shown by UUID; nothing held or given reads empty.
	 *
	 * @return void
	 */
	public function testUnknownDelegatorFallsBackToUuidAndNoGrantsReadEmpty(): void {
		$holdings = $this->holdings(['voting-round/round-1' => ['notes' => [self::grant('ghost', 'me')]], 'voting-round/round-2' => ['notes' => 'none']]);

		self::assertSame([['participantId' => 'ghost', 'displayName' => 'ghost']], $holdings->proxiesFor(votingRoundId: 'round-1', participantId: 'me')['held']);
		self::assertSame(['participantId' => 'me', 'held' => [], 'granted' => null], $holdings->proxiesFor(votingRoundId: 'round-2', participantId: 'me'));
	}//end testUnknownDelegatorFallsBackToUuidAndNoGrantsReadEmpty()

	/**
	 * An unknown round is refused.
	 *
	 * @return void
	 */
	public function testAnUnknownRoundThrows(): void {
		$this->expectException(RuntimeException::class);
		$this->holdings([])->proxiesFor(votingRoundId: 'nope', participantId: 'me');
	}//end testAnUnknownRoundThrows()
}//end class
