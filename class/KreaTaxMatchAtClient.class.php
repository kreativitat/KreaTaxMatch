<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * Client for Portuguese Tax Authority (AT) e-Fatura authentication and document fetch.
 */
class KreaTaxMatchAtClient
{
	/**
	 * Observed AT response ceiling for one document batch.
	 */
	private const AT_RESPONSE_LIMIT = 300;

	/**
	 * Protect against infinite recursive range splitting on malformed payloads.
	 */
	private const MAX_RANGE_SPLIT_DEPTH = 32;

	/**
	 * @var string
	 */
	public $error = '';

	/**
	 * @var array<int,string>
	 */
	public $errors = array();

	/**
	 * Fetch purchase documents for a date range.
	 *
	 * @param string $nif
	 * @param string $password
	 * @param string $dateFrom YYYY-MM-DD
	 * @param string $dateTo YYYY-MM-DD
	 * @return array<int,array<string,mixed>>
	 * @throws Exception
	 */
	public function fetchPurchaseDocuments($nif, $password, $dateFrom, $dateTo)
	{
		$this->validateInput($nif, $password, $dateFrom, $dateTo);

		$cookieFile = $this->createCookieFile();
		try {
			$loginEntryUrl = 'https://www.acesso.gov.pt/jsp/loginRedirectForm.jsp?path=painelAdquirente.action&partID=EFPF';
			$loginPage = $this->request('GET', $loginEntryUrl, $cookieFile, array(
				'followlocation' => true,
			));
			$context = $this->extractLoginContext($loginPage['body']);

			$postFields = array(
				'username' => $nif,
				'password' => $password,
				'selectedAuthMethod' => 'N',
				$context['csrf_name'] => $context['csrf_token'],
			);
			foreach ($context['attributes'] as $attributeName => $attributeValue) {
				if (!array_key_exists($attributeName, $postFields)) {
					$postFields[$attributeName] = $attributeValue;
				}
			}
			if (!isset($postFields['authVersion'])) {
				$postFields['authVersion'] = '1';
			}
			if (!isset($postFields['path'])) {
				$postFields['path'] = 'painelAdquirente.action';
			}
			if (!isset($postFields['partID'])) {
				$postFields['partID'] = 'EFPF';
			}

			$loginPost = $this->request('POST', 'https://www.acesso.gov.pt/submissaoFormularioLogin', $cookieFile, array(
				'followlocation' => true,
				'referer' => $loginPage['effective_url'],
				'headers' => array(
					'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
					'Origin: https://www.acesso.gov.pt',
				),
				'postfields' => $postFields,
			));

			$this->assertLoginSucceeded($loginPost['body']);
			$this->completePostLoginFlow($loginPost, $cookieFile);
			$this->assertAuthenticatedSession($cookieFile);

			return $this->fetchPurchaseDocumentsRange($cookieFile, $dateFrom, $dateTo);
		} catch (Exception $e) {
			$this->error = $e->getMessage();
			$this->errors[] = $this->error;
			if (function_exists('dol_syslog')) {
				dol_syslog('KreaTaxMatchAtClient error: ' . $this->error, LOG_ERR);
			}
			throw $e;
		} finally {
			if (is_file($cookieFile)) {
				@unlink($cookieFile);
			}
		}
	}

	/**
	 * @param string $nif
	 * @param string $password
	 * @param string $dateFrom
	 * @param string $dateTo
	 * @return void
	 * @throws Exception
	 */
	private function validateInput($nif, $password, $dateFrom, $dateTo)
	{
		if (!preg_match('/^[0-9]{9}$/', (string) $nif)) {
			throw new Exception('AT NIF must contain 9 numeric digits.');
		}
		if ((string) $password === '') {
			throw new Exception('AT password is empty. Please configure it in module setup.');
		}
		if (!$this->isValidDate($dateFrom) || !$this->isValidDate($dateTo)) {
			throw new Exception('Date filters must use format YYYY-MM-DD.');
		}
		if (strcmp($dateFrom, $dateTo) > 0) {
			throw new Exception('Start date cannot be later than end date.');
		}
	}

