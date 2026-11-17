<?php

/**
 * Edge-case unit tests for SeedProfileService: the generated set, a real
 * import through OpenRegister's importer, and descriptors that are missing,
 * malformed or change between the listing read and the import read.
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

namespace Unit\Service;

use OCA\Decidiq\Service\DemoDataService;
use OCA\Decidiq\Service\SeedProfileService;
use OCP\App\IAppManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The branches the shipped-descriptor tests in SeedProfileServiceTest cannot
 * reach: they run against fixture descriptors in a temporary app root.
 */
#[CoversClass(SeedProfileService::class)]
class SeedProfileServiceEdgesTest extends TestCase {
	private const IMPORTER = 'OCA\OpenRegister\Service\ConfigurationService';

	private IAppManager&MockObject $appManager;
	private ContainerInterface&MockObject $container;
	private LoggerInterface&MockObject $logger;
	private DemoDataService&MockObject $demoData;
	private SeedProfileService $service;
	private string $appRoot;

	/** PHP warnings captured while a test's own error handler is active. */
	private array $phpWarnings = [];

	protected function setUp(): void {
		$this->appRoot = sys_get_temp_dir() . '/decidiq-seed-edges-' . bin2hex(random_bytes(6));
		mkdir($this->appRoot . '/lib/Settings/profiles', 0777, true);

		$this->appManager = $this->createMock(IAppManager::class);
		$this->appManager->method('getAppPath')->willReturnCallback(fn (): string => $this->appRoot);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->demoData = $this->createMock(DemoDataService::class);

		$this->service = new SeedProfileService($this->appManager, $this->container, $this->logger, $this->demoData);
	}

	protected function tearDown(): void {
		SeedProfileFileWrapper::uninstall();
		$this->removeTree($this->appRoot);
	}

	public function testTheGeneratedSetIsKnownExactlyWhenTheDemoDataServiceSaysItShips(): void {
		$this->demoData->expects($this->exactly(2))->method('isAvailable')->willReturnOnConsecutiveCalls(true, false);
		// Answered by the demo-data service alone: no descriptor is read.
		$this->appManager->expects($this->never())->method('getAppPath');

		$this->assertTrue($this->service->isKnown('generated'));
		$this->assertFalse($this->service->isKnown('generated'));
	}

	public function testInstallingTheGeneratedSetReportsTheDemoDataImportCount(): void {
		$this->demoData->expects($this->once())->method('install')->willReturn(['objects' => '42', 'schemas' => 7]);
		$this->container->expects($this->never())->method('get');

		$this->assertSame(['objects' => 42, 'profile' => 'generated'], $this->service->install('generated'));
	}

	public function testInstallingTheGeneratedSetCountsZeroWhenTheImportReportsNoObjects(): void {
		$this->demoData->expects($this->once())->method('install')->willReturn([]);

		$this->assertSame(['objects' => 0, 'profile' => 'generated'], $this->service->install('generated'));
	}

	public function testInstallImportsThePickedDescriptorUnderItsOwnConfigurationId(): void {
		$this->writeProfile('a-other.json', ['x-openregister' => ['profile' => ['id' => 'other']]]);
		$descriptor = [
			'openapi' => '3.0.0',
			'x-openregister' => [
				'profile' => ['id' => 'tiny', 'label' => 'Tiny'],
				'seedData' => [
					'objects' => [
						'governance-body' => [['slug' => 'raad'], ['slug' => 'college']],
						'meeting' => [['slug' => 'm-1']],
						// A scalar entry still counts as one object, as (array) casts it.
						'note' => 'stray',
					],
				],
			],
		];
		$this->writeProfile('b-tiny.json', $descriptor);

		$importer = new SeedProfileRecordingImporter();
		$this->appManager->expects($this->once())->method('getInstalledApps')->willReturn(['files', 'openregister']);
		$this->appManager->expects($this->once())->method('getAppVersion')->with('decidiq')->willReturn('1.4.2');
		$this->container->expects($this->once())->method('get')->with(self::IMPORTER)->willReturn($importer);
		$this->logger->expects($this->once())->method('info')->with(
			'[SeedProfileService] imported example set "tiny": 4 object(s).',
			['app' => 'decidiq']
		);

		$result = $this->service->install('tiny');

		$this->assertSame(['objects' => 4, 'profile' => 'tiny'], $result);
		$this->assertSame(
			[
				[
					'appId' => 'decidiq.profile.tiny',
					'data' => $descriptor,
					'version' => '1.4.2',
					'force' => true,
				],
			],
			$importer->calls
		);
	}

