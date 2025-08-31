<?php

namespace Drupal\access_request\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Url;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;
use Drupal\Core\Flood\FloodInterface;

class AccessRequestForm extends FormBase implements ContainerInjectionInterface {

  protected $config;
  protected $httpClient;
  protected $flood;

  public function __construct(ConfigFactoryInterface $config_factory, ClientInterface $http_client, FloodInterface $flood) {
    $this->config = $config_factory->get('access_request.settings');
    $this->httpClient = $http_client;
    $this->flood = $flood;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('http_client'),
      $container->get('flood')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'access_request_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $asset_identifier = NULL) {
    $current_user = \Drupal::currentUser();
    $user_block_field = $this->config->get('user_block_field');

    if ($user_block_field) {
      $user = \Drupal\user\Entity\User::load($current_user->id());
      if ($user->hasField($user_block_field) && $user->get($user_block_field)->value) {
        \Drupal::messenger()->addError($this->t('Your access to this system has been revoked. Please contact an administrator.'));
        return [];
      }
    }

    if ($current_user->isAnonymous()) {
      // Redirect anonymous users to the login page with the destination URL.
      $url = Url::fromRoute('user.login', [], [
        'query' => ['destination' => "/access-request/asset/{$asset_identifier}"],
      ]);
      return new RedirectResponse($url->toString());
    }

    // Ensure the asset_identifier is provided.
    if (empty($asset_identifier)) {
      if (\Drupal::request()->getPathInfo() === '/access-request/asset') {
        \Drupal::messenger()->addError($this->t('No asset identifier provided in the URL. Please contact support.'));
      }
      return [];
    }

    // Fetch the card ID from the user's profile.
    $card_id = $this->fetchCardIdForUser();
    if (empty($card_id)) {
      \Drupal::messenger()->addError($this->t('No card found associated with your account. Please contact support.'));
      return [];
    }

    // Store asset_identifier, card_id, and method in form state.
    $form_state->set('asset_identifier', $asset_identifier);
    $form_state->set('card_id', $card_id);

    // Get the method from the query parameters, defaulting to 'website'.
    $method = \Drupal::request()->query->get('method', 'website');
    $form_state->set('method', $method);

    // Automatically submit the form on page load if not already submitted.
    if (!$form_state->has('submitted')) {
      $form_state->set('submitted', TRUE);
      $this->submitForm($form, $form_state);
    }

    // Provide a button to resend the request.
    $form['resend'] = [
      '#type' => 'submit',
      '#value' => $this->t('Resend Request'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Rate limiting: 10 requests per minute per user/IP.
    $limit = 10;
    $window = 60;
    if (!$this->flood->isAllowed('access_request.form_submit', $limit, $window)) {
      \Drupal::messenger()->addError($this->t('You have made too many requests in a short time. Please try again later.'));
      return;
    }

    // Retrieve asset_identifier, card_id, and method.
    $asset_identifier = $form_state->get('asset_identifier');
    $card_id = $form_state->get('card_id');
    $method = $form_state->get('method');

    if ($this->isValidAssetIdentifier($asset_identifier)) {
      // Derive the source by removing a trailing "reader".
      $source = preg_replace('/reader$/', '', $asset_identifier);

      // Prepare the payload with the extra parameters.
      $payload = json_encode([
        'card_id'          => $card_id,
        'asset_identifier' => $asset_identifier,
        'reader_name'      => $asset_identifier,
        'source'           => $source,
        'method'           => $method,
      ]);

      // Submit the access request and capture the result.
      $result = $this->requestAssetAccess($payload);

      switch ($result['status']) {
        case 'success':
          \Drupal::messenger()->addMessage($this->t('Access request sent.'));
          break;
        case 'denied':
          \Drupal::messenger()->addError($this->t('Access denied. Reason: @reason', ['@reason' => $result['reason']]));
          break;
        case 'error':
          \Drupal::messenger()->addError($this->t('Temporary error contacting the access server. Please try again.'));
          break;
      }
      $this->flood->register('access_request.form_submit', $window);
    }
    else {
      \Drupal::messenger()->addError($this->t('Invalid asset identifier provided. Please contact support.'));
    }
  }

  /**
   * Handles the asset access request.
   *
   * @param string $payload
   *   The JSON payload containing card_id, asset_identifier, source, and method.
   *
   * @return array
   *   An array containing the result message and status.
   */
  protected function requestAssetAccess($payload) {
    $url = $this->config->get('python_gateway_url');
    $timeout = $this->config->get('timeout_seconds');
    $hmac_secret = $this->config->get('web_hmac_secret');
    $request_id = \Drupal::service('uuid')->generate();
    $current_user = \Drupal::currentUser();
    $uid = $current_user->id();
    $payload_array = json_decode($payload, TRUE);
    $asset_id = $payload_array['asset_identifier'];

    $headers = [
      'Content-Type' => 'application/json',
    ];

    if (!empty($hmac_secret)) {
      $signature = hash_hmac('sha256', $payload, $hmac_secret);
      $headers['X-Signature'] = 'sha256=' . $signature;
    }

    $start_time = microtime(TRUE);

    if ($this->config->get('dry_run')) {
      \Drupal::logger('access_request')->info(
        'Dry-run mode enabled. Would have sent payload: @payload',
        ['@payload' => $payload]
      );
      return ['status' => 'success'];
    }

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
      }
      else {
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
      $return_value = ['status' => 'error', 'reason' => $reason];
    }

    \Drupal::logger('access_request')->info(
      'Access request: request_id=@request_id, uid=@uid, asset_id=@asset_id, http_status=@http_status, latency=@latency, result=@result, reason=@reason',
      [
        '@request_id' => $request_id,
        '@uid' => $uid,
        '@asset_id' => $asset_id,
        '@http_status' => $http_status,
        '@latency' => $latency,
        '@result' => $result,
        '@reason' => $reason,
      ]
    );

    return $return_value;
  }

  /**
   * Fetch the card ID associated with the current user.
   *
   * @return string|null
   *   The card ID or NULL if not found.
   */
  protected function fetchCardIdForUser() {
    $current_user = \Drupal::currentUser();
    $uid = $current_user->id();

    $user = \Drupal::entityTypeManager()->getStorage('user')->load($uid);
    $card_id = $user->get('field_card_serial_number')->value;

    if (empty($card_id)) {
      $profile_storage = \Drupal::entityTypeManager()->getStorage('profile');
      $profiles = $profile_storage->loadByProperties([
        'uid'  => $uid,
        'type' => 'main',
      ]);

      $profile = reset($profiles);
      if ($profile) {
        $card_id = $profile->get('field_card_serial_number')->value;
      }
    }

    return $card_id ?: NULL;
  }

  /**
   * Validate the asset identifier.
   *
   * @param string $asset_identifier
   *   The asset identifier to validate.
   *
   * @return bool
   *   TRUE if valid, FALSE otherwise.
   */
  protected function isValidAssetIdentifier($asset_identifier) {
    return !empty($asset_identifier) && preg_match('/^[a-zA-Z0-9_-]+$/', $asset_identifier) && strlen($asset_identifier) <= 25;
  }
}
