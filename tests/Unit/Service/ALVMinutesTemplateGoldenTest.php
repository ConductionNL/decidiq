<?php

/**
 * Golden runs of the ALV minutes draft.
 *
 * Pins the whole rendered ALV template for a meeting whose quorum is reached
 * (with agenda items, one without a number or title), one whose quorum is not
 * reached, and one with no title, date or location (today's date is masked),
 * and the recipient count. Pinned before the template rendering moved onto a
 * draft run.
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
 * @spec exclude golden test of the ALV draft, no behaviour of its own
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\ALVMinutesService;
use OCA\Decidiq\Service\MinutesContextResolver;
use OCA\Decidiq\Service\NotificationPreferenceService;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ALVMinutesTemplateGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/alv-minutes-template-golden.json';

	/**
	 * The three drafts match the golden.
	 *
	 * @return void
	 */
	public function testTheDraftsAreUnchanged(): void {
		$runs = [
			'quorum reached' => $this->draft(
				['title' => 'ALV 2025', 'relations' => ['Meeting' => ['m-1']]],
				['meetingType' => 'alv', 'scheduledDate' => '2025-04-15', 'location' => 'Amsterdam', 'quorumRequired' => 2],
				[[], ['leftAt' => '2025-01-01'], [], []],
				[['orderNumber' => 1, 'title' => 'Opening'], ['title' => 'Begroting'], ['orderNumber' => 3]]
			),
			'quorum not reached' => $this->draft(
				['title' => 'ALV voorjaar', 'relations' => ['Meeting' => ['m-1']]],
				['meetingType' => 'alv', 'scheduledDate' => '2025-05-01', 'location' => 'Utrecht', 'quorumRequired' => 5],
				[[], []],
				[]
			),
			'defaults' => $this->draft(
				['relations' => ['Meeting' => ['m-1']]],
				['meetingType' => 'alv'],
				[],
				[['orderNumber' => 1, 'title' => 'Opening']]
			),
		];
		$runs['defaults']['content'] = str_replace(date('d-m-Y'), '<today>', $runs['defaults']['content']);

		$json = json_encode($runs, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n";
		if (getenv('DECIDIQ_WRITE_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $json);
		}

		self::assertSame(file_get_contents(self::GOLDEN), $json);
	}//end testTheDraftsAreUnchanged()

	/**
	 * Generate one draft over the given objects.
	 *
	 * @param array<string, mixed>             $minutes      The minutes.
	 * @param array<string, mixed>             $meeting      The meeting.
	 * @param list<array<string, mixed>>       $participants The meeting participants.
	 * @param list<array<string, mixed>>       $agenda       The agenda items.
	 *
	 * @return array{content: string, recipientCount: int} The draft.
	 */
	private function draft(array $minutes, array $meeting, array $participants, array $agenda): array {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnSelf();
		$objects->method('find')->willReturnCallback(
			fn (int|string $id): ?object => $this->entity((string)$id === 'minutes-1' ? (['id' => 'minutes-1'] + $minutes) : (['id' => 'm-1'] + $meeting))
		);
		$objects->method('findAll')->willReturnCallback(
			fn (array $config): array => array_map(fn (array $row): object => $this->entity($row), $agenda)
		);
		$resolver = $this->createMock(ParticipantResolver::class);
		$resolver->method('resolveMeetingParticipants')->willReturn($participants);

		$service = new ALVMinutesService(
			$this->createMock(LoggerInterface::class),
			new MinutesContextResolver(objectService: $objects),
			$resolver,
			$this->createMock(NotificationPreferenceService::class),
			$this->createMock(IFactory::class),
		);
		return $service->generateALVDraft('minutes-1');
	}//end draft()

	/**
	 * An entity serialising to the row.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return object The entity.
	 */
	private function entity(array $row): object {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($row);
		return $entity;
	}//end entity()
}//end class