	/**
	 * @param string $date
	 * @return bool
	 */
	private function isValidDate($date)
	{
		if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', (string) $date)) {
			return false;
		}
		$dt = DateTime::createFromFormat('Y-m-d', (string) $date);
		if (!$dt instanceof DateTime) {
			return false;
		}

		return $dt->format('Y-m-d') === (string) $date;
	}

	/**
	 * @return string
	 * @throws Exception
	 */
	private function createCookieFile()
	{
		$tempDir = function_exists('dol_get_temp_dir') ? dol_get_temp_dir(0, 1) : '';
		if ((string) $tempDir === '') {
			$tempDir = sys_get_temp_dir();
		}
		$cookieFile = tempnam($tempDir, 'kreataxmatch_');
		if ($cookieFile === false) {
			throw new Exception('Unable to allocate temporary cookie file for AT session.');
		}

		return $cookieFile;
	}

	/**
	 * @param string $body
	 * @return array{csrf_name:string,csrf_token:string,attributes:array<string,string>}
	 * @throws Exception
	 */
	private function extractLoginContext($body)
	{
		$context = array(
			'csrf_name' => '_csrf',
			'csrf_token' => '',
			'attributes' => array(),
		);

		if (preg_match('/parameterName:\s*`([^`]+)`/', (string) $body, $matchParameterName)) {
			$context['csrf_name'] = (string) $matchParameterName[1];
		}
		if (preg_match('/token:\s*`([^`]+)`/', (string) $body, $matchToken)) {
			$context['csrf_token'] = (string) $matchToken[1];
		}
		if ($context['csrf_token'] === '') {
			throw new Exception('Unable to extract AT login CSRF token.');
		}

		if (preg_match('/<script id="data-attributes" type="application\/json">(.*?)<\/script>/s', (string) $body, $matchAttributes)) {
			$attributesPayload = $this->decodeJson($matchAttributes[1]);
			if (is_array($attributesPayload)) {
				foreach ($attributesPayload as $key => $value) {
					if (is_scalar($key) && is_scalar($value)) {
						$context['attributes'][(string) $key] = (string) $value;
					}
				}
			}
		}

		return $context;
	}

	/**
	 * @param string $body
	 * @return void
	 * @throws Exception
	 */
	private function assertLoginSucceeded($body)
	{
		if (preg_match('/is2FA:\s*parseBoolean\(\'true\'\)/', (string) $body)) {
			throw new Exception('AT account requires two-factor authentication. This flow is not yet supported by KreaTaxMatch.');
		}

		if (preg_match('/<script id="data-field-error" type="application\/json">(.*?)<\/script>/s', (string) $body, $matchError)) {
			$fieldError = $this->decodeJson($matchError[1]);
			if (is_array($fieldError) && !empty($fieldError['errorMsg'])) {
				$errorMessage = html_entity_decode(strip_tags((string) $fieldError['errorMsg']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
				throw new Exception('AT login failed: ' . trim((string) $errorMessage));
			}
		}
	}

	/**
	 * @param string $cookieFile
	 * @return void
	 * @throws Exception
	 */
	private function assertAuthenticatedSession($cookieFile)
	{
		$sessionCheck = $this->request('GET', 'https://faturas.portaldasfinancas.gov.pt/painelAdquirente.action', $cookieFile, array(
			'followlocation' => false,
		));

		$redirectLocation = isset($sessionCheck['headers']['location']) ? (string) $sessionCheck['headers']['location'] : '';
		if ($sessionCheck['status'] >= 300 && $sessionCheck['status'] < 400 && stripos($redirectLocation, 'acesso.gov.pt') !== false) {
			throw new Exception('AT authentication did not establish an active e-Fatura session.');
		}
	}

	/**
	 * Execute post-login auto-submit flows used by acesso.gov.pt SSO pages.
	 *
	 * @param array{status:int,headers:array<string,string>,body:string,effective_url:string} $response
	 * @param string $cookieFile
	 * @return void
	 * @throws Exception
	 */
	private function completePostLoginFlow(array $response, $cookieFile)
	{
		$current = $response;
		for ($i = 0; $i < 5; $i++) {
			$form = $this->extractAutoSubmitForm($current['body'], $current['effective_url']);
			if ($form !== null) {
				$options = array(
					'followlocation' => true,
					'referer' => $current['effective_url'],
				);
				if ($form['method'] === 'POST') {
					$options['headers'] = array('Content-Type: application/x-www-form-urlencoded; charset=UTF-8');
					$options['postfields'] = $form['fields'];
					$current = $this->request('POST', $form['action'], $cookieFile, $options);
				} else {
					$url = $form['action'];
					if (!empty($form['fields'])) {
						$separator = (strpos($url, '?') === false) ? '?' : '&';
						$url .= $separator . http_build_query($form['fields'], '', '&', PHP_QUERY_RFC3986);
					}
					$current = $this->request('GET', $url, $cookieFile, $options);
				}
				continue;
			}

			$jsRedirect = $this->extractJsRedirectUrl($current['body'], $current['effective_url']);
			if ($jsRedirect !== null) {
				$current = $this->request('GET', $jsRedirect, $cookieFile, array(
					'followlocation' => true,
					'referer' => $current['effective_url'],
				));
				continue;
			}

			break;
		}
	}

	/**
	 * @param array<string,mixed> $payload
	 * @param string $default
	 * @return string
	 */
	private function extractAtErrorMessage(array $payload, $default)
	{
		if (!empty($payload['messages']['error']) && is_array($payload['messages']['error'])) {
			$first = reset($payload['messages']['error']);
			if (is_scalar($first)) {
				return html_entity_decode(strip_tags((string) $first), ENT_QUOTES | ENT_HTML5, 'UTF-8');
			}
		}

		return $default;
	}

	/**
	 * Fetch one logical date range, splitting it automatically when the AT API
	 * reports more records than fit in a single response.
	 *
	 * @param string $cookieFile
	 * @param string $dateFrom
	 * @param string $dateTo
	 * @param int $depth
	 * @return array<int,array<string,mixed>>
	 * @throws Exception
	 */
	private function fetchPurchaseDocumentsRange($cookieFile, $dateFrom, $dateTo, $depth = 0)
	{
		if ((int) $depth > self::MAX_RANGE_SPLIT_DEPTH) {
			throw new Exception('AT range splitting exceeded the maximum safe recursion depth.');
		}

		$payload = $this->requestPurchaseDocumentsPayload($cookieFile, $dateFrom, $dateTo);
		$documents = $this->extractDocuments($payload);
		if (!$this->payloadRequiresSplitting($payload, $documents)) {
			return $documents;
		}

		if ($dateFrom === $dateTo) {
			$totalElements = $this->extractPayloadInt($payload, 'totalElementos');
			$returnedElements = count($documents);
			throw new Exception(
				'AT returned more documents for the single day ' . $dateFrom
				. ' than one response can safely contain'
				. ($totalElements > 0 ? ' (returned ' . $returnedElements . ' of ' . $totalElements . ').' : '.')
				. ' Automatic download cannot continue for that day without true server-side pagination.'
			);
		}

		list($leftFrom, $leftTo, $rightFrom, $rightTo) = $this->splitDateRange($dateFrom, $dateTo);
		if (function_exists('dol_syslog')) {
			$totalElements = $this->extractPayloadInt($payload, 'totalElementos');
			dol_syslog(
				'KreaTaxMatchAtClient splitting AT range ' . $dateFrom . ' -> ' . $dateTo
				. ' into ' . $leftFrom . ' -> ' . $leftTo
				. ' and ' . $rightFrom . ' -> ' . $rightTo
				. ' after receiving ' . count($documents)
				. ($totalElements > 0 ? ' of ' . $totalElements : '')
				. ' rows in one batch.',
				LOG_INFO
			);
		}

		$leftDocuments = $this->fetchPurchaseDocumentsRange($cookieFile, $leftFrom, $leftTo, $depth + 1);
		$rightDocuments = $this->fetchPurchaseDocumentsRange($cookieFile, $rightFrom, $rightTo, $depth + 1);

		return array_merge($leftDocuments, $rightDocuments);
	}

	/**
	 * Execute one AT JSON request for one exact date range.
	 *
	 * @param string $cookieFile
	 * @param string $dateFrom
	 * @param string $dateTo
	 * @return array<string,mixed>
	 * @throws Exception
	 */
	private function requestPurchaseDocumentsPayload($cookieFile, $dateFrom, $dateTo)
	{
		$query = http_build_query(array(
			'dataInicioFilter' => $dateFrom,
			'dataFimFilter' => $dateTo,
			'ambitoAquisicaoFilter' => '',
		), '', '&', PHP_QUERY_RFC3986);
		$jsonResponse = $this->request(
			'GET',
			'https://faturas.portaldasfinancas.gov.pt/json/obterDocumentosAdquirente.action?' . $query,
			$cookieFile,
			array(
				'followlocation' => true,
				'headers' => array(
					'Accept: application/json, text/plain, */*',
					'X-Requested-With: XMLHttpRequest',
				),
			)
		);

		$payload = $this->decodeJson($jsonResponse['body']);
		if (!is_array($payload)) {
			throw new Exception('Unexpected AT response payload format.');
		}
		if (!empty($payload['expiredSession'])) {
			throw new Exception('AT session expired while fetching e-Fatura documents.');
		}
		if (isset($payload['success']) && !$payload['success']) {
			throw new Exception($this->extractAtErrorMessage($payload, 'AT returned an error while fetching e-Fatura documents.'));
		}

		return $payload;
	}

	/**
	 * Decide whether one AT payload appears truncated and must be split by date.
	 *
	 * @param array<string,mixed> $payload
	 * @param array<int,array<string,mixed>> $documents
	 * @return bool
	 */
	private function payloadRequiresSplitting(array $payload, array $documents)
	{
		$returnedElements = count($documents);
		$totalElements = $this->extractPayloadInt($payload, 'totalElementos');
		return ($totalElements > $returnedElements);
	}

	/**
	 * @param array<string,mixed> $payload
	 * @param string $key
	 * @return int
	 */
	private function extractPayloadInt(array $payload, $key)
	{
		if (!array_key_exists((string) $key, $payload)) {
			return 0;
		}
		$value = $payload[(string) $key];
		if (is_int($value)) {
			return $value;
		}
		if (is_string($value) && preg_match('/^[0-9]+$/', $value)) {
			return (int) $value;
		}

		return 0;
	}

	/**
	 * Split an inclusive YYYY-MM-DD date range into two non-overlapping sub-ranges.
	 *
	 * @param string $dateFrom
	 * @param string $dateTo
	 * @return array{0:string,1:string,2:string,3:string}
	 * @throws Exception
	 */
	private function splitDateRange($dateFrom, $dateTo)
	{
		$start = DateTime::createFromFormat('Y-m-d', (string) $dateFrom);
		$end = DateTime::createFromFormat('Y-m-d', (string) $dateTo);
		if (!$start instanceof DateTime || !$end instanceof DateTime) {
			throw new Exception('Unable to split AT date range due to invalid date boundaries.');
		}

		$daySpan = (int) $start->diff($end)->days;
		if ($daySpan <= 0) {
			throw new Exception('Unable to split AT date range because it spans less than one full day.');
		}

		$leftEnd = clone $start;
		$leftEnd->modify('+' . (string) intdiv($daySpan, 2) . ' day');
		$rightStart = clone $leftEnd;
		$rightStart->modify('+1 day');

		return array(
			$start->format('Y-m-d'),
			$leftEnd->format('Y-m-d'),
			$rightStart->format('Y-m-d'),
			$end->format('Y-m-d'),
		);
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<int,array<string,mixed>>
	 */
	private function extractDocuments(array $payload)
	{
		$candidates = array(
			'linhas',
			'documentos',
			'listaDocumentos',
			'documentosAdquirente',
			'rows',
			'items',
			'data',
			'result',
		);

		foreach ($candidates as $key) {
			if (!isset($payload[$key])) {
				continue;
			}
			if (is_array($payload[$key]) && $this->isListOfArrays($payload[$key])) {
				return $payload[$key];
			}

			if (is_array($payload[$key])) {
				$nested = $this->extractDocuments($payload[$key]);
				if (!empty($nested)) {
					return $nested;
				}
			}
		}

		if ($this->isListOfArrays($payload)) {
			return $payload;
		}

		return array();
	}

	/**
	 * @param mixed $value
	 * @return bool
	 */
	private function isListOfArrays($value)
	{
		if (!is_array($value) || empty($value)) {
			return false;
		}

		foreach ($value as $row) {
			if (!is_array($row)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param string $body
	 * @return mixed
	 */
	private function decodeJson($body)
	{
		$decoded = json_decode((string) $body, true);
		if (json_last_error() === JSON_ERROR_NONE) {
			return $decoded;
		}

		$decoded = json_decode($this->toUtf8((string) $body), true);

		return (json_last_error() === JSON_ERROR_NONE) ? $decoded : null;
	}

	/**
	 * @param string $value
	 * @return string
	 */
	private function toUtf8($value)
	{
		$converted = @iconv('ISO-8859-15', 'UTF-8//IGNORE', (string) $value);
		if ($converted !== false && (string) $converted !== '') {
			return (string) $converted;
		}

		return (string) $value;
	}

	/**
	 * @param string $method
	 * @param string $url
	 * @param string $cookieFile
	 * @param array<string,mixed> $options
	 * @return array{status:int,headers:array<string,string>,body:string,effective_url:string}
	 * @throws Exception
	 */
	private function request($method, $url, $cookieFile, array $options = array())
	{
		$ch = curl_init((string) $url);
		if ($ch === false) {
			throw new Exception('Unable to initialize cURL session for AT request.');
		}

		$headers = array('Accept: text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8');
		if (!empty($options['headers']) && is_array($options['headers'])) {
			$headers = array_merge($headers, $options['headers']);
		}

		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, !empty($options['followlocation']));
		curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
		curl_setopt($ch, CURLOPT_TIMEOUT, 60);
		curl_setopt($ch, CURLOPT_USERAGENT, 'KreaTaxMatch/1.1.0 (+Dolibarr)');
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
		curl_setopt($ch, CURLOPT_ENCODING, '');
		curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
		curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

		if (!empty($options['referer'])) {
			curl_setopt($ch, CURLOPT_REFERER, (string) $options['referer']);
		}

		if (strtoupper((string) $method) === 'POST') {
			curl_setopt($ch, CURLOPT_POST, true);
			$postFields = !empty($options['postfields']) && is_array($options['postfields'])
				? http_build_query($options['postfields'], '', '&', PHP_QUERY_RFC3986)
				: '';
			curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
		}

		$raw = curl_exec($ch);
		if ($raw === false) {
			$error = curl_error($ch);
			curl_close($ch);

			throw new Exception('AT request failed: ' . $error);
		}

		$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
		$effectiveUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
		curl_close($ch);

		$headerRaw = substr((string) $raw, 0, $headerSize);
		$body = substr((string) $raw, $headerSize);

		return array(
			'status' => $status,
			'headers' => $this->parseHeaders($headerRaw),
			'body' => (string) $body,
			'effective_url' => $effectiveUrl,
		);
	}

	/**
	 * @param string $headerRaw
	 * @return array<string,string>
	 */
	private function parseHeaders($headerRaw)
	{
		$headers = array();
		$lines = preg_split('/\r\n|\r|\n/', (string) $headerRaw);
		if (!is_array($lines)) {
			return $headers;
		}

		foreach ($lines as $line) {
			$line = trim((string) $line);
			if ($line === '' || strpos($line, ':') === false) {
				continue;
			}

			list($name, $value) = explode(':', $line, 2);
			$headers[strtolower(trim((string) $name))] = trim((string) $value);
		}

		return $headers;
	}

	/**
	 * @param string $html
	 * @param string $baseUrl
	 * @return array{action:string,method:string,fields:array<string,string>}|null
	 */
	private function extractAutoSubmitForm($html, $baseUrl)
	{
		if ((string) $html === '' || stripos($html, '<form') === false) {
			return null;
		}
		if (!class_exists('DOMDocument')) {
			return null;
		}

		$doc = new DOMDocument();
		libxml_use_internal_errors(true);
		$loaded = $doc->loadHTML((string) $html);
		libxml_clear_errors();
		if (!$loaded) {
			return null;
		}

		$forms = $doc->getElementsByTagName('form');
		if ($forms->length === 0) {
			return null;
		}

		$form = $forms->item(0);
		if (!$form instanceof DOMElement) {
			return null;
		}

		$method = strtoupper(trim((string) $form->getAttribute('method')));
		if ($method !== 'POST') {
			$method = 'GET';
		}

		$action = trim((string) $form->getAttribute('action'));
		$action = $this->resolveUrl($action, $baseUrl);
		if ($action === '') {
			return null;
		}

		$fields = array();
		$inputs = $form->getElementsByTagName('input');
		foreach ($inputs as $input) {
			if (!$input instanceof DOMElement) {
				continue;
			}
			$name = trim((string) $input->getAttribute('name'));
			if ($name === '') {
				continue;
			}
			$type = strtolower(trim((string) $input->getAttribute('type')));
			if ($type === 'submit' || $type === 'button' || $type === 'image') {
				continue;
			}
			$fields[$name] = (string) $input->getAttribute('value');
		}

		return array(
			'action' => $action,
			'method' => $method,
			'fields' => $fields,
		);
	}

	/**
	 * @param string $html
	 * @param string $baseUrl
	 * @return string|null
	 */
	private function extractJsRedirectUrl($html, $baseUrl)
	{
		$patterns = array(
			'/window\\.location\\.href\\s*=\\s*[\'"]([^\'"]+)[\'"]/i',
			'/window\\.location\\s*=\\s*[\'"]([^\'"]+)[\'"]/i',
			'/location\\.href\\s*=\\s*[\'"]([^\'"]+)[\'"]/i',
		);
		foreach ($patterns as $pattern) {
			if (preg_match($pattern, (string) $html, $match)) {
				$url = $this->resolveUrl((string) $match[1], $baseUrl);
				if ($url !== '') {
					return $url;
				}
			}
		}

		return null;
	}

	/**
	 * @param string $url
	 * @param string $baseUrl
	 * @return string
	 */
	private function resolveUrl($url, $baseUrl)
	{
		$url = trim((string) $url);
		if ($url === '') {
			return (string) $baseUrl;
		}
		if (preg_match('/^https?:\\/\\//i', $url)) {
			return $url;
		}

		$base = parse_url((string) $baseUrl);
		if (!is_array($base) || empty($base['scheme']) || empty($base['host'])) {
			return $url;
		}

		$root = $base['scheme'] . '://' . $base['host'];
		if (!empty($base['port'])) {
			$root .= ':' . ((int) $base['port']);
		}

		if ($url[0] === '/') {
			return $root . $url;
		}

		$path = isset($base['path']) ? (string) $base['path'] : '/';
		$dir = rtrim(str_replace('\\', '/', dirname($path)), '/');
		if ($dir === '') {
			$dir = '/';
		}

		$baseDir = ($dir === '/') ? '' : $dir;

		return rtrim($root, '/') . $baseDir . '/' . ltrim($url, '/');
	}
}
