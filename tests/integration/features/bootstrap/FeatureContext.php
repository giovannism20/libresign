<?php

use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;
use Behat\Testwork\Hook\Scope\BeforeSuiteScope;
use Libresign\NextcloudBehat\NextcloudApiContext;
use PHPUnit\Framework\Assert;
use rpkamp\Behat\MailhogExtension\Context\OpenedEmailStorageAwareContext;
use rpkamp\Behat\MailhogExtension\Service\OpenedEmailStorage;

/**
 * Defines application features from the specific context.
 */
class FeatureContext extends NextcloudApiContext implements OpenedEmailStorageAwareContext {
	private array $signer = [];
	private array $file = [];
	private array $fields = [];
	private OpenedEmailStorage $openedEmailStorage;

	/**
	 * @BeforeSuite
	 */
	public static function beforeSuite(BeforeSuiteScope $scope) {
		if (get_current_user() !== exec('whoami')) {
			throw new Exception(sprintf('Have files that %s is the owner.and the user that is running this test is %s, is necessary to be the same user', get_current_user(), exec('whoami')));
		}
		self::runCommand('config:system:set debug --value true --type boolean');
		self::runCommand('app:enable --force notifications');
	}

	/**
	 * @BeforeScenario
	 */
	public static function BeforeScenario(): void {
		self::runCommand('libresign:developer:reset --all');
	}

	/**
	 * @When /^run the command "(?P<command>(?:[^"]|\\")*)"$/
	 */
	public static function runCommand($command): void {
		$console = realpath(__DIR__ . '/../../../../../../console.php');
		$owner = posix_getpwuid(fileowner($console));
		$fullCommand = 'php ' . $console . ' ' . $command;
		if (posix_getuid() !== $owner['uid']) {
			$fullCommand = 'runuser -u ' . $owner['name'] . ' -- ' . $fullCommand;
		}
		$fullCommand .= '  2>&1';
		exec($fullCommand, $output);
	}

	public function setOpenedEmailStorage(OpenedEmailStorage $storage): void {
		$this->openedEmailStorage = $storage;
	}

	public function sendRequest(string $verb, string $url, $body = null, array $headers = [], array $options = []): void {
		if (!is_null($this->currentUser)) {
			$options = array_merge(
				['cookies' => $this->getUserCookieJar($this->currentUser)],
				$options
			);
		}
		parent::sendRequest($verb, $url, $body, $headers, $options);
	}

	/**
	 * @Then /^the signer "([^"]*)" have a file to sign$/
	 */
	public function theSignerHaveAFileToSign(string $signer): void {
		$this->setCurrentUser($signer);
		$this->sendOCSRequest('get', '/apps/libresign/api/v1/file/list');
		$response = json_decode($this->response->getBody()->getContents(), true);
		Assert::assertGreaterThan(0, $response['data'], 'Haven\'t files to sign');
		$this->signer = [];
		$this->file = [];
		foreach (array_reverse($response['data']) as $file) {
			$currentSigner = array_filter($file['signers'], function ($signer): bool {
				return $signer['me'];
			});
			if (count($currentSigner) === 1) {
				$this->signer = end($currentSigner);
				$this->file = $file;
				break;
			}
		}
		Assert::assertGreaterThan(1, $this->signer, $signer . ' don\'t will sign a file');
		Assert::assertGreaterThan(1, $this->file, 'The /file/list didn\'t returned a file assigned to ' . $signer);
	}

	/**
	 * @Then /^the file to sign contains$/
	 *
	 * @param string $name
	 */
	public function theFileToSignContains(TableNode $table): void {
		if (!$this->file) {
			$this->theSignerHaveAFileToSign($this->currentUser);
		}
		$expectedValues = $table->getColumnsHash();
		foreach ($expectedValues as $value) {
			Assert::assertArrayHasKey($value['key'], $this->file);
			if ($value['value'] === '<IGNORE>') {
				continue;
			}
			Assert::assertEquals($value['value'], $this->file[$value['key']]);
		}
	}

	protected function beforeRequest(string $fullUrl, array $options): array {
		list($fullUrl, $options) = parent::beforeRequest($fullUrl, $options);
		$options = $this->parseFormParams($options);
		$fullUrl = $this->parseText($fullUrl);
		return [$fullUrl, $options];
	}

	protected function parseFormParams(array $options): array {
		if (!empty($options['form_params'])) {
			$this->parseTextRcursive($options['form_params']);
		}
		return $options;
	}

