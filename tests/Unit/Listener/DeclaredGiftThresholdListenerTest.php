<?php

/**
 * Unit tests for DeclaredGiftThresholdListener and fragment 136: a gift is
 * stamped against its body's threshold, only a gift at or above it notifies,
 * and the annual review reminder has its 12-month cutoff (REQ-INT-006,
 * REQ-INT-008, REQ-INT-009).
 *
 * @category Tests
 * @package  OCA\Decidiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-25-interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-006-gift-threshold-per-gedragscode-drives-badge-and-notification
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\Listener\DeclaredGiftThresholdListener;
use OCA\Decidiq\Listener\Registrar\SaveGuardSubscriptions;
use OCA\Decidiq\Tests\Unit\Support\MergedRegisterSchema;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for DeclaredGiftThresholdListener.
 */
final class DeclaredGiftThresholdListenerTest extends TestCase {
	/**
	 * The filters the object service was asked for.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $asked = [];

	/**
	 * A listener over bodies with the given configured thresholds.
	 *
	 * @param array<string, mixed> $thresholds Per body uuid, its giftThresholdAmount.
	 * @param bool                 $broken     Whether reading a configuration throws.
	 *
	 * @return DeclaredGiftThresholdListener
	 */
	private function listener(array $thresholds = [], bool $broken = false): DeclaredGiftThresholdListener {
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true) use ($thresholds, $broken): array {
				self::assertFalse(condition: $_rbac, message: 'the threshold is policy, read whoever saves the gift');
				$this->asked[] = $config['filters'];
				if ($broken === true) {
					throw new RuntimeException('register down');
				}

				$body = (string)$config['filters']['governanceBody'];
				if (array_key_exists($body, $thresholds) === false) {
					return [];
				}

				$entity = new ObjectEntity();
				$entity->setObject(['governanceBody' => $body, 'giftThresholdAmount' => $thresholds[$body]]);
				return [$entity];
			}
		);

		return new DeclaredGiftThresholdListener(configurations: $objects, logger: new NullLogger());
	}//end listener()

	/**
	 * An OpenRegister object entity.
	 *
	 * @param array<string, mixed> $payload The object.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $payload): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject($payload);
		return $entity;
	}//end entity()

	/**
	 * A gift of EUR 75 under the default EUR 50 threshold is above; EUR 15 is below.
	 *
	 * @return void
	 */
	public function testAGiftIsStampedAgainstTheDefaultThreshold(): void {
		$above = new ObjectCreatingEvent($this->entity(payload: ['_schemaSlug' => 'declared-gift', 'governanceBody' => 'raad', 'estimatedValue' => 75]));
		$this->listener()->handle($above);
		self::assertSame(expected: ['thresholdStatus' => 'above'], actual: $above->getModifiedData());

		$below = new ObjectCreatingEvent($this->entity(payload: ['_schemaSlug' => 'declared-gift', 'governanceBody' => 'raad', 'estimatedValue' => 15]));
		$this->listener()->handle($below);
		self::assertSame(expected: ['thresholdStatus' => 'below'], actual: $below->getModifiedData());

		self::assertSame(
			expected: ['register' => 'decidiq', 'schema' => 'body-governance-configuration', 'governanceBody' => 'raad'],
			actual: $this->asked[0]
		);
	}//end testAGiftIsStampedAgainstTheDefaultThreshold()

	/**
	 * A body configured at EUR 25 puts a EUR 30 gift above; exactly the threshold is above.
	 *
	 * @return void
	 */
	public function testTheBodysConfiguredThresholdApplies(): void {
		$listener = $this->listener(thresholds: ['rvc' => 25]);

		self::assertSame(expected: 'above', actual: $listener->statusOf(gift: ['governanceBody' => 'rvc', 'estimatedValue' => 30]));
		self::assertSame(expected: 'above', actual: $listener->statusOf(gift: ['governanceBody' => 'rvc', 'estimatedValue' => '25']));
		self::assertSame(expected: 'below', actual: $listener->statusOf(gift: ['governanceBody' => 'rvc', 'estimatedValue' => 24.99]));
		self::assertSame(
			expected: 'below',
			actual: $listener->statusOf(gift: ['governanceBody' => ['id' => 'raad'], 'estimatedValue' => 30]),
			message: 'no configuration: EUR 50'
		);
	}//end testTheBodysConfiguredThresholdApplies()

	/**
	 * An update re-stamps from the merged gift, and a client's own stamp is replaced.
	 *
	 * @return void
	 */
	public function testAnUpdateRestampsAndTheClientsStampIsReplaced(): void {
		$old = $this->entity(payload: ['_schemaSlug' => 'declared-gift', 'governanceBody' => 'raad', 'estimatedValue' => 15]);
		$new = $this->entity(payload: ['_schemaSlug' => 'declared-gift', 'governanceBody' => 'raad', 'estimatedValue' => 15, 'thresholdStatus' => 'below']);
		$event = new ObjectUpdatingEvent($new, $old);
		$event->setModifiedData(['estimatedValue' => 120, 'thresholdStatus' => 'below']);

		$this->listener()->handle($event);

		self::assertSame(expected: ['estimatedValue' => 120, 'thresholdStatus' => 'above'], actual: $event->getModifiedData());
	}//end testAnUpdateRestampsAndTheClientsStampIsReplaced()

	/**
	 * Without a value there is no stamp; without a body, or when the
	 * configuration cannot be read, the default threshold applies.
	 *
	 * @return void
	 */
	public function testMissingValueBodyOrConfiguration(): void {
		self::assertNull(actual: $this->listener()->statusOf(gift: ['governanceBody' => 'raad']));
		self::assertNull(actual: $this->listener()->statusOf(gift: ['governanceBody' => 'raad', 'estimatedValue' => 'onbekend']));

		self::assertSame(expected: 'above', actual: $this->listener()->statusOf(gift: ['estimatedValue' => 50]));
		self::assertSame(expected: [], actual: $this->asked, message: 'no body, nothing read');

		$broken = $this->listener(thresholds: ['rvc' => 500], broken: true);
		self::assertSame(expected: 'above', actual: $broken->statusOf(gift: ['governanceBody' => 'rvc', 'estimatedValue' => 60]));
	}//end testMissingValueBodyOrConfiguration()

	/**
	 * Another schema is not stamped.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsLeftAlone(): void {
		$event = new ObjectCreatingEvent($this->entity(payload: ['_schemaSlug' => 'ancillary-position', 'estimatedValue' => 75]));
		$this->listener()->handle($event);

		self::assertSame(expected: [], actual: $event->getModifiedData());
	}//end testAnotherSchemaIsLeftAlone()

	/**
	 * The listener runs before create and before update, for declared gifts only.
	 *
	 * @return void
	 */
	public function testTheListenerIsSubscribedOnCreateAndUpdate(): void {
		$mine = array_values(
			array_filter(
				SaveGuardSubscriptions::ALL,
				static fn (array $guard): bool => $guard['listener'] === DeclaredGiftThresholdListener::class
			)
		);

		self::assertSame(expected: [ObjectCreatingEvent::class, ObjectUpdatingEvent::class], actual: array_column($mine, 'event'));
		self::assertSame(expected: [['declared-gift'], ['declared-gift']], actual: array_column($mine, 'schemas'));
	}//end testTheListenerIsSubscribedOnCreateAndUpdate()

	/**
	 * The merged gift schema carries the stamp, and its notification fires only above the threshold.
	 *
	 * @return void
	 */
	public function testTheGiftNotificationFiresOnlyAboveTheThreshold(): void {
		$schema = MergedRegisterSchema::forSlug(slug: 'declared-gift');

		self::assertSame(expected: ['above', 'below'], actual: $schema['properties']['thresholdStatus']['enum']);
		self::assertNotContains(needle: 'thresholdStatus', haystack: ($schema['required'] ?? []));

		$trigger = $schema['x-openregister-notifications']['geschenkBovenDrempel']['trigger'];
		self::assertSame(expected: 'created', actual: $trigger['type']);
		self::assertSame(expected: ['field' => 'thresholdStatus', 'operator' => 'equals', 'value' => 'above'], actual: $trigger['filter']);
	}//end testTheGiftNotificationFiresOnlyAboveTheThreshold()

	/**
	 * The annual review reminder reaches a disclosure not reviewed, or never
	 * reviewed and declared, more than 12 months ago, and never an ended one.
	 *
	 * @return void
	 */
	public function testTheAnnualReviewReminderHasItsTwelveMonthCutoff(): void {
		$schema = MergedRegisterSchema::forSlug(slug: 'ancillary-position');
		$filter = $schema['x-openregister-notifications']['nevenfunctieJaarlijkseReview']['trigger']['filter'];

		self::assertSame(expected: ['operator' => 'in', 'values' => ['reported', 'public', 'internal']], actual: $filter['lifecycle']);
		self::assertSame(
			expected: [
				['reviewedAt' => ['operator' => 'olderThan', 'value' => 'P12M']],
				['all' => [
					['reviewedAt' => ['operator' => 'equals', 'value' => null]],
					['declaredAt' => ['operator' => 'olderThan', 'value' => 'P12M']],
				]],
			],
			actual: $filter['any']
		);
	}//end testTheAnnualReviewReminderHasItsTwelveMonthCutoff()
}//end class
