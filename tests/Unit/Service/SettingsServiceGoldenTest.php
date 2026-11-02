<?php

/**
 * Golden test for SettingsService.
 *
 * Runs getSettings, updateSettings, loadConfiguration, reloadConfiguration,
 * mergedRegisterConfig and shippedRegisterDescriptor over app config values,
 * users, OpenRegister importer answers and role mappings, and merges fragment
 * directories built from fixtures in a temporary directory (missing, empty,
 * out of order, malformed, top-level lists, null and scalar fragments, every
 * list/map/scalar combination of the deep merge). It records every app config
 * call, log line and importer call and what each call returns. The expected
 * values live in tests/fixtures/settings-service-golden.json; run with
 * RECORD_SETTINGS_SERVICE_GOLDEN=1 to write them, then run again without it.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p1-crud-operations/tasks.md#task-2.3
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\RoleGroupMapping;
use OCA\Decidiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

/**
 * Golden test for the settings service.
 *
 * @covers \OCA\Decidiq\Service\SettingsService
 * @uses   \OCA\Decidiq\Service\RoleGroupMapping
 * @uses   \OCA\Decidiq\Support\FleetAppId
 */
class SettingsServiceGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/settings-service-golden.json';

	/**
	 * Every recorded call of the current case.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $log = [];

	/**
	 * The temporary directory the fragment fixtures are written to.
	 *
	 * @var string
	 */
	private string $tmp = '';

	/**
	 * Remove the fragment fixtures.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ($this->tmp !== '') {
			$this->removeTree(path: $this->tmp);
		}

		parent::tearDown();
	}//end tearDown()

	/**
	 * Every case answers as recorded.
	 *
	 * @return void
	 */
	public function testEverySettingsCallMatchesTheRecording(): void {
		$actual = [];
		foreach ($this->settingsCases() as $name => $case) {
			$actual['settings:' . $name] = $this->runCase(case: $case);
		}

		foreach ($this->fragmentCases() as $name => $files) {
			$actual['fragments:' . $name] = $this->mergeFixture(name: $name, files: $files);
		}

		$actual['fragments:missing-directory'] = $this->merge(dir: '/nonexistent/decidiq-golden', report: true);
		$actual['shipped'] = $this->describe(data: SettingsService::shippedRegisterDescriptor());

		$golden = [];
		if (is_file(self::GOLDEN) === true) {
			$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		}

		if (getenv('RECORD_SETTINGS_SERVICE_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
			$golden = $actual;
		}

		self::assertSame(array_keys($golden), array_keys($actual));
		foreach ($actual as $name => $value) {
			self::assertSame(json_encode($golden[$name]), json_encode($value), $name);
		}
	}//end testEverySettingsCallMatchesTheRecording()

	/**
	 * The service-level cases: app config values, user, importer answer and the call.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function settingsCases(): array {
		$stored = [
			'register'                => 'decidiq',
			'ori_bearer_secret'       => 'secret',
			'minutesSchema'           => 'my-minutes',
			'organisatie_modus'       => 'corp',
			'convert_office_papers'   => 'false',
			'organisation_name'       => 'Zuiddrecht',
			'simple_structure'        => 'full',
		];
		$roles    = json_encode(['secretariat' => ['g1', 'g2'], 'unknown' => ['x']]);

		return [
			'get-defaults-no-user'        => ['call' => 'get'],
			'get-stored-admin'            => ['call' => 'get', 'config' => $stored, 'user' => 'admin', 'apps' => ['openregister', 'filinq']],
			'get-stored-member'           => ['call' => 'get', 'config' => $stored, 'user' => 'anna', 'apps' => ['docudesk']],
			'update-mixed'                => ['call' => 'update', 'data' => ['register' => 'r2', 'ori_bearer_secret' => 's2', 'unknown' => 'x', 'motion_min_cosigners' => 3, 'email_voting_enabled' => true, 'organisation_logo' => null, 'organisation_locale' => '']],
			'update-empty'                => ['call' => 'update', 'data' => []],
			'load-no-openregister'        => ['call' => 'load'],
			'load-imported'               => ['call' => 'load', 'apps' => ['openregister'], 'import' => ['version' => '9.9.9', 'other' => 1]],
			'reload-imported-roles'       => ['call' => 'reload', 'apps' => ['openregister'], 'import' => ['version' => '9.9.9'], 'config' => ['role_group_mapping' => $roles]],
			'load-no-version'             => ['call' => 'load', 'apps' => ['openregister'], 'import' => ['other' => 1]],
			'load-empty-result'           => ['call' => 'load', 'apps' => ['openregister'], 'import' => []],
			'load-importer-throws'        => ['call' => 'load', 'apps' => ['openregister'], 'import' => 'throw'],
			'load-container-throws'       => ['call' => 'reload', 'apps' => ['openregister'], 'container' => 'throw'],
			'load-bad-role-mapping'       => ['call' => 'load', 'apps' => ['openregister'], 'import' => ['version' => '1'], 'config' => ['role_group_mapping' => '{not json']],
			'merged'                      => ['call' => 'merged'],
			'merged-roles'                => ['call' => 'merged', 'config' => ['role_group_mapping' => $roles]],
			'role-group-mapping'          => ['call' => 'roles', 'config' => ['role_group_mapping' => $roles]],
			'openregister-available'      => ['call' => 'available', 'apps' => ['openregister']],
			'openregister-not-available'  => ['call' => 'available'],
		];
	}//end settingsCases()

	/**
	 * The fragment directories: file name => contents.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function fragmentCases(): array {
		$deep = [
			'listList'      => ['b1', 'b2'],
			'mapMap'        => ['k1' => 'b', 'k2' => ['x' => 1, 'y' => [1, 2]]],
			'mapList'       => ['a' => 1, 'b' => 2],
			'listMap'       => ['l0', 'l1'],
			'emptyBase'     => [],
			'mapEmpty'      => ['keep' => 'me'],
			'scalarBase'    => 'scalar',
			'nullBase'      => null,
			'arrayToScalar' => ['gone' => true],
			'sparse'        => [1 => 'one', 2 => 'two'],
			'listSparse'    => ['z0', 'z1'],
			'nested'        => ['a' => ['b' => ['c' => [1], 'd' => 'base']]],
		];
		$overlay = [
			'listList'      => ['o1'],
			'mapMap'        => ['k2' => ['y' => [3], 'z' => 'new'], 'k3' => 'add'],
			'mapList'       => ['o0', 'o1'],
			'listMap'       => ['k' => 'v', 1 => 'replaced'],
			'emptyBase'     => ['m' => 1],
			'mapEmpty'      => [],
			'scalarBase'    => ['now' => 'array'],
			'nullBase'      => ['now' => 'array'],
			'arrayToScalar' => 7,
			'sparse'        => [1 => 'uno', 3 => 'tres'],
			'listSparse'    => [5 => 'five'],
			'nested'        => ['a' => ['b' => ['c' => [2], 'e' => 'new']]],
			'missing'       => ['fresh' => ['deep']],
			'missingScalar' => false,
		];

		return [
			'empty-directory'  => [],
			'one-fragment'     => ['10-a.json' => (string)json_encode(['components' => ['schemas' => ['Thing' => ['type' => 'object']]]])],
			'deep-merge'       => ['01-base.json' => (string)json_encode($deep), '02-overlay.json' => (string)json_encode($overlay)],
			'sorted-order'     => [
				'30-z.json' => '{"order":"z","list":["z"]}',
				'10-a.json' => '{"order":"a","list":["a"]}',
				'20-m.json' => '{"order":"m","list":["m"]}',
			],
			'malformed-middle' => [
				'10-a.json'   => '{"a":1}',
				'20-bad.json' => '{"a":',
				'30-c.json'   => '{"c":3}',
			],
			'not-json-suffix'  => ['10-a.json' => '{"a":1}', '20-b.txt' => '{"b":2}', '30-c.JSON' => '{"c":3}'],
			'top-level-list'   => ['10-list.json' => '["x","y"]'],
			'empty-fragment'   => ['10-empty.json' => '[]', '20-obj.json' => '{}'],
			'null-fragment'    => ['10-a.json' => '{"a":1}', '20-null.json' => 'null'],
			'scalar-fragment'  => ['10-num.json' => '5'],
		];
	}//end fragmentCases()

	/**
	 * The base configuration every fragment directory is merged over.
	 *
	 * @return array<mixed>
	 */
	private function base(): array {
		return [
			'info'        => ['version' => '1.0.0'],
			'components'  => ['schemas' => ['Base' => ['type' => 'object']]],
			'list'        => ['base'],
			'order'       => 'base',
			'listList'    => ['a0'],
			'0'           => 'zero',
		];
	}//end base()

	/**
	 * Write one fixture directory and merge it, with and without a malformed callback.
	 *
	 * @param string                $name  The case name
	 * @param array<string, string> $files File name => contents
	 *
	 * @return array<string, mixed>
	 */
	private function mergeFixture(string $name, array $files): array {
		if ($this->tmp === '') {
			$this->tmp = sys_get_temp_dir() . '/decidiq-settings-golden-' . getmypid() . '-' . bin2hex(random_bytes(4));
			mkdir($this->tmp);
		}

		$dir = $this->tmp . '/' . $name;
		mkdir($dir);
		foreach ($files as $file => $content) {
			file_put_contents($dir . '/' . $file, $content);
		}

		return [
			'reported' => $this->merge(dir: $dir, report: true),
			'silent'   => $this->merge(dir: $dir, report: false),
		];
	}//end mergeFixture()

	/**
	 * Merge one directory over the base and record what came back.
	 *
	 * @param string $dir    The fragment directory
	 * @param bool   $report Whether to pass a malformed-fragment callback
	 *
	 * @return array<string, mixed>
	 */
	private function merge(string $dir, bool $report): array {
		$malformed = [];
		$callback  = null;
		if ($report === true) {
			$callback = static function (string $fragment, string $reason) use (&$malformed): void {
				$malformed[] = [$fragment, $reason];
			};
		}

		try {
			$method = new \ReflectionMethod(SettingsService::class, 'mergeFragmentDirectory');
			[$merged, $signature] = $method->invoke(null, $this->base(), $dir, $callback);
			$returns = ['merged' => $merged, 'signature' => str_replace($dir, '<dir>', $signature)];
		} catch (\Throwable $e) {
			$returns = ['throws' => get_class($e)];
		}

		return ['returns' => $returns, 'malformed' => $malformed];
	}//end merge()

	/**
	 * Run one service-level case.
	 *
	 * @param array<string, mixed> $case The case
	 *
	 * @return array<string, mixed>
	 */
	private function runCase(array $case): array {
		$this->log = [];
		$service   = $this->service(case: $case);
		$returns   = match ($case['call']) {
			'get'       => $service->getSettings(),
			'update'    => $service->updateSettings($case['data']),
			'load'      => $service->loadConfiguration(),
			'reload'    => $service->reloadConfiguration(),
			'merged'    => $this->describe(data: $service->mergedRegisterConfig()),
			'roles'     => $service->roleGroupMapping(),
			'available' => $service->isOpenRegisterAvailable(),
		};

		return ['returns' => $returns, 'calls' => $this->log];
	}//end runCase()

	/**
	 * The service over one case.
	 *
	 * @param array<string, mixed> $case The case
	 *
	 * @return SettingsService
	 */
	private function service(array $case): SettingsService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use ($case): string {
				$this->log[] = ['getValueString' => [$app, $key, $default]];
				return ($case['config'][$key] ?? $default);
			}
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->log[] = ['setValueString' => [$app, $key, $value]];
				return true;
			}
		);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForAnyone')->willReturnCallback(
			function (string $app) use ($case): bool {
				$this->log[] = ['isEnabledForAnyone' => $app];
				return in_array($app, ($case['apps'] ?? []), true);
			}
		);

		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if (isset($case['user']) === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($case['user']);
		}

		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(
			function (string $uid): bool {
				$this->log[] = ['isAdmin' => $uid];
				return $uid === 'admin';
			}
		);

		return new SettingsService(
			appConfig: $config,
			appManager: $apps,
			container: $this->container(case: $case),
			groupManager: $groups,
			userSession: $session,
			logger: $this->logger(),
			roleMapping: new RoleGroupMapping()
		);
	}//end service()

	/**
	 * The container, answering with an importer that behaves as the case says.
	 *
	 * @param array<string, mixed> $case The case
	 *
	 * @return ContainerInterface
	 */
	private function container(array $case): ContainerInterface {
		$describe = function (array $data, string $version): array {
			$shipped = (string)(SettingsService::shippedRegisterDescriptor()['info']['version'] ?? '');
			$version = (string)preg_replace('/\+frag\.[0-9a-f]{8}(?=\+|$)/', '+frag.<sig>', $version);
			return [$this->describe(data: $data), str_replace($shipped, '<version>', $version)];
		};
		$importer = new class($case, $this->log, $describe) {
			/**
			 * Create the importer double.
			 *
			 * @param array<string, mixed>             $case The case
			 * @param array<int, array<string, mixed>> $log  The call log
			 * @param \Closure                         $describe Names the imported data and version
			 */
			public function __construct(private array $case, private array &$log, private \Closure $describe) {
			}

			/**
			 * Record the import and answer as the case says.
			 *
			 * @param string               $appId   The app id
			 * @param array<string, mixed> $data    The configuration
			 * @param string               $version The version
			 * @param bool                 $force   Whether to force
			 *
			 * @return array<string, mixed>
			 */
			public function importFromApp(string $appId, array $data, string $version, bool $force): array {
				$this->log[] = ['importFromApp' => [$appId, ...($this->describe)($data, $version), $force]];
				if (($this->case['import'] ?? null) === 'throw') {
					throw new RuntimeException('import down');
				}

				return ($this->case['import'] ?? []);
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($case, $importer): object {
				$this->log[] = ['container' => $id];
				if (($case['container'] ?? null) === 'throw') {
					throw new RuntimeException('no service');
				}

				return $importer;
			}
		);

		return $container;
	}//end container()

	/**
	 * Name a register configuration by what it equals, so the recording does
	 * not change whenever the shipped register does.
	 *
	 * @param array<mixed> $data The configuration
	 *
	 * @return string
	 */
	private function describe(array $data): string {
		$shipped = SettingsService::shippedRegisterDescriptor();
		$mapping = new RoleGroupMapping();
		$roles   = $mapping->decode(json: (string)json_encode(['secretariat' => ['g1', 'g2']]));
		return match (true) {
			$data === $shipped => 'shipped',
			$data === $mapping->rewrite(config: $shipped, mapping: $roles) => 'shipped with roles',
			$data === [] => 'empty',
			default => 'other ' . count($data),
		};
	}//end describe()

	/**
	 * A logger that records every line.
	 *
	 * @return AbstractLogger
	 */
	private function logger(): AbstractLogger {
		$log = &$this->log;
		return new class($log) extends AbstractLogger {
			/**
			 * Create the logger double.
			 *
			 * @param array<int, array<string, mixed>> $log The call log
			 */
			public function __construct(private array &$log) {
			}

			/**
			 * Record one log line.
			 *
			 * @param mixed             $level   The level
			 * @param string|Stringable $message The message
			 * @param array<mixed>      $context The context
			 *
			 * @return void
			 */
			public function log($level, string|Stringable $message, array $context=[]): void {
				$this->log[] = ['log' => [$level, (string)$message, $context]];
			}
		};
	}//end logger()

	/**
	 * Remove a directory tree.
	 *
	 * @param string $path The directory
	 *
	 * @return void
	 */
	private function removeTree(string $path): void {
		foreach ((array)glob($path . '/*') as $child) {
			if (is_dir((string)$child) === true) {
				$this->removeTree(path: (string)$child);
				continue;
			}

			unlink((string)$child);
		}

		rmdir($path);
	}//end removeTree()
}//end class
