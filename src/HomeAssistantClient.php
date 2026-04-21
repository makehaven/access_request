<?php

namespace Drupal\access_request;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;

/**
 * Calls the Home Assistant REST API directly.
 *
 * Used by AccessRequestService when an asset is configured with
 * backend: home_assistant. Keeps the Python-gateway path entirely untouched;
 * its own circuit breaker state is isolated under the ha_* state keys.
 */
class HomeAssistantClient {

  const CIRCUIT_CLOSED = 'closed';
  const CIRCUIT_OPEN = 'open';
  const CIRCUIT_HALF_OPEN = 'half_open';

  const FAILURE_THRESHOLD = 3;
  const RESET_TIMEOUT = 60;

  const STATE_CIRCUIT = 'access_request.ha_circuit_state';
  const STATE_FAILURES = 'access_request.ha_failure_count';
  const STATE_LAST_FAIL = 'access_request.ha_last_failure_time';

  const TOKEN_ENV_VAR = 'HA_BEARER_TOKEN';

  protected ConfigFactoryInterface $configFactory;
  protected ClientInterface $httpClient;
  protected $logger;
  protected StateInterface $state;

  public function __construct(ConfigFactoryInterface $config_factory, ClientInterface $http_client, LoggerChannelFactoryInterface $logger_factory, StateInterface $state) {
    $this->configFactory = $config_factory;
    $this->httpClient = $http_client;
    $this->logger = $logger_factory->get('access_request');
    $this->state = $state;
  }

  /**
   * Returns TRUE when a bearer token is available in the environment.
   */
  public function hasToken(): bool {
    return $this->readToken() !== '';
  }

  /**
   * Returns the configured HA base URL, or an empty string if unset.
   */
  public function getBaseUrl(): string {
    $config = $this->configFactory->get('access_request.settings');
    return rtrim((string) ($config->get('home_assistant.base_url') ?? ''), '/');
  }

  /**
   * Calls a Home Assistant service.
   *
   * @param string $domain
   *   HA service domain (e.g. "esphome", "script", "switch").
   * @param string $service
   *   HA service name (e.g. "backdooractivator_enable", "turn_on").
   * @param array $data
   *   Service data to include in the JSON body.
   * @param array $target
   *   Optional HA "target" (entity_id/device_id/area_id) merged into the body.
   *
   * @return array
   *   Shape compatible with AccessRequestService::sendRequest():
   *   - http_status: int
   *   - body: string
   */
  public function callService(string $domain, string $service, array $data = [], array $target = []): array {
    $base_url = $this->getBaseUrl();
    if ($base_url === '') {
      $this->logger->error('Home Assistant base_url is not configured.');
      return ['http_status' => 503, 'body' => 'Home Assistant base URL not configured.'];
    }

    $token = $this->readToken();
    if ($token === '') {
      $this->logger->error('Home Assistant bearer token missing: env var @var is not set.', ['@var' => self::TOKEN_ENV_VAR]);
      return ['http_status' => 503, 'body' => 'Home Assistant token not configured.'];
    }

    $current_time = \Drupal::time()->getRequestTime();
    $circuit_state = $this->state->get(self::STATE_CIRCUIT, self::CIRCUIT_CLOSED);
    $failure_count = (int) $this->state->get(self::STATE_FAILURES, 0);
    $last_failure_time = (int) $this->state->get(self::STATE_LAST_FAIL, 0);

    if ($circuit_state === self::CIRCUIT_OPEN) {
      if ($current_time < $last_failure_time + self::RESET_TIMEOUT) {
        $this->logger->warning('HA circuit OPEN; failing fast for @domain.@service', [
          '@domain' => $domain,
          '@service' => $service,
        ]);
        return ['http_status' => 503, 'body' => 'Home Assistant circuit breaker is OPEN.'];
      }
      $circuit_state = self::CIRCUIT_HALF_OPEN;
      $this->state->set(self::STATE_CIRCUIT, $circuit_state);
    }

    $config = $this->configFactory->get('access_request.settings');
    $timeout = (int) ($config->get('home_assistant.timeout_seconds') ?? 5);
    if ($timeout <= 0) {
      $timeout = 5;
    }

    $url = sprintf('%s/api/services/%s/%s', $base_url, rawurlencode($domain), rawurlencode($service));
    $payload = $data;
    if (!empty($target)) {
      $payload['target'] = $target;
    }

    $start = microtime(TRUE);
    $http_status = 500;
    $body = '';
    $circuit_failure = FALSE;

    try {
      $response = $this->httpClient->request('POST', $url, [
        'headers' => [
          'Authorization' => 'Bearer ' . $token,
          'Content-Type' => 'application/json',
          'Accept' => 'application/json',
        ],
        'body' => json_encode($payload ?: new \stdClass()),
        'timeout' => $timeout,
        'http_errors' => FALSE,
      ]);
      $http_status = $response->getStatusCode();
      $body = (string) $response->getBody();
      if ($http_status >= 500) {
        $circuit_failure = TRUE;
      }
    }
    catch (\GuzzleHttp\Exception\RequestException $e) {
      $http_status = $e->getResponse() ? $e->getResponse()->getStatusCode() : 500;
      $body = $e->getResponse() ? (string) $e->getResponse()->getBody() : $e->getMessage();
      $circuit_failure = !$e->getResponse() || $http_status >= 500;
    }
    catch (\Throwable $e) {
      $http_status = 500;
      $body = $e->getMessage();
      $circuit_failure = TRUE;
    }

    $latency = microtime(TRUE) - $start;

    if (!$circuit_failure) {
      $this->state->set(self::STATE_FAILURES, 0);
      $this->state->set(self::STATE_CIRCUIT, self::CIRCUIT_CLOSED);
    }
    else {
      $failure_count++;
      $this->state->set(self::STATE_FAILURES, $failure_count);
      if ($failure_count >= self::FAILURE_THRESHOLD) {
        $this->state->set(self::STATE_CIRCUIT, self::CIRCUIT_OPEN);
        $this->state->set(self::STATE_LAST_FAIL, $current_time);
        $this->logger->error('HA circuit opened after @n failures (last @domain.@service).', [
          '@n' => $failure_count,
          '@domain' => $domain,
          '@service' => $service,
        ]);
      }
    }

    $this->logger->info('HA call: @domain.@service http_status=@status latency=@lat', [
      '@domain' => $domain,
      '@service' => $service,
      '@status' => $http_status,
      '@lat' => $latency,
    ]);

    return ['http_status' => $http_status, 'body' => $body];
  }

  /**
   * Reads the bearer token from the environment.
   *
   * Pantheon injects site secrets as env vars; on other hosts, the admin is
   * expected to provide HA_BEARER_TOKEN via the server environment.
   */
  protected function readToken(): string {
    $value = getenv(self::TOKEN_ENV_VAR);
    if ($value === FALSE || $value === NULL) {
      return '';
    }
    return trim((string) $value);
  }

}
