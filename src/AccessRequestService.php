<?php

namespace Drupal\access_request;

use Drupal\Core\Config\ConfigFactoryInterface;
use Psr\Log\LoggerInterface;
use GuzzleHttp\ClientInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Component\Uuid\UuidInterface;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\Yaml\Yaml;

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

  /**
   * Constructs an AccessRequestService object.
   */
  public function __construct(ConfigFactoryInterface $config_factory, \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory, ClientInterface $http_client, AccountInterface $current_user, EntityTypeManagerInterface $entity_type_manager, UuidInterface $uuid) {
    $this->config = $config_factory->get('access_request.settings');
    $this->logger = $logger_factory->get('access_request');
    $this->httpClient = $http_client;
    $this->currentUser = $current_user;
    $this->entityTypeManager = $entity_type_manager;
    $this->uuid = $uuid;
  }

  /**
   * Performs the access request.
   */
  public function performAccessRequest($asset_identifier, $method, $source = NULL) {
    $card_id = $this->fetchCardIdForUser($this->currentUser->id());

    if (empty($source)) {
      $source = preg_replace('/reader$/', '', $asset_identifier);
    }

    $door_map_yaml = $this->config->get('door_map');
    $door_map = Yaml::parse($door_map_yaml) ?? [];
    $permission_id = $door_map[$asset_identifier] ?? $asset_identifier;

    $payload_array = [
      'uid' => $this->currentUser->id(),
      'email' => $this->currentUser->getEmail(),
      'card_serial' => $card_id ?? '',
      'asset_identifier' => $asset_identifier,
      'reader_name' => $asset_identifier,
      'permission_id' => $permission_id,
      'source' => $source,
      'method' => $method,
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
      $profiles = $profile_storage->loadByProperties([
        'uid'  => $uid,
        'type' => 'main',
      ]);

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
   * Sends the request to the gateway.
   */
  protected function sendRequest(array $payload_array) {
    $url = $this->config->get('python_gateway_url');
    $timeout = $this->config->get('timeout_seconds');
    $hmac_secret = $this->config->get('web_hmac_secret');
    $request_id = $this->uuid->generate();

    $headers = ['Content-Type' => 'application/json'];
    $payload = json_encode($payload_array);

    if (!empty($hmac_secret)) {
      $signature = hash_hmac('sha256', $payload, $hmac_secret);
      $headers['X-Signature'] = 'sha256=' . $signature;
    }

    $start_time = microtime(TRUE);

    if ($this->config->get('dry_run')) {
      $this->logger->info('Dry-run mode enabled. Would have sent payload: @payload', ['@payload' => $payload]);
      return ['status' => 'success'];
    }

    $log_context = [
      '@request_id' => $request_id,
      '@uid' => $payload_array['uid'],
      '@email' => $payload_array['email'],
      '@card_serial' => $payload_array['card_serial'],
      '@asset_id' => $payload_array['asset_identifier'],
      '@permission_id' => $payload_array['permission_id'],
    ];

    try {
      $response = $this->httpClient->request('POST', $url, [
        'headers' => $headers,
        'body' => $payload,
        'timeout' => $timeout,
      ]);

      $latency = microtime(TRUE) - $start_time;
      $response_body = $response->getBody()->getContents();
      $http_status = $response->getStatusCode();

      if (strpos($response_body, 'Card accepted') !== FALSE) {
        $result = 'allowed';
        $reason = '';
        $return_value = ['status' => 'success'];
      } else {
        $result = 'denied';
        $reason = $response_body;
        $return_value = ['status' => 'denied', 'reason' => $reason];
      }
    }
    catch (\Exception $e) {
      $latency = microtime(TRUE) - $start_time;
      $result = 'error';
      $reason = $e->getMessage();
      $http_status = 0;
      $return_value = ['status' => 'error', 'reason' => 'An unexpected error occurred.'];

      if ($e instanceof RequestException && $e->hasResponse()) {
        $response = $e->getResponse();
        $http_status = $response->getStatusCode();
        $response_body = (string) $response->getBody();
        $json_response = json_decode($response_body, TRUE);
        if (json_last_error() === JSON_ERROR_NONE) {
          $reason = 'Server error. Response: ' . print_r($json_response, TRUE);
        }
        else {
          $reason = 'Server error. Raw response: ' . $response_body;
        }
        $return_value['reason'] = $reason;
      }
    }

    $log_context['@http_status'] = $http_status;
    $log_context['@latency'] = $latency;
    $log_context['@result'] = $result;
    $log_context['@reason'] = $reason;

    $this->logger->info(
      'Access request: request_id=@request_id, uid=@uid, email=@email, card_serial=@card_serial, asset_id=@asset_id, permission_id=@permission_id, http_status=@http_status, latency=@latency, result=@result, reason=@reason',
      $log_context
    );

    return $return_value;
  }
}