	private function parseTextRcursive(&$array): array {
		array_walk_recursive($array, function (&$value) {
			if (is_string($value)) {
				$value = $this->parseText($value);
			} elseif ($value instanceof \stdClass) {
				$value = (array) $value;
				$value = json_decode(json_encode($this->parseTextRcursive($value)));
			}
		});
		return $array;
	}

	protected function parseText(string $text): string {
		$patterns = [
			'/<SIGN_UUID>/',
			'/<FILE_UUID>/',
			'/<BASE_URL>/',
		];
		$replacements = [
			$this->signer['sign_uuid'] ?? null,
			$this->file['uuid'] ?? $this->getFileUuidFromText($text),
			$this->baseUrl . '/index.php',
		];
		foreach ($this->fields as $key => $value) {
			$patterns[] = '/<' . $key . '>/';
			$replacements[] = $value;
		}
		$text = preg_replace($patterns, $replacements, $text);
		return $text;
	}

	private function getFileUuidFromText(string $text): ?string {
		if (!$this->isJson($text)) {
			return '';
		}
		$json = json_decode($text, true);
		if (isset($json['sign']['uuid']) && $json['sign']['uuid']) {
			return $this->file['uuid'] = $json['sign']['uuid'];
		}
		return '';
	}

	/**
	 * @Given the signer contains
	 */
	public function theSignerContains(TableNode $table): void {
		if (!$this->signer) {
			$this->theSignerHaveAFileToSign($this->currentUser);
		}
		$expectedValues = $table->getColumnsHash();
		foreach ($expectedValues as $value) {
			Assert::assertArrayHasKey($value['key'], $this->signer);
			if ($value['value'] === '<IGNORE>') {
				continue;
			}
			$actual = $this->signer[$value['key']];
			if (is_array($this->signer[$value['key']]) || is_object($this->signer[$value['key']])) {
				$actual = json_encode($actual);
			}
			Assert::assertEquals($value['value'], $actual, sprintf('The actual value of key "%s" is different of expected', $value['key']));
		}
	}

	/**
	 * @When I fetch the signer UUID from opened email
	 */
	public function iFetchTheLinkOnOpenedEmail(): void {
		if (!$this->openedEmailStorage->hasOpenedEmail()) {
			throw new RuntimeException('No email opened, unable to do something!');
		}

		/** @var \rpkamp\Mailhog\Message\Message $openedEmail */
		$openedEmail = $this->openedEmailStorage->getOpenedEmail();
		preg_match('/p\/sign\/(?<uuid>[\w-]+)"/', $openedEmail->body, $matches);
		Assert::assertArrayHasKey('uuid', $matches, 'UUID not found on email');
		$this->signer['sign_uuid'] = $matches['uuid'];
	}

	/**
	 * @when I send a file to be signed
	 */
	public function iSendAFileToBeSigned(TableNode $body): void {
		$this->sendOCSRequest('post', '/apps/libresign/api/v1/request-signature', $body);
		$realResponseArray = json_decode($this->response->getBody()->getContents(), true);
		$this->file['uuid'] = $realResponseArray['data']['uuid'];
	}

	/**
	 * @When I change the file
	 */
	public function iChangeTheFile(TableNode $body): void {
		$newBody = [];
		foreach ($body->getTable() as $key => $row) {
			$newBody[$key] = $row;
			if ($row[1] === '<FILE_UUID>') {
				$newBody[$key][1] = $this->file['uuid'];
			}
		}
		$body = new TableNode($newBody);
		$this->sendOCSRequest('patch', '/apps/libresign/api/v1/request-signature', $body);
		$realResponseArray = json_decode($this->response->getBody()->getContents(), true);
	}

	/**
	 * @When follow the link on opened email
	 */
	public function iDoSomethingWithTheOpenedEmail(): void {
		if (!$this->openedEmailStorage->hasOpenedEmail()) {
			throw new RuntimeException('No email opened, unable to do something!');
		}

		/** @var \rpkamp\Mailhog\Message\Message $openedEmail */
		$openedEmail = $this->openedEmailStorage->getOpenedEmail();
		preg_match('/p\/sign\/(?<uuid>[\w-]+)"/', $openedEmail->body, $matches);

		$this->sendRequest('get', '/apps/libresign/p/sign/' . $matches['uuid']);
	}

	/**
	 * @When reset notifications of user :user
	 */
	public function resetNotifications($user): void {
		self::runCommand('libresign:developer:reset --notifications=' . $user);
	}

