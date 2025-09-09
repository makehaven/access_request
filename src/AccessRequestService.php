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

/**
 * Service for handling access requests.
 */
class AccessRequestService {

  protected $config;
  protected $logger;
  protected $httpClient;
  protected $currentUser;
  protected $entityTypeManager;
  protected $uuid;
  protected $requestStack;
  protected $routeMatch;

  /**
   * Constructs an AccessRequestService object.
   */
  public function __construct(ConfigFactoryInterface $config_factory, LoggerChannelFactoryInterface $logger_factory, ClientInterface $http_client, AccountInterface $current_user, EntityTypeManagerInterface $entity_type_manager, UuidInterface $uuid, RequestStack $request_stack, RouteMatchInterface $route_match) {
    $this->config = $config_factory->get('access_request.settings');
    $this->logger = $logger_factory->get('access_request');
    $this->httpClient = $http_client;
    $this->currentUser = $current_user;
    $this->entityTypeManager = $entity_type_manager;
    $this->uuid = $uuid;
    $this->requestStack = $request_stack;
    $this->routeMatch = $route_match;
  }

  /**
   * Performs the access request.
   */
  public function performAccessRequest() {
    $request = $this->requestStack->getCurrentRequest();
    $asset_id = $this->routeMatch->getParameter('asset');
    $method = $request->query->get('method', 'qr');

    $asset_map_yaml = $this->config->get('asset_map');
    $asset_map = Yaml::parse((string) $asset_map_yaml) ?? [];

    // Prioritize reader_name from map, but fall back to asset_id.
    $readerKey = $asset_map[$asset_id]['reader_name'] ?? $asset_id;

    // Normalize to always have 'reader' suffix.
    if (!preg_match('/reader$/', (string) $readerKey)) {
      $readerKey .= 'reader';
    }

    $card_id = $this->fetchCardIdForUser($this->currentUser->id());

    $payload_array = [
      'reader_name' => $readerKey,
      'card_id' => $card_id ?? '',
      // Optional metadata for logs.
      'uid' => $this->currentUser->id(),
      'email' => $this->currentUser->getEmail(),
      'asset_id' => $asset_id,
      'permission_id' => $this->getPermissionId($asset_id),
      'source' => $method,
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
      $result = ($http_status === 201) ? 'allowed' : 'denied';
    }
    catch (\Exception $e) {
      $latency = microtime(TRUE) - $start_time;
      $result = 'error';
      $http_status = 0;
      $response_body = $e->getMessage();
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
}
