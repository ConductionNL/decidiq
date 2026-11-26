<?php

/**
 * After an example set is imported, its meetings are pointed at their archival
 * dossiers (MeetingDossierBackfill), and a failure there never fails the import.
 *
 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-007-archive-completeness-and-compliance-dashboard
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\DemoDataService;
use OCA\Decidiq\Service\Records\MeetingDossierBackfill;
use OCA\Decidiq\Service\SeedProfileService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the meeting link after an example-set import.
 */
class SeedProfileServiceLinksMeetingsTest extends TestCase {

	/**
	 * OpenRegister's configuration service, as the container names it.
	 */
	private const IMPORTER = 'OCA\OpenRegister\Service\ConfigurationService';

	/**
	 * A temporary app root holding one example set.
	 *
	 * @var string
	 */
	private string $appRoot = '';

	/**
	 * The imports performed.
	 *
	 * @var list<string>
	 */
	public array $imports = [];

	/**
	 * Write a one-meeting example set under a temporary app root.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->appRoot = sys_get_temp_dir() . '/decidiq-seed-links-' . bin2hex(random_bytes(6));
		mkdir(directory: $this->appRoot . '/lib/Settings/profiles', recursive: true);
		$set = ['x-openregister' => ['profile' => ['id' => 'tiny'], 'seedData' => ['objects' => ['meeting' => [['slug' => 'm-1']]]]]];
		file_put_contents(filename: $this->appRoot . '/lib/Settings/profiles/tiny.json', data: json_encode(value: $set));
	}//end setUp()

	/**
	 * Remove the temporary app root.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unlink(filename: $this->appRoot . '/lib/Settings/profiles/tiny.json');
		rmdir(directory: $this->appRoot . '/lib/Settings/profiles');
		rmdir(directory: $this->appRoot . '/lib/Settings');
		rmdir(directory: $this->appRoot . '/lib');
		rmdir(directory: $this->appRoot);
	}//end tearDown()

	/**
	 * The service, with a container that answers the importer and the backfill.
	 *
	 * @param MeetingDossierBackfill $backfill The backfill double.
	 * @param LoggerInterface        $logger   The logger.
	 *
	 * @return SeedProfileService
	 */
	private function service(MeetingDossierBackfill $backfill, LoggerInterface $logger): SeedProfileService {
		$apps = $this->createMock(originalClassName: IAppManager::class);
		$apps->method('getAppPath')->willReturn($this->appRoot);
		$apps->method('getInstalledApps')->willReturn(['openregister']);
		$apps->method('getAppVersion')->willReturn('1.0.0');

		$importer = new class($this) {
			/**
			 * Constructor.
			 *
			 * @param SeedProfileServiceLinksMeetingsTest $test Records the imports.
			 */
			public function __construct(private SeedProfileServiceLinksMeetingsTest $test) {
			}

			/**
			 * Record the import.
			 *
			 * @param string               $appId   The configuration id.
			 * @param array<string, mixed> $data    The descriptor.
			 * @param string               $version The app version.
			 * @param bool                 $force   Whether to force.
			 *
			 * @return array<string, mixed>
			 */
			public function importFromApp(string $appId, array $data, string $version, bool $force=false): array {
				$this->test->imports[] = $appId;
				return [];
			}
		};

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnMap([[self::IMPORTER, $importer], [MeetingDossierBackfill::class, $backfill]]);

		return new SeedProfileService(
			appManager: $apps,
			container: $container,
			logger: $logger,
			demoData: $this->createMock(originalClassName: DemoDataService::class)
		);
	}//end service()

	/**
	 * The meetings are linked once, after the import.
	 *
	 * @return void
	 */
	public function testTheImportedMeetingsArePointedAtTheirDossiers(): void {
		$backfill = $this->createMock(originalClassName: MeetingDossierBackfill::class);
		$backfill->expects($this->once())->method('fill')->willReturnCallback(
			function (): int {
				$this->assertSame(expected: ['decidiq.profile.tiny'], actual: $this->imports);
				return 1;
			}
		);

		$result = $this->service(backfill: $backfill, logger: $this->createMock(originalClassName: LoggerInterface::class))->install(profileId: 'tiny');

		$this->assertSame(expected: ['objects' => 1, 'profile' => 'tiny'], actual: $result);
	}//end testTheImportedMeetingsArePointedAtTheirDossiers()

	/**
	 * A failed link is logged and never fails the import.
	 *
	 * @return void
	 */
	public function testAFailedLinkDoesNotFailTheImport(): void {
		$backfill = $this->createMock(originalClassName: MeetingDossierBackfill::class);
		$backfill->method('fill')->willThrowException(new RuntimeException(message: 'locked'));
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains(string: 'not pointed at their dossier: locked'));

		$result = $this->service(backfill: $backfill, logger: $logger)->install(profileId: 'tiny');

		$this->assertSame(expected: ['objects' => 1, 'profile' => 'tiny'], actual: $result);
	}//end testAFailedLinkDoesNotFailTheImport()
}//end class
