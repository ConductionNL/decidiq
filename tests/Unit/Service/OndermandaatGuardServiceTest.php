<?php

/**
 * OndermandaatGuardService: a parent must permit ondermandaat and the chain may not loop.
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
 * @spec openspec/changes/delegatie-mandaatregister/specs/delegatie-mandaatregister/spec.md#requirement-req-dmr-003-ondermandaat-chains-are-permitted-displayed-and-cycle-free
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Exception\OndermandaatRefusedException;
use OCA\Decidiq\Service\OndermandaatGuardService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Decidiq\Service\OndermandaatGuardService
 */
class OndermandaatGuardServiceTest extends TestCase {

	/**
	 * Stored toedelingen by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $stored = [
		'mandaat-secretaris' => ['id' => 'mandaat-secretaris', 'subMandatePermitted' => true],
		'delegatie-college' => ['id' => 'delegatie-college', 'subMandatePermitted' => false],
		'ondermandaat-a' => ['id' => 'ondermandaat-a', 'subMandatePermitted' => true, 'parentAllocation' => 'ondermandaat-b'],
		'ondermandaat-b' => ['id' => 'ondermandaat-b', 'subMandatePermitted' => true, 'parentAllocation' => 'ondermandaat-a'],
	];

	/**
	 * The ids read, in order.
	 *
	 * @var list<string>
	 */
	private array $reads = [];

	/**
	 * Toedelingen still on the old Dutch slug.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $legacy = [
		'mandaat-oud' => ['id' => 'mandaat-oud', 'subMandatePermitted' => false],
	];

	/**
	 * The guard over the stored toedelingen.
	 *
	 * @param \Throwable|null $failure Thrown on every read.
	 *
	 * @return OndermandaatGuardService
	 */
	private function guard(?\Throwable $failure = null): OndermandaatGuardService {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			function (mixed ...$args) use ($failure): ObjectEntity {
				if ($failure !== null) {
					throw $failure;
				}

				// Parents are read on the renamed schema, where the migration moved
				// the rows, and then on the old Dutch slug.
				$store = $this->stored;
				if (in_array('authority-delegation', $args, true) === false) {
					self::assertContains('bevoegdheidstoedeling', $args);
					$store = $this->legacy;
				}

				$this->reads[] = $args[0];
				if (isset($store[$args[0]]) === false) {
					throw new DoesNotExistException('gone');
				}

				$entity = $this->createMock(ObjectEntity::class);
				$entity->method('jsonSerialize')->willReturn($store[$args[0]]);
				return $entity;
			}
		);

		return new OndermandaatGuardService(objectService: $objects);
	}//end guard()

	/**
	 * Assert the toedeling is refused with this message.
	 *
	 * @param array<string, mixed>          $toedeling The toedeling.
	 * @param string                        $message   The refusal.
	 * @param OndermandaatGuardService|null $guard     The guard.
	 *
	 * @return OndermandaatRefusedException
	 */
	private function refused(array $toedeling, string $message, ?OndermandaatGuardService $guard = null): OndermandaatRefusedException {
		try {
			($guard ?? $this->guard())->assertAllowed(toedeling: $toedeling);
		} catch (OndermandaatRefusedException $e) {
			self::assertSame($message, $e->getMessage());
			return $e;
		}

		self::fail('The toedeling must be refused: ' . $message);
	}//end refused()

	public function testAnOndermandaatUnderAPermittingParentIsAllowed(): void {
		$this->guard()->assertAllowed(toedeling: ['id' => 'ondermandaat-samenleving', 'parentAllocation' => 'mandaat-secretaris']);
		$this->guard()->assertAllowed(toedeling: ['parentAllocation' => ['id' => 'mandaat-secretaris']]);

		self::assertSame(['mandaat-secretaris', 'mandaat-secretaris'], $this->reads);
	}//end testAnOndermandaatUnderAPermittingParentIsAllowed()

	public function testAHoofdmandaatIsNotRead(): void {
		$this->guard(failure: new RuntimeException('must not be read'))->assertAllowed(toedeling: ['id' => 'x', 'parentAllocation' => ' ']);
		self::assertSame([], $this->reads);
	}//end testAHoofdmandaatIsNotRead()

	public function testAParentThatForbidsOndermandaatIsRefusedByName(): void {
		$this->refused(['parentAllocation' => 'delegatie-college'], 'The toedeling was not saved: its parent does not permit ondermandaat.');
	}//end testAParentThatForbidsOndermandaatIsRefusedByName()

	public function testASelfParentAndALongerCycleAreRefused(): void {
		$loop = 'The toedeling was not saved: its parent chain leads back to itself. An ondermandaat chain may not loop.';
		$this->refused(['id' => 'self', 'parentAllocation' => 'self'], $loop);
		$this->refused(['id' => 'ondermandaat-b', 'parentAllocation' => 'ondermandaat-a'], $loop);
		$this->refused(['id' => 'new', 'parentAllocation' => 'ondermandaat-a'], $loop);
	}//end testASelfParentAndALongerCycleAreRefused()

	public function testAMissingParentIsRefused(): void {
		$this->refused(['parentAllocation' => 'gone'], 'The toedeling was not saved: its parent toedeling does not exist.');
	}//end testAMissingParentIsRefused()

	public function testAnUnreadableParentRefusesItDoesNotPermit(): void {
		$failure = new RuntimeException('database gone');
		$refusal = $this->refused(
			['parentAllocation' => 'mandaat-secretaris'],
			'The toedeling was not saved: its parent could not be read to check the ondermandaat chain.',
			$this->guard(failure: $failure)
		);
		self::assertSame($failure, $refusal->getPrevious());
	}//end testAnUnreadableParentRefusesItDoesNotPermit()

	public function testAChainDeeperThanTheLimitIsRefused(): void {
		for ($i = 0; $i < OndermandaatGuardService::MAX_DEPTH + 1; $i++) {
			$this->stored['link-' . $i] = ['id' => 'link-' . $i, 'subMandatePermitted' => true, 'parentAllocation' => 'link-' . ($i + 1)];
		}

		$this->refused(
			['id' => 'start', 'parentAllocation' => 'link-0'],
			'The toedeling was not saved: its parent chain leads back to itself. An ondermandaat chain may not loop.'
		);
	}//end testAChainDeeperThanTheLimitIsRefused()
	/**
	 * A parent still on the old Dutch slug is found there and still checked.
	 *
	 * @return void
	 */
	public function testAParentOnTheOldSlugIsStillChecked(): void {
		$this->refused(
			toedeling: ['parentAllocation' => 'mandaat-oud'],
			message: 'The toedeling was not saved: its parent does not permit ondermandaat.'
		);
		self::assertSame(['mandaat-oud', 'mandaat-oud'], $this->reads, 'read on the renamed schema first, then on the old slug');
	}//end testAParentOnTheOldSlugIsStillChecked()

}//end class
