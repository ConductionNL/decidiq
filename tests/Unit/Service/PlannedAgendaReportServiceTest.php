<?php

/**
 * Tests for the long-term agenda report.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use DateTime;
use OCA\Decidiq\Exception\PlannedAgendaReportException;
use OCA\Decidiq\Service\PlannedAgendaReportRenderer;
use OCA\Decidiq\Service\PlannedAgendaReportService;
use OCA\Decidiq\Support\FilinqPdf;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Decidiq\Service\PlannedAgendaReportService
 * @covers \OCA\Decidiq\Exception\PlannedAgendaReportException
 * @uses   \OCA\Decidiq\Service\PlannedAgendaReportRenderer
 *
 * @spec openspec/changes/planning-long-term-agenda-tracks-and-report/specs/termijnagenda-register/spec.md#requirement-req-ltat-004-the-filtered-list-downloads-as-a-formatted-report
 */
class PlannedAgendaReportServiceTest extends TestCase {

	/**
	 * Files written, by path.
	 *
	 * @var array<string,string>
	 */
	private array $files = [];

	/**
	 * The config every findAll received.
	 *
	 * @var list<array<string,mixed>>
	 */
	private array $reads = [];

	/**
	 * What the PDF renderer received.
	 *
	 * @var list<string>
	 */
	private array $rendered = [];

	/**
	 * The objects the caller may read, by schema. Anything else OpenRegister's
	 * read rules hide, so findAll never returns it.
	 *
	 * @return array<string,list<array<string,mixed>>>
	 */
	private static function readable(): array {
		return [
			'planned-agenda-item' => [
				['id' => 'topic', 'subject' => 'Energietransitie', 'plannedPeriod' => '2026-Q3', 'owner' => 'de-boer', 'lifecycle' => 'planned'],
				['id' => 'warmte', 'subject' => 'Warmtevisie 2.0', 'parentItem' => 'topic', 'plannedPeriod' => '2026-Q4', 'governanceBody' => 'raad', 'owner' => 'de-boer', 'author' => 'visser', 'lifecycle' => 'planned'],
				['id' => 'res', 'subject' => 'Regionale energiestrategie 2.0', 'parentItem' => 'topic', 'plannedPeriod' => '2027-Q1', 'owner' => 'de-boer', 'lifecycle' => 'postponed'],
				['id' => 'orphan', 'subject' => 'Track of a hidden topic', 'parentItem' => 'secret-topic', 'plannedPeriod' => '2026-Q1', 'owner' => 'yilmaz', 'lifecycle' => 'planned'],
			],
			'person' => [
				['id' => 'de-boer', 'name' => 'De Boer'],
				['id' => 'visser', 'name' => 'S. Visser'],
			],
			'governance-body' => [
				['id' => 'raad', 'name' => 'Gemeenteraad'],
			],
		];
	}//end readable()

	/**
	 * A folder double over $this->files at $path.
	 *
	 * @param string $path The folder path.
	 *
	 * @return Folder
	 */
	private function folder(string $path): Folder {
		$folder = $this->createMock(Folder::class);
		$prefix = ($path === '' ? '' : $path . '/');
		$folder->method('nodeExists')->willReturnCallback(fn (string $name): bool => isset($this->files[$prefix . $name]) === true);
		$folder->method('newFolder')->willReturnCallback(
			function (string $name) use ($prefix): Folder {
				$this->files[$prefix . $name] = '<dir>';
				return $this->folder($prefix . $name);
			}
		);
		$folder->method('get')->willReturnCallback(
			function (string $name) use ($prefix): Folder|File {
				if ($this->files[$prefix . $name] === '<dir>') {
					return $this->folder($prefix . $name);
				}

				$file = $this->createMock(File::class);
				$file->method('getId')->willReturn(7);
				$file->method('putContent')->willReturnCallback(function (string $content) use ($prefix, $name): void {
					$this->files[$prefix . $name] = $content;
				});
				return $file;
			}
		);
		$folder->method('newFile')->willReturnCallback(
			function (string $name, mixed $content=null) use ($prefix): File {
				$this->files[$prefix . $name] = (string)$content;
				$file = $this->createMock(File::class);
				$file->method('getId')->willReturn(42);
				return $file;
			}
		);
		return $folder;
	}//end folder()

