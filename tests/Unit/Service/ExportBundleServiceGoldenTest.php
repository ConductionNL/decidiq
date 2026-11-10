<?php

/**
 * Golden tests for ExportBundleService::export.
 *
 * Pins the order of the refusals (format, sign-in, filinq, nothing to export,
 * too many), the read each request makes (selection, filter, search), the
 * list name a file gets, the order a selection keeps, and which writer call
 * receives what. The writer is a recording double, so nothing is written.
 *
 * @category  Test
 * @package   OCA\Decidiq\Tests\Unit\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/decidiq
 *
 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-001-motions-export-as-one-pdf-with-their-attachments
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use DateTime;
use OCA\Decidiq\Exception\ExportBundleException;
use OCA\Decidiq\Service\ExportBundleService;
use OCA\Decidiq\Service\ExportRequest;
use OCA\Decidiq\Service\ExportBundleWriter;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Pins every refusal, read and writer call of one export.
 */
final class ExportBundleServiceGoldenTest extends TestCase {
	/**
	 * Every read and writer call, in order.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * The service over recording doubles.
	 *
	 * @param list<mixed> $rows   What the read returns.
	 * @param string|null $uid    The signed-in user, null for nobody.
	 * @param bool        $filinq Whether filinq's merge service is installed.
	 *
	 * @return ExportBundleService The service.
	 */
	private function service(array $rows, ?string $uid = 'griffier', bool $filinq = true): ExportBundleService {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config) use ($rows): array {
				$this->calls[] = ['read', $config];
				return $rows;
			}
		);

		$merger = new \stdClass();
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($merger, $filinq): object {
				if ($filinq === true && str_ends_with($id, 'Service\DocumentMergeService') === true) {
					return $merger;
				}

				throw new RuntimeException('no ' . $id);
			}
		);

		$folder = $this->createMock(Folder::class);
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('nodeExists')->willReturn(false);
		$userFolder->method('newFolder')->willReturn($folder);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturnCallback(
			function (string $who) use ($userFolder): Folder {
				$this->calls[] = ['folder', $who];
				return $userFolder;
			}
		);

		$writer = $this->createMock(ExportBundleWriter::class);
		$writer->method('zip')->willReturnCallback(
			function (Folder $target, string $name, array $decisions) use ($folder): array {
				$this->calls[] = ['zip', $target === $folder, $name, self::idsOf($decisions)];
				return ['status' => 'done'];
			}
		);
		$writer->method('mergedPdf')->willReturnCallback(
			function (object $usedMerger, Folder $target, string $name, array $decisions, string $who) use ($merger, $folder): array {
				$this->calls[] = ['pdf', $usedMerger === $merger, $target === $folder, $name, self::idsOf($decisions), $who];
				return ['status' => 'queued'];
			}
		);

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-10-20 10:00:00'));

		return new ExportBundleService(
			objectService: $objects,
			container: $container,
			writer: $writer,
			rootFolder: $root,
			userSession: $session,
			time: $time,
		);
	}//end service()

	/**
	 * The ids of the decisions a writer received, whichever shape carries them.
	 *
	 * @param list<array<string, mixed>> $decisions The decisions.
	 *
	 * @return list<string> The ids.
	 */
	private static function idsOf(array $decisions): array {
		return array_map(
			static fn (array $d): string => (string)($d['id'] ?? $d['@self']['id'] ?? $d['uuid'] ?? ''),
			$decisions
		);
	}//end idsOf()

	/**
	 * The status and message an export refuses with.
	 *
	 * @param ExportBundleService $service The service.
	 * @param string              $format  The format.
	 *
	 * @return array{0: int, 1: string} Status and message, or [0, 'none'].
	 */
	private function refusal(ExportBundleService $service, string $format = 'zip'): array {
		try {
			$service->export(request: new ExportRequest(format: $format, list: 'Motions', ids: [], filter: [], search: ''));
		} catch (ExportBundleException $e) {
			return [$e->getStatus(), $e->getMessage()];
		}

		return [0, 'none'];
	}//end refusal()

	/**
	 * Refusals come in order, and none of the first three reads anything.
	 *
	 * @return void
	 */
	public function testRefusalsInOrder(): void {
		$this->assertSame([400, 'Choose one PDF or a ZIP.'], $this->refusal($this->service([], null, false), 'docx'));
		$this->assertSame([401, 'Sign in to export.'], $this->refusal($this->service([], null, false), 'pdf'));
		$this->assertSame([401, 'Sign in to export.'], $this->refusal($this->service([], ''), 'zip'));
		$this->assertSame(
			[503, 'One PDF needs the filinq app, which is not installed. Export a ZIP instead.'],
			$this->refusal($this->service([], 'griffier', false), 'pdf')
		);
		$this->assertSame([], $this->calls);
		$this->assertSame([422, 'Nothing to export: select rows or widen the filter.'], $this->refusal($this->service(['not a row', 7]), 'zip'));
		$this->assertCount(1, $this->calls);

		$many = array_map(static fn (int $i): array => ['id' => 'd-' . $i], range(1, 501));
		$this->assertSame(
			[422, 'This export would hold more than 500 decisions. Narrow the filter and try again.'],
			$this->refusal($this->service(['results' => $many]), 'zip')
		);
		$this->assertCount(2, $this->calls);
	}//end testRefusalsInOrder()

	/**
	 * A search reaches the read trimmed; an unknown list is named Decisions; a ZIP needs no filinq.
	 *
	 * @return void
	 */
	public function testAFilteredSearchBecomesADecisionsZip(): void {
		$result = $this->service([['id' => 'b'], ['id' => 'a']], 'griffier', false)->export(request: new ExportRequest(
			format: 'zip',
			list: 'Everything',
			ids: ['', ''],
			filter: ['decisionType' => 'motion', '' => 'x', '_page' => 2, 'register' => 'evil', 'schema' => 'evil', 'action' => 'go', 3 => 'three'],
			search: '  budget  ',
		));

		$this->assertSame(['status' => 'done'], $result);
		$this->assertSame(
			[
				['read', ['filters' => ['register' => 'decidiq', 'schema' => 'decision', 'decisionType' => 'motion', 3 => 'three'], 'limit' => 501, 'search' => 'budget']],
				['folder', 'griffier'],
				['zip', true, 'Decisions 2026-10-20.zip', ['b', 'a']],
			],
			$this->calls
		);
	}//end testAFilteredSearchBecomesADecisionsZip()

	/**
	 * A selection ignores the filter and search and keeps the clerk's order; one PDF goes to the merger.
	 *
	 * @return void
	 */
	public function testASelectionBecomesOnePdfInItsOwnOrder(): void {
		$rows = [['id' => 'x'], ['@self' => ['id' => 'c']], ['uuid' => 'a'], ['id' => 'b']];
		$result = $this->service($rows)->export(request: new ExportRequest(format: 'pdf', list: 'Motions', ids: ['a', 7, '', 'b', 'c'], filter: ['decisionType' => 'motion'], search: 'ignored'));

		$this->assertSame(['status' => 'queued'], $result);
		$this->assertSame(
			['read', ['filters' => ['register' => 'decidiq', 'schema' => 'decision'], 'ids' => ['a', '7', 'b', 'c'], 'limit' => 501]],
			$this->calls[0]
		);
		$this->assertSame(['pdf', true, true, 'Motions 2026-10-20.pdf', ['a', 'b', 'c', 'x'], 'griffier'], $this->calls[2]);
	}//end testASelectionBecomesOnePdfInItsOwnOrder()
}//end class