	public function testInstallCountsNothingWhenTheSeedObjectsAreNotAList(): void {
		$this->writeProfile('tiny.json', ['x-openregister' => ['profile' => ['id' => 'tiny'], 'seedData' => ['objects' => 'none']]]);

		$importer = new SeedProfileRecordingImporter();
		$this->appManager->method('getInstalledApps')->willReturn(['openregister']);
		$this->appManager->method('getAppVersion')->willReturn('1.0.0');
		$this->container->method('get')->willReturn($importer);
		$this->logger->expects($this->once())->method('info')->with(
			'[SeedProfileService] imported example set "tiny": 0 object(s).',
			['app' => 'decidiq']
		);

		$this->assertSame(['objects' => 0, 'profile' => 'tiny'], $this->service->install('tiny'));
		$this->assertCount(1, $importer->calls);
	}

	public function testInstallRefusesWithANamedErrorWhenOpenRegisterIsNotInstalled(): void {
		$this->writeProfile('tiny.json', ['x-openregister' => ['profile' => ['id' => 'tiny']]]);
		$this->appManager->expects($this->once())->method('getInstalledApps')->willReturn(['files', 'opencatalogi']);
		$this->container->expects($this->never())->method('get');
		$this->logger->expects($this->never())->method('info');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Example data needs OpenRegister, which is not installed.');

		$this->service->install('tiny');
	}

	public function testAnAppWithoutAProfileDirectoryOffersNoSets(): void {
		$this->removeTree($this->appRoot . '/lib/Settings/profiles');
		$this->demoData->method('isAvailable')->willReturn(false);

		$this->assertSame([], $this->service->listProfiles());
		$this->assertFalse($this->service->isKnown('municipality'));
	}

	public function testBrokenDescriptorsAreSkippedWithAWarningAndTheRestStillListed(): void {
		symlink($this->appRoot . '/does-not-exist.json', $this->appRoot . '/lib/Settings/profiles/a-dangling.json');
		file_put_contents($this->appRoot . '/lib/Settings/profiles/b-malformed.json', '{"x-openregister": ');
		$this->writeProfile('c-numeric-id.json', ['x-openregister' => ['profile' => ['id' => 5]]]);
		$this->writeProfile('d-schemas-only.json', ['x-openregister' => ['seedData' => []]]);
		$this->writeProfile('e-bare.json', ['x-openregister' => ['profile' => ['id' => 'bare']]]);
		$this->demoData->method('isAvailable')->willReturn(false);

		$logged = [];
		$this->logger->expects($this->exactly(4))->method('warning')->willReturnCallback(
			static function (string $message) use (&$logged): void {
				$logged[] = $message;
			}
		);

		$profiles = $this->withCapturedWarnings(fn (): array => $this->service->listProfiles());

		// The only good file is listed, with every optional field defaulted.
		$this->assertSame(
			[['id' => 'bare', 'label' => 'bare', 'description' => '', 'objectCount' => 0, 'icon' => '']],
			$profiles
		);
		$this->assertSame(
			[
				'[SeedProfileService] unreadable example set: a-dangling.json',
				'[SeedProfileService] malformed example set: b-malformed.json',
				'[SeedProfileService] example set declares no profile block: c-numeric-id.json',
				'[SeedProfileService] example set declares no profile block: d-schemas-only.json',
			],
			$logged
		);
		$this->assertCount(1, $this->phpWarnings);
		$this->assertStringContainsString('Failed to open stream', $this->phpWarnings[0]);
	}

	public function testInstallThrowsWhenTheDescriptorCannotBeReadAtImportTime(): void {
		// The listing read succeeds and the import read fails: the file vanished
		// between the two. The operator must get an error, not a quiet "0 objects".
		$path = $this->writeProfile('tiny.json', ['x-openregister' => ['profile' => ['id' => 'tiny']]]);
		SeedProfileFileWrapper::install(path: $path, secondRead: null);
		$this->container->expects($this->never())->method('get');

		try {
			$this->withCapturedWarnings(fn (): array => $this->service->install('tiny'));
			$this->fail('A descriptor that cannot be read must throw');
		} catch (RuntimeException $e) {
			$this->assertSame('The example set could not be read: tiny.json', $e->getMessage());
		}

		$this->assertSame(2, SeedProfileFileWrapper::$reads);
		$this->assertCount(1, $this->phpWarnings);
	}

	public function testInstallThrowsWhenTheDescriptorIsNoLongerValidJsonAtImportTime(): void {
		$path = $this->writeProfile('tiny.json', ['x-openregister' => ['profile' => ['id' => 'tiny']]]);
		SeedProfileFileWrapper::install(path: $path, secondRead: '{"truncated": ');
		$this->container->expects($this->never())->method('get');

		try {
			$this->service->install('tiny');
			$this->fail('A descriptor that is not valid JSON must throw');
		} catch (RuntimeException $e) {
			$this->assertSame('The example set is not valid JSON: tiny.json', $e->getMessage());
		}

		$this->assertSame(2, SeedProfileFileWrapper::$reads);
	}

