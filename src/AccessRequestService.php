<?php

namespace Drupal\access_request;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Component\Uuid\UuidInterface;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\State\StateInterface;

/**
 * Service for handling access requests.
 */
class AccessRequestService {

  // Circuit breaker states.
  const CIRCUIT_CLOSED = 'closed';
  const CIRCUIT_OPEN = 'open';
  const CIRCUIT_HALF_OPEN = 'half_open';

  // Circuit breaker configuration (can be made configurable in settings).
  const FAILURE_THRESHOLD = 3; // Number of consecutive failures before opening the circuit.
  const RESET_TIMEOUT = 60;    // Time in seconds to stay in open state before attempting half-open.

  protected $config;
  protected $logger;
  protected $httpClient;
  protected $currentUser;
  protected $entityTypeManager;
  protected $uuid;
  protected $requestStack;
  protected $routeMatch;
  protected StateInterface $state;

  /**
   * Constructs an AccessRequestService object.
   */
  public function __construct(ConfigFactoryInterface $config_factory, LoggerChannelFactoryInterface $logger_factory, ClientInterface $http_client, AccountInterface $current_user, EntityTypeManagerInterface $entity_type_manager, UuidInterface $uuid, RequestStack $request_stack, RouteMatchInterface $route_match, StateInterface $state) {
    $this->config = $config_factory->get('access_request.settings');
    $this->logger = $logger_factory->get('access_request');
    $this->httpClient = $http_client;
    $this->currentUser = $current_user;
    $this->entityTypeManager = $entity_type_manager;
    $this->uuid = $uuid;
    $this->requestStack = $request_stack;
    $this->routeMatch = $route_match;
    $this->state = $state;
  }

  /**
   * Performs the access request.
   */
  public function performAccessRequest() {
    // --- BEGIN: normalize asset & resolve reader/permission consistently ---

    // Route param from /access-request/asset/{asset}
    $incoming = (string) $this->routeMatch->getParameter('asset');   // e.g., "storedoorreader" or "storedoor"

    // Parse asset_map YAML from config.
    $map = [];
    $asset_map_yaml = (string) ($this->config->get('asset_map') ?? '');
    if ($asset_map_yaml !== '') {
      try {
        $parsed = \Symfony\Component\Yaml\Yaml::parse($asset_map_yaml);
        if (is_array($parsed)) { $map = $parsed; }
      } catch (\Throwable $e) {
        // non-fatal; leave $map empty
      }
    }

    // 1) Normalize to a known asset key:
    //    - prefer exact match
    //    - else if incoming ends with 'reader', strip it and try again
    $asset_key = $incoming;
    if (!array_key_exists($asset_key, $map)) {
      if (preg_match('/reader$/', $asset_key)) {
        $maybe = preg_replace('/reader$/', '', $asset_key);
        if (is_string($maybe) && array_key_exists($maybe, $map)) {
          $asset_key = $maybe; // e.g., "storedoorreader" -> "storedoor"
        }
      }
    }

    // 2) Reader resolution precedence:
    //    a) asset_map[asset_key].reader_name (explicit override)
    //    b) asset_key, with 'reader' suffix appended if not present
    if (isset($map[$asset_key]['reader_name']) && is_string($map[$asset_key]['reader_name'])) {
      // a) Explicit override from map. Use it as-is.
      $reader_name = $map[$asset_key]['reader_name'];
    } else {
      // b) Default legacy behavior: asset key + suffix if needed.
      $reader_name = $asset_key;
      if (!preg_match('/reader$/', $reader_name)) {
        $reader_name .= 'reader';
      }
    }

    // 3) Permission resolution precedence:
    //    a) asset_map[asset_key].permission_id
    //    b) category === 'doors' -> 'door' (shared badge default)
    //    c) fallback to asset_key
    $permission_id = $asset_key;
    if (isset($map[$asset_key]['permission_id']) && is_string($map[$asset_key]['permission_id'])) {
      $permission_id = $map[$asset_key]['permission_id'];
    } elseif ((string) ($map[$asset_key]['category'] ?? '') === 'doors') {
      $permission_id = 'door';
    }

    // 4) For logging clarity (what the user originally hit vs normalized)
    $asset_id = $asset_key;  // what we consider the canonical asset now

    // --- END: normalize asset & resolve reader/permission consistently ---

    $card_id = $this->fetchCardIdForUser($this->currentUser->id());
    $request = $this->requestStack->getCurrentRequest();
    $method = $request->query->get('method', 'website');

    $payload_array = [
      'reader_name'   => $reader_name,
      'card_id'       => $card_id,
      'uid'           => $this->currentUser->id(),
      'email'         => $this->currentUser->getEmail(),
      'asset_id'      => $asset_id,
      'permission_id' => $permission_id,
      'source'        => 'website',
      'method'        => $method,
    ];

    return $this->sendRequest($payload_array);
  }

