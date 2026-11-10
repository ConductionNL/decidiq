<?php

/**
 * Golden runs of ALVMinutesService::distribute().
 *
 * Records every notice sent (person, event, title, message, link, in-app
 * payload) and every log line, for a meeting with a title and one without,
 * a member whose notice fails, duplicate and account-less participants,
 * minutes without a meeting, and minutes still in draft. Pinned before
 * distribute() was split into named steps.
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
 * @spec exclude golden test of the minutes notices, no behaviour of its own
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use Exception;
use OCA\Decidiq\Service\ALVMinutesService;
use OCA\Decidiq\Service\MinutesContextResolver;
use OCA\Decidiq\Service\NotificationPreferenceService;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IL10N;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class ALVMinutesDistributeGoldenTest extends TestCase {

	/**
	 * Everything the service did, in order.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $trace = [];

	/**
	 * Each run matches.
	 *
	 * @return void
	 */
	public function testEveryRunIsUnchanged(): void {
		$members = [
			['nextcloudUserId' => 'alice'],
			['owner' => 'bob'],
			['nextcloudUserId' => 'alice'],
			['nextcloudUserId' => 'gone', 'leftAt' => '2025-01-01'],
			['name' => 'no account'],
			['nextcloudUserId' => 'carol'],
		];
		$titled = $this->runDistribute(['lifecycle' => 'approved', 'relations' => ['Meeting' => ['m-1']]], ['title' => 'ALV 2025'], $members);
		self::assertSame(
			[
				['tell', 'alice', 'decisionPublished', 'The minutes of ALV 2025 are available', 'Open the minutes in Decidiq to read them.', '/minutes/minutes-1', ['subject' => 'minutes_available', 'parameters' => ['meetingId' => 'm-1', 'meetingTitle' => 'ALV 2025'], 'objectType' => 'minutes', 'objectId' => 'minutes-1']],
				['tell', 'bob', 'decisionPublished', 'The minutes of ALV 2025 are available', 'Open the minutes in Decidiq to read them.', '/minutes/minutes-1', ['subject' => 'minutes_available', 'parameters' => ['meetingId' => 'm-1', 'meetingTitle' => 'ALV 2025'], 'objectType' => 'minutes', 'objectId' => 'minutes-1']],
				['warning', 'Decidiq: minutes notice to {userId} failed: {error}', ['userId' => 'bob', 'error' => 'mail down']],
				['tell', 'carol', 'decisionPublished', 'The minutes of ALV 2025 are available', 'Open the minutes in Decidiq to read them.', '/minutes/minutes-1', ['subject' => 'minutes_available', 'parameters' => ['meetingId' => 'm-1', 'meetingTitle' => 'ALV 2025'], 'objectType' => 'minutes', 'objectId' => 'minutes-1']],
				['info', 'Minutes minutes-1 sent to members: 3 notices', []],
				['sent', 3],
			],
			$titled
		);

		$untitled = $this->runDistribute(['lifecycle' => 'signed', 'relations' => ['Meeting' => ['m-1']]], null, [['nextcloudUserId' => 'alice']]);
		self::assertSame('The minutes of this meeting are available', $untitled[0][3]);
		self::assertSame(['meetingId' => 'm-1', 'meetingTitle' => ''], $untitled[0][6]['parameters']);
		self::assertSame(['sent', 1], end($untitled));

		self::assertSame([['sent', 0]], $this->runDistribute(['lifecycle' => 'published'], ['title' => 'x'], [['nextcloudUserId' => 'alice']]));
		self::assertSame([['sent', 0]], $this->runDistribute(['lifecycle' => 'approved', 'relations' => ['Meeting' => ['m-1']]], ['title' => 'x'], [['name' => 'nobody']]));
		self::assertSame([['threw', 'Minutes must be approved or signed before distribution (current: draft)', 403]], $this->runDistribute(['lifecycle' => 'draft', 'relations' => ['Meeting' => ['m-1']]], ['title' => 'x'], []));
	}//end testEveryRunIsUnchanged()

	/**
	 * Distribute one set of minutes.
	 *
	 * @param array<string, mixed>       $minutes The minutes.
	 * @param array<string, mixed>|null  $meeting The meeting, null when it cannot be read.
	 * @param list<array<string, mixed>> $members The meeting participants.
	 *
	 * @return list<array<int, mixed>> The trace.
	 */
	private function runDistribute(array $minutes, ?array $meeting, array $members): array {
		$this->trace = [];
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnSelf();
		$objects->method('find')->willReturnCallback(
			function (int|string $id) use ($minutes, $meeting): ?object {
				$row = (string)$id === 'minutes-1' ? (['id' => 'minutes-1'] + $minutes) : ($meeting === null ? null : (['id' => 'm-1'] + $meeting));
				if ($row === null) {
					return null;
				}

				$entity = $this->createMock(ObjectEntity::class);
				$entity->method('jsonSerialize')->willReturn($row);
				return $entity;
			}
		);
		$resolver = $this->createMock(ParticipantResolver::class);
		$resolver->method('resolveMeetingParticipants')->willReturn($members);
		$preferences = $this->createMock(NotificationPreferenceService::class);
		$preferences->method('dispatch')->willReturnCallback(
			function (string $personId, string $eventType, string $title, string $message, string $deepLink = '', array $inApp = []): int {
				$this->trace[] = ['tell', $personId, $eventType, $title, $message, $deepLink, $inApp];
				if ($personId === 'bob') {
					throw new RuntimeException('mail down');
				}

				return ($personId === 'carol') ? 2 : 1;
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->trace[] = [$level, $message, $context];
				}
			);
		}

		$service = new ALVMinutesService($logger, new MinutesContextResolver(objectService: $objects), $resolver, $preferences, $factory);
		try {
			$this->trace[] = ['sent', $service->distribute('minutes-1')];
		} catch (Exception $e) {
			$this->trace[] = ['threw', $e->getMessage(), $e->getCode()];
		}

		return $this->trace;
	}//end runDistribute()
}//end class
