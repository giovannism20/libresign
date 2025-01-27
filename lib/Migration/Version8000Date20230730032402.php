<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2023 Vitor Mattos <vitor@php.rio>
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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Libresign\Migration;

use Closure;
use OCA\Libresign\Service\InstallService;
use OCP\AppFramework\Services\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version8000Date20230730032402 extends SimpleMigrationStep {
	public function __construct(
		protected InstallService $installService,
		protected IAppConfig $appConfig,
	) {
	}

	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$this->installService->installPdftk();
		if ($rootCert = $this->appConfig->getAppValue('rootCert')) {
			$this->appConfig->deleteAppValue('rootCert');
			$this->appConfig->setAppValue('root_cert', $rootCert);
		}
		if ($notifyUnsignedUser = $this->appConfig->getAppValue('notifyUnsignedUser', '')) {
			$this->appConfig->setAppValue('notify_unsigned_user', $notifyUnsignedUser);
		}
		$this->appConfig->deleteAppValue('notifyUnsignedUser');
	}
}