  /**
   * Fetches the card ID for a user.
   */
  public function fetchCardIdForUser($uid) {
    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    if ($user && $user->hasField('field_card_serial_number') && !empty($user->get('field_card_serial_number')->value)) {
      return $user->get('field_card_serial_number')->value;
    }

    $profile_storage = $this->entityTypeManager->getStorage('profile');
    if ($profile_storage) {
      $profiles = $profile_storage->loadByProperties(['uid' => $uid, 'type' => 'main']);
      if ($profiles) {
        $profile = reset($profiles);
        if ($profile && $profile->hasField('field_card_serial_number') && !empty($profile->get('field_card_serial_number')->value)) {
          return $profile->get('field_card_serial_number')->value;
        }
      }
    }

    return NULL;
  }

  /**
   * Gets the permission ID for an asset.
   */
  protected function getPermissionId($asset_id) {
    $asset_map_yaml = $this->config->get('asset_map');
    $asset_map = Yaml::parse((string) $asset_map_yaml) ?? [];
    return $asset_map[$asset_id]['permission_id'] ?? $asset_id;
  }

  /**
   * Sends the request to the gateway.
   */
  protected function sendRequest(array $payload_array) {
    $url = $this->config->get('python_gateway_url');
    $timeout = $this->config->get('timeout_seconds');
    $hmac_secret = $this->config->get('web_hmac_secret');
    $request_id = $this->uuid->generate();
    $current_time = \Drupal::time()->getRequestTime();

    // Circuit breaker state management.
    $circuit_state_key = 'access_request.circuit_state';
    $failure_count_key = 'access_request.failure_count';
    $last_failure_time_key = 'access_request.last_failure_time';

    $circuit_state = $this->state->get($circuit_state_key, self::CIRCUIT_CLOSED);
    $failure_count = $this->state->get($failure_count_key, 0);
    $last_failure_time = $this->state->get($last_failure_time_key, 0);

    // Check circuit state before making request.
    if ($circuit_state === self::CIRCUIT_OPEN) {
      if ($current_time < $last_failure_time + self::RESET_TIMEOUT) {
        // Circuit is open, and reset timeout has not passed. Fail fast.
        $this->logger->warning('Circuit breaker is OPEN. Failing fast for external request. Request ID: @request_id', ['@request_id' => $request_id]);
        return ['http_status' => 503, 'body' => 'Circuit breaker is OPEN. External service unavailable.'];
      }
      else {
        // Reset timeout has passed. Transition to HALF_OPEN.
        $circuit_state = self::CIRCUIT_HALF_OPEN;
        $this->state->set($circuit_state_key, $circuit_state);
        $this->logger->notice('Circuit breaker transitioned to HALF_OPEN. Attempting request. Request ID: @request_id', ['@request_id' => $request_id]);
      }
    }

    $headers = ['Content-Type' => 'application/json'];
    // The gateway only requires reader_name and card_id.
    $authoritative_payload = [
      'reader_name' => $payload_array['reader_name'],
      'card_id' => $payload_array['card_id'],
    ];
    $payload_for_gateway = json_encode($authoritative_payload);

    if (!empty($hmac_secret)) {
      $signature = hash_hmac('sha256', $payload_for_gateway, $hmac_secret);
      $headers['X-Signature'] = 'sha256=' . $signature;
    }

    $start_time = microtime(TRUE);

    if ($this->config->get('dry_run')) {
      $this->logger->info('Dry-run mode enabled. Would have sent payload: @payload', ['@payload' => json_encode($payload_array)]);
      return ['http_status' => 201, 'body' => 'Dry run: Card accepted'];
    }

    $log_context = [
      '@request_id' => $request_id,
      '@uid' => $payload_array['uid'],
      '@email' => $payload_array['email'],
      '@card_id' => $payload_array['card_id'],
      '@asset_id' => $payload_array['asset_id'],
      '@permission_id' => $payload_array['permission_id'],
      '@reader_name' => $payload_array['reader_name'],
    ];

    $success = FALSE;
    $circuit_failure = FALSE;
    $http_status = 500;
    $response_body = '';

    try {
      $response = $this->httpClient->request('POST', $url, [
        'headers' => $headers,
        'body' => $payload_for_gateway,
        'timeout' => $timeout,
        'http_errors' => false, // We want to handle 4xx/5xx responses ourselves.
      ]);

      $latency = microtime(TRUE) - $start_time;
      $http_status = $response->getStatusCode();
      $response_body = $response->getBody()->getContents();
      $result = ($http_status >= 200 && $http_status < 300) ? 'allowed' : 'denied';

      if ($http_status >= 200 && $http_status < 300) {
        $success = TRUE;
      }
      elseif ($http_status >= 500) {
        // Upstream service/server failure.
        $circuit_failure = TRUE;
      }
    } catch (\GuzzleHttp\Exception\RequestException $e) {
      $latency = microtime(TRUE) - $start_time;
      $result = 'error';
      $http_status = $e->getResponse() ? $e->getResponse()->getStatusCode() : 500;
      $response_body = $e->getResponse() ? (string) $e->getResponse()->getBody() : $e->getMessage();
      // Network/transport failures and 5xx responses indicate upstream health
      // issues and should contribute to opening the circuit.
      $circuit_failure = !$e->getResponse() || $http_status >= 500;
    } catch (\Throwable $e) {
      $latency = microtime(TRUE) - $start_time;
      $result = 'error';
      $http_status = 500;
      $response_body = $e->getMessage();
      $circuit_failure = TRUE;
    }

    // Update circuit breaker state based on request outcome.
    if (!$circuit_failure) {
      $this->state->set($failure_count_key, 0);
      $this->state->set($circuit_state_key, self::CIRCUIT_CLOSED);
      if ($success) {
        $this->logger->info('Circuit breaker is CLOSED. Request ID: @request_id', ['@request_id' => $request_id]);
      }
    }
    else {
      $failure_count++;
      $this->state->set($failure_count_key, $failure_count);
      if ($failure_count >= self::FAILURE_THRESHOLD) {
        $this->state->set($circuit_state_key, self::CIRCUIT_OPEN);
        $this->state->set($last_failure_time_key, $current_time);
        $this->logger->error('Circuit breaker opened due to @count consecutive failures. Request ID: @request_id', ['@count' => $failure_count, '@request_id' => $request_id]);
      }
      else {
        $this->state->set($circuit_state_key, self::CIRCUIT_CLOSED); // Remain closed until threshold.
        $this->logger->warning('Circuit breaker failure count: @count. Request ID: @request_id', ['@count' => $failure_count, '@request_id' => $request_id]);
      }
    }

    $log_context['@http_status'] = $http_status;
    $log_context['@latency'] = $latency;
    $log_context['@result'] = $result;
    $log_context['@reason'] = $response_body;

    $this->logger->info(
      'Access request: request_id=@request_id, uid=@uid, email=@email, card_id=@card_id, asset_id=@asset_id, permission_id=@permission_id, reader_name=@reader_name, http_status=@http_status, latency=@latency, result=@result, reason=@reason',
      $log_context
    );

    return ['http_status' => $http_status, 'body' => $response_body];
  }

  /**
   * Forwards externally sourced access checks through the gateway pipeline.
   *
   * @param array $payload_array
   *   Expected keys include reader_name, card_id, uid, email, asset_id,
   *   permission_id, source, and method.
   *
   * @return array
   *   Gateway response array with at least http_status and body.
   */
  public function forwardExternalRequest(array $payload_array): array {
    return $this->sendRequest($payload_array);
  }
}