	/**
	 * Run a callable while recording PHP warnings instead of letting PHPUnit
	 * report them: an unreadable file is the scenario under test here.
	 */
	private function withCapturedWarnings(callable $run): mixed {
		set_error_handler(
			function (int $errno, string $errstr): bool {
				$this->phpWarnings[] = $errstr;
				return true;
			},
			E_WARNING
		);

		try {
			return $run();
		} finally {
			restore_error_handler();
		}
	}

	/** Write one fixture descriptor and return its path. */
	private function writeProfile(string $name, array $data): string {
		$path = $this->appRoot . '/lib/Settings/profiles/' . $name;
		file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));

		return $path;
	}

	/** Remove a fixture directory tree, dangling symlinks included. */
	private function removeTree(string $dir): void {
		if (is_dir($dir) === false || is_link($dir) === true) {
			return;
		}

		foreach (scandir($dir) as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}

			$path = $dir . '/' . $entry;
			if (is_link($path) === true || is_dir($path) === false) {
				unlink($path);
				continue;
			}

			$this->removeTree($path);
		}

		rmdir($dir);
	}
}

/**
 * Stands in for OpenRegister's ConfigurationService (absent in unit runs) and
 * records each import it is asked to perform.
 */
class SeedProfileRecordingImporter {
	/** @var list<array{appId: string, data: array, version: string, force: bool}> */
	public array $calls = [];

	public function importFromApp(string $appId, array $data, string $version, bool $force = false): array {
		$this->calls[] = ['appId' => $appId, 'data' => $data, 'version' => $version, 'force' => $force];

		return [];
	}
}

/**
 * A `file://` stream wrapper that serves ONE path from the real file on the
 * first read and something else on the second, to reproduce a descriptor that
 * changes or vanishes between the listing read and the import read. Every
 * other path is delegated to PHP's native wrapper, so autoloading keeps working.
 */
class SeedProfileFileWrapper {
	public static int $reads = 0;
	private static ?string $target = null;
	private static ?string $secondRead = null;
	private static bool $active = false;

	/** @var resource|null */
	public $context;

	/** @var resource|null */
	private $handle = null;

	public static function install(string $path, ?string $secondRead): void {
		self::$target = $path;
		self::$secondRead = $secondRead;
		self::$reads = 0;
		stream_wrapper_unregister('file');
		stream_wrapper_register('file', self::class);
		self::$active = true;
	}

	public static function uninstall(): void {
		if (self::$active === true) {
			stream_wrapper_restore('file');
			self::$active = false;
		}
	}

	private static function native(callable $operation): mixed {
		stream_wrapper_restore('file');
		try {
			return $operation();
		} finally {
			stream_wrapper_unregister('file');
			stream_wrapper_register('file', self::class);
		}
	}

	public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool {
		$plain = preg_replace('#^file://#', '', $path);
		if ($plain === self::$target) {
			self::$reads++;
			if (self::$reads >= 2) {
				if (self::$secondRead === null) {
					return false;
				}

				$this->handle = fopen('php://memory', 'r+');
				fwrite($this->handle, self::$secondRead);
				rewind($this->handle);

				return true;
			}
		}

		$this->handle = self::native(static fn () => fopen($path, $mode, ($options & STREAM_USE_PATH) !== 0));

		return $this->handle !== false;
	}

	public function stream_read(int $count): string|false {
		return fread($this->handle, $count);
	}

	public function stream_write(string $data): int {
		return (int)fwrite($this->handle, $data);
	}

	public function stream_eof(): bool {
		return feof($this->handle);
	}

	public function stream_tell(): int {
		return (int)ftell($this->handle);
	}

	public function stream_seek(int $offset, int $whence): bool {
		return fseek($this->handle, $offset, $whence) === 0;
	}

	public function stream_stat(): array|false {
		return fstat($this->handle);
	}

	public function stream_close(): void {
		fclose($this->handle);
	}

	public function stream_flush(): bool {
		return fflush($this->handle);
	}

	public function stream_lock(int $operation): bool {
		return flock($this->handle, $operation);
	}

	public function stream_set_option(int $option, int $arg1, ?int $arg2): bool {
		return false;
	}

	public function url_stat(string $path, int $flags): array|false {
		return self::native(
			static function () use ($path, $flags) {
				$stat = 'stat';
				if (($flags & STREAM_URL_STAT_LINK) !== 0) {
					$stat = 'lstat';
				}

				if (($flags & STREAM_URL_STAT_QUIET) !== 0) {
					return @$stat($path);
				}

				return $stat($path);
			}
		);
	}
}