	/**
	 * @When the response of file list match with:
	 */
	public function theResponseOfFileListMatchWith(PyStringNode $expected): void {
		$this->response->getBody()->seek(0);
		$realResponseArray = json_decode($this->response->getBody()->getContents(), true);
		$expectedArray = json_decode($expected, true);
		Assert::assertArrayHasKey('pagination', $realResponseArray, 'The response have not pagination');
		Assert::assertJsonStringEqualsJsonString(json_encode($expectedArray['pagination']), json_encode($realResponseArray['pagination']));
		Assert::assertArrayHasKey('data', $realResponseArray);
		Assert::assertCount(count($expectedArray['data']), $realResponseArray['data']);
		foreach ($expectedArray['data'] as $fileFey => $file) {
			Assert::assertCount(count($file['signers']), $realResponseArray['data'][$fileFey]['signers']);
			foreach ($file['signers'] as $signerKey => $signer) {
				Assert::assertCount(count($signer['identifyMethods']), $realResponseArray['data'][$fileFey]['signers'][$signerKey]['identifyMethods']);
			}
		}
	}

	/**
	 * @When delete signer :signer from file :file of previous listing
	 */
	public function deleteSignerFromFileOfPreviousListing(int $signerSequence, int $fileSequence): void {
		$this->response->getBody()->seek(0);
		$responseArray = json_decode($this->response->getBody()->getContents(), true);
		$fileId = $responseArray['data'][$fileSequence - 1]['nodeId'];
		$signRequestId = $responseArray['data'][$fileSequence - 1]['signers'][$signerSequence - 1]['signRequestId'];
		$this->sendOCSRequest('delete', '/apps/libresign/api/v1/sign/file_id/' . $fileId . '/'. $signRequestId);
	}

	/**
	 * @When fetch field :path from prevous JSON response
	 */
	public function fetchFieldFromPreviousJsonResponse(string $path): void {
		$this->response->getBody()->seek(0);
		$responseArray = json_decode($this->response->getBody()->getContents(), true);
		$keys = explode('.', $path);
		$value = $responseArray;
		foreach ($keys as $key) {
			Assert::assertArrayHasKey($key, $value, 'Key [' . $key . '] of path [' . $path . '] not found.');
			$value = $value[$key];
		}
		$this->fields[$path] = $value;
	}

	/**
	 * @When /^wait for ([0-9]+) (second|seconds)$/
	 */
	public function waitForXSecond($seconds): void {
		sleep($seconds);
	}

	/**
	 * @When user :user has the following notifications
	 *
	 * @param string $user
	 * @param TableNode|null $body
	 */
	public function userNotifications(string $user, TableNode $body = null): void {
		$this->setCurrentUser($user);
		$this->sendOCSRequest(
			'GET', '/apps/notifications/api/v2/notifications'
		);

		$jsonBody = json_decode($this->response->getBody()->getContents(), true);
		if ($this->response->getStatusCode() === 500) {
			throw new Exception('Internal failure when access notifications endpoint');
		}
		$data = $jsonBody['ocs']['data'];

		if ($body === null) {
			Assert::assertCount(0, $data);
			return;
		}
		$expectedValues = $body->getColumnsHash();
		foreach ($expectedValues as $expected) {
			ksort($expected);
			$found = false;
			foreach ($data as $actual) {
				$actualIntersect = array_filter(
					$actual,
					function ($k) use ($expected) {
						return isset($expected[$k]);
					},
					ARRAY_FILTER_USE_KEY,
				);
				ksort($actualIntersect);
				if ($expected === $actualIntersect) {
					$found = true;
					break;
				}
			}
			if (!$found) {
				throw new Exception('Notification not found: ' . json_encode($expected));
			}
		}
	}

	/**
	 * @When I fetch the signer UUID from notification
	 */
	public function iFetchTheSignerUuidFromNotification(): void {
		$this->sendOCSRequest(
			'GET', '/apps/notifications/api/v2/notifications'
		);

		$jsonBody = json_decode($this->response->getBody()->getContents(), true);
		$data = $jsonBody['ocs']['data'];

		$found = array_filter(
			$data,
			function ($notification) {
				return $notification['subject'] === 'admin requested your signature on document';
			}
		);
		if (empty($found)) {
			throw new Exception('Notification with the subject [admin requested your signature on document] not found');
		}
		$found = current($found);


		preg_match('/p\/sign\/(?<uuid>[\w-]+)$/', $found['link'], $matches);
		Assert::assertArrayHasKey('uuid', $matches, 'UUID not found on email');
		$this->signer['sign_uuid'] = $matches['uuid'];
	}
}
