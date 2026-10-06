<?php

/**
 * Unit tests for ProxyHolderCap — the per-holder proxy limit on grants.
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
 * @spec openspec/specs/voting-system/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\ProxyHolderCap;
use OCA\Decidiq\Service\ProxyVoteService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Tests the configured cap, the fallback to the default and the refusal message.
 *
 * @spec openspec/specs/voting-system/spec.md
 */
class ProxyHolderCapTest extends TestCase {

	/**
	 * Build the cap with `max_proxies_per_holder` set to the given value.
	 *
	 * @param int $configured The configured cap
	 *
	 * @return ProxyHolderCap
	 */
	private function capConfiguredAt(int $configured): ProxyHolderCap {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $key, int $default): int => ($key === ProxyVoteService::MAX_PROXIES_CONFIG_KEY ? $configured : $default)
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($appConfig);

		return new ProxyHolderCap(container: $container, logger: new NullLogger());
	}//end capConfiguredAt()

	/**
	 * Grants held by one holder, from distinct grantors.
	 *
	 * @param int $count Number of grants
	 *
	 * @return list<array{fromParticipantId: string, toParticipantId: string}>
	 */
	private static function grantsTo(int $count): array {
		$grants = [];
		for ($i = 0; $i < $count; $i++) {
			$grants[] = ['fromParticipantId' => 'grantor-' . $i, 'toParticipantId' => 'holder-uuid'];
		}

		return $grants;
	}//end grantsTo()

	/**
	 * A configured cap of 3 lets a holder with two proxies receive a third.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function testConfiguredCapIsHonoured(): void {
		$this->capConfiguredAt(configured: 3)->assertRoomFor(grants: self::grantsTo(count: 2), toParticipantId: 'holder-uuid');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('(3 van 3)');
		$this->capConfiguredAt(configured: 3)->assertRoomFor(grants: self::grantsTo(count: 3), toParticipantId: 'holder-uuid');

	}//end testConfiguredCapIsHonoured()

	/**
	 * A cap below 1 never switches the limit off: the default applies.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function testCapBelowOneFallsBackToTheDefault(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('(2 van 2)');
		$this->capConfiguredAt(configured: 0)->assertRoomFor(grants: self::grantsTo(count: 2), toParticipantId: 'holder-uuid');

	}//end testCapBelowOneFallsBackToTheDefault()

	/**
	 * Grants held by someone else do not count against the receiver.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function testOnlyTheReceiversGrantsCount(): void {
		$this->capConfiguredAt(configured: 2)->assertRoomFor(grants: self::grantsTo(count: 2), toParticipantId: 'other-uuid');

		$this->addToAssertionCount(1);

	}//end testOnlyTheReceiversGrantsCount()
}//end class