	/**
	 * The service over the readable objects.
	 *
	 * @param bool        $filinq Whether filinq renders a PDF.
	 * @param string|null $uid    The signed-in user, or null.
	 *
	 * @return PlannedAgendaReportService
	 */
	private function service(bool $filinq=true, ?string $uid='griffier'): PlannedAgendaReportService {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config): array {
				$this->reads[] = $config;
				$filters = $config['filters'];
				$rows    = (self::readable()[$filters['schema']] ?? []);
				if (isset($config['ids']) === true) {
					$rows = array_values(array_filter($rows, fn (array $r): bool => in_array($r['id'], $config['ids'], true)));
				}

				foreach ($filters as $key => $value) {
					if (in_array($key, ['register', 'schema'], true) === true) {
						continue;
					}

					$rows = array_values(array_filter($rows, fn (array $r): bool => ($value === 'IS NULL' ? isset($r[$key]) === false : in_array(($r[$key] ?? null), (array)$value, true))));
				}

				return ['results' => array_slice($rows, 0, (int)$config['limit'])];
			}
		);

		$pdf = $this->createMock(FilinqPdf::class);
		$pdf->method('fromHtml')->willReturnCallback(
			function (string $html) use ($filinq): ?string {
				$this->rendered[] = $html;
				return ($filinq === true ? '%PDF report' : null);
			}
		);

		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturnCallback(fn (string $u): Folder => $this->folder(''));

		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-11-14 10:00:00'));

		return new PlannedAgendaReportService($objects, new PlannedAgendaReportRenderer(), $pdf, $root, $session, $time);
	}//end service()

	/**
	 * The filters select the rows, the PDF lands in Decidiq/Reports, and the heading names the filters.
	 *
	 * @return void
	 */
	public function testRendersTheFilteredRowsAsAPdfInTheReportsFolder(): void {
		$result = $this->service()->render(filter: ['owner' => 'de-boer', 'lifecycle' => 'planned', '_search' => 'x', 'page' => 2]);

		self::assertSame('pdf', $result['format']);
		self::assertSame('/Decidiq/Reports/Long-term agenda 2026-11-14.pdf', $result['path']);
		self::assertSame(42, $result['fileId']);
		self::assertSame(2, $result['count']);
		self::assertNull($result['note']);
		self::assertSame('%PDF report', $this->files['Decidiq/Reports/Long-term agenda 2026-11-14.pdf']);

		self::assertSame(
			['register' => 'decidiq', 'schema' => 'planned-agenda-item', 'owner' => 'de-boer', 'lifecycle' => 'planned'],
			$this->reads[0]['filters']
		);
		self::assertSame(501, $this->reads[0]['limit']);

		$html = $this->rendered[0];
		self::assertStringContainsString('Portfolio holder: De Boer, status: planned', $html);
		self::assertStringContainsString('14 November 2026', $html);
		self::assertStringContainsString('<h2>Energietransitie</h2>', $html);
		self::assertStringContainsString('<td>Warmtevisie 2.0</td><td>Gemeenteraad</td><td>2026-Q4</td><td>De Boer</td><td>S. Visser</td><td>planned</td>', $html);
		self::assertStringNotContainsString('Regionale energiestrategie', $html);
	}//end testRendersTheFilteredRowsAsAPdfInTheReportsFolder()

	/**
	 * A row, topic or person OpenRegister hides from the caller never reaches the report.
	 *
	 * @return void
	 */
	public function testTheReportNeverShowsWhatTheListWouldHide(): void {
		$this->service()->render(filter: []);

		$html = $this->rendered[0];
		self::assertStringContainsString('<td>Track of a hidden topic</td>', $html);
		self::assertStringNotContainsString('secret-topic', $html);
		self::assertStringNotContainsString('yilmaz', $html);
		self::assertStringContainsString('<h2>Track of a hidden topic</h2>', $html);
		self::assertStringContainsString('No filters: every item you may read.', $html);

		// The hidden topic was asked for by id, as the caller, and not returned.
		$topicRead = array_values(array_filter($this->reads, fn (array $c): bool => ($c['ids'] ?? null) === ['secret-topic']));
		self::assertCount(1, $topicRead);
		self::assertSame('planned-agenda-item', $topicRead[0]['filters']['schema']);
	}//end testTheReportNeverShowsWhatTheListWouldHide()

	/**
	 * Tracks group under their topic, ordered by period, and groups by their first period.
	 *
	 * @return void
	 */
	public function testGroupsTracksUnderTheirTopicOrderedByPeriod(): void {
		$this->service()->render(filter: []);

		$html = $this->rendered[0];
		$orphan = strpos($html, '<h2>Track of a hidden topic</h2>');
		$topic  = strpos($html, '<h2>Energietransitie</h2>');
		$warmte = strpos($html, '<td>Warmtevisie 2.0</td>');
		$res    = strpos($html, '<td>Regionale energiestrategie 2.0</td>');
		self::assertNotFalse($orphan);
		self::assertTrue($orphan < $topic && $topic < $warmte && $warmte < $res);
		self::assertSame(2, substr_count($html, '<h2>'));
	}//end testGroupsTracksUnderTheirTopicOrderedByPeriod()

	/**
	 * Without filinq the HTML is saved, with the note the page shows.
	 *
	 * @return void
	 */
	public function testWithoutFilinqTheHtmlIsSavedWithANote(): void {
		$result = $this->service(filinq: false)->render(filter: ['parentItem' => 'IS NULL']);

		self::assertSame('html', $result['format']);
		self::assertSame('/Decidiq/Reports/Long-term agenda 2026-11-14.html', $result['path']);
		self::assertSame('filinq is not installed, so the report was saved as HTML', $result['note']);
		$saved = $this->files['Decidiq/Reports/Long-term agenda 2026-11-14.html'];
		self::assertSame($this->rendered[0], $saved);
		self::assertStringContainsString('Topics only', $saved);
		self::assertSame(1, $result['count']);
	}//end testWithoutFilinqTheHtmlIsSavedWithANote()

	/**
	 * A report made again the same day replaces the earlier file.
	 *
	 * @return void
	 */
	public function testASecondReportTheSameDayReplacesTheFirst(): void {
		$this->files = ['Decidiq' => '<dir>', 'Decidiq/Reports' => '<dir>', 'Decidiq/Reports/Long-term agenda 2026-11-14.pdf' => 'old'];

		$result = $this->service()->render(filter: []);

		self::assertSame(7, $result['fileId']);
		self::assertSame('%PDF report', $this->files['Decidiq/Reports/Long-term agenda 2026-11-14.pdf']);
	}//end testASecondReportTheSameDayReplacesTheFirst()

	/**
	 * A filter that selects nothing is refused with 422 and nothing is written.
	 *
	 * @return void
	 */
	public function testAFilterThatSelectsNothingIsRefused(): void {
		try {
			$this->service()->render(filter: ['owner' => 'nobody']);
			self::fail('Expected a refusal');
		} catch (PlannedAgendaReportException $e) {
			self::assertSame('No items match these filters', $e->getMessage());
			self::assertSame(422, $e->getStatus());
		}

		self::assertSame([], $this->files);
		self::assertSame([], $this->rendered);
	}//end testAFilterThatSelectsNothingIsRefused()

	/**
	 * Nobody signed in: 401 before anything is read.
	 *
	 * @return void
	 */
	public function testSignedOutIsRefused(): void {
		try {
			$this->service(uid: null)->render(filter: []);
			self::fail('Expected a refusal');
		} catch (PlannedAgendaReportException $e) {
			self::assertSame(401, $e->getStatus());
		}

		self::assertSame([], $this->reads);
	}//end testSignedOutIsRefused()

	/**
	 * A file where the reports folder should be is refused with 409.
	 *
	 * @return void
	 */
	public function testAFileInTheWayOfTheFolderIsRefused(): void {
		$this->files = ['Decidiq' => 'a file'];

		$this->expectException(PlannedAgendaReportException::class);
		$this->expectExceptionCode(409);
		$this->service()->render(filter: []);
	}//end testAFileInTheWayOfTheFolderIsRefused()

	/**
	 * The page's own keys and empty values are not filters.
	 *
	 * @return void
	 */
	public function testListFiltersDropsThePagesOwnKeysAndEmptyValues(): void {
		self::assertSame(
			['owner' => 'x', 'lifecycle' => ['planned', 'postponed']],
			PlannedAgendaReportService::listFilters(
				filter: ['owner' => 'x', 'register' => 'other', 'schema' => 'other', '_order' => 'a', 'author' => '', 'governanceBody' => null, 'lifecycle' => ['planned', 'postponed'], 'limit' => 20]
			)
		);
	}//end testListFiltersDropsThePagesOwnKeysAndEmptyValues()
}//end class
