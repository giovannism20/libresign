<?php
/**
 * @copyright Copyright (c) 2022, Vitor Mattos <vitor@php.rio>
 *
 * @author Vitor Mattos <vitor@php.rio>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Libresign\Tests\Unit\Service;

use OCA\Libresign\Handler\CertificateEngine\Handler as CertificateEngineHandler;
use OCA\Libresign\Service\InstallService;
use OCP\AppFramework\Services\IAppConfig;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IRootFolder;
use OCP\Http\Client\IClientService;
use OCP\ICacheFactory;
use OCP\IConfig;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\BufferedOutput;

final class InstallServiceTest extends \OCA\Libresign\Tests\Unit\TestCase {
	private ICacheFactory|MockObject $cacheFactory;
	private IClientService|MockObject $clientService;
	private CertificateEngineHandler|MockObject $certificateEngineHandler;
	private IConfig|MockObject $config;
	private IAppConfig|MockObject $appConfig;
	private IRootFolder|MockObject $rootFolder;
	private LoggerInterface|MockObject $logger;
	private IAppDataFactory|MockObject $appDataFactory;

	public function setUp(): void {
		parent::setUp();
	}

	protected function getInstallService(): InstallService {
		$this->cacheFactory = $this->createMock(ICacheFactory::class);
		$this->clientService = $this->createMock(IClientService::class);
		$this->certificateEngineHandler = $this->createMock(CertificateEngineHandler::class);
		$this->config = $this->createMock(IConfig::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->appDataFactory = $this->createMock(IAppDataFactory::class);
		return new InstallService(
			$this->cacheFactory,
			$this->clientService,
			$this->certificateEngineHandler,
			$this->config,
			$this->appConfig,
			$this->rootFolder,
			$this->logger,
			$this->appDataFactory
		);
	}

	/**
	 * @dataProvider providerDownloadCli
	 */
	public function testDownloadCli(string $url, string $filename, string $path, string $hash, string $algorithm, string $expectedOutput): void {
		$installService = $this->getInstallService();
		$output = new BufferedOutput();
		$installService->setOutput($output);
		self::invokePrivate($installService, 'downloadCli', [$url, $filename, $path, $hash, $algorithm]);
		$actual = $output->fetch();
		$this->assertEquals($expectedOutput, $actual);
	}

	public function providerDownloadCli(): array {
		vfsStream::setup('download');

		$pathInvalid = 'vfs://download/appInvalid.svg';
		file_put_contents($pathInvalid, 'invalidContent');
		$pathValid = 'vfs://download/validContent.svg';
		file_put_contents($pathValid, 'invalidContent');
		return [
			[
				"http://localhost/apps/libresign/img/app.svg",
				'app.svg',
				'vfs://download/app.svg',
				'',
				'md5',
				"Downloading app.svg...\n" .
				"    0 [>---------------------------]\n".
				"Failure on download app.svg, empty file, try again\n",
			],
			[
				"http://localhost/apps/libresign/img/appInvalid.svg",
				'appInvalid.svg',
				$pathInvalid,
				'hashInvalid',
				'md5',
				"Downloading appInvalid.svg...\n" .
				"    0 [>---------------------------]\n" .
				"Failure on download appInvalid.svg try again\n" .
				"Invalid md5\n",
			],
			[
				"http://localhost/apps/libresign/img/appInvalid.svg",
				'appInvalid.svg',
				$pathInvalid,
				'hashInvalid',
				'sha256',
				"Downloading appInvalid.svg...\n" .
				"    0 [>---------------------------]\n" .
				"Failure on download appInvalid.svg try again\n" .
				"Invalid sha256\n",
			],
			[
				"http://localhost/apps/libresign/img/validContent.svg",
				'validContent.svg',
				$pathValid,
				hash_file('sha256', $pathValid),
				'sha256',
				"Downloading validContent.svg...\n" .
				"    0 [>---------------------------]\n",
			],
		];
	}
}
