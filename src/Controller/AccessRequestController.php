<?php
namespace Drupal\access_request\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Url;
use Symfony\Component\Yaml\Yaml;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\ClientInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\Exception\RequestException;
use Drupal\access_request\AccessRequestService;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Render\Markup;
class AccessRequestController extends ControllerBase {
  protected $config;
  protected $httpClient;
  protected $logger;
  protected $accessRequestService;
  protected $flood;
  protected $currentUser;
  protected $entityTypeManager;
  protected $renderer;
  protected $messenger;

  public function __construct(ConfigFactoryInterface $config_factory, ClientInterface $http_client, LoggerChannelFactoryInterface $logger_factory, AccessRequestService $access_request_service, FloodInterface $flood, AccountInterface $current_user, EntityTypeManagerInterface $entity_type_manager, RendererInterface $renderer, MessengerInterface $messenger) {
    $this->config = $config_factory->get('access_request.settings');
    $this->httpClient = $http_client;
    $this->logger = $logger_factory->get('access_request');
    $this->accessRequestService = $access_request_service;
    $this->flood = $flood;
    $this->currentUser = $current_user;
    $this->entityTypeManager = $entity_type_manager;
    $this->renderer = $renderer;
    $this->messenger = $messenger;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('http_client'),
      $container->get('logger.factory'),
      $container->get('access_request.service'),
      $container->get('flood'),
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('renderer'),
      $container->get('messenger')
    );
  }

  public function proxyRequest() {
    $url = $this->config->get('python_gateway_url');
    $data = file_get_contents('php://input');

    try {
      $response = $this->httpClient->post($url, [
        'headers' => ['Content-Type' => 'application/json'],
        'body' => $data,
        'http_errors' => false,
      ]);

      return new JsonResponse(json_decode($response->getBody()->getContents(), TRUE), $response->getStatusCode());
    }
    catch (\Exception $e) {
      $this->logger->error('Proxy request failed with exception: @error', ['@error' => $e->getMessage()]);
      return new JsonResponse(['success' => false, 'message' => 'Error sending the request.'], 500);
    }
  }

  public function healthCheck() {
    $gateway_url = $this->config->get('python_gateway_url');
    $build = [];

    if (empty($gateway_url)) {
      $build['#markup'] = $this->t('The Python Gateway URL is not configured. Please configure it in the Access Request settings.');
      return $build;
    }

    $health_check_url = str_replace('/toolauth/req', '/health', $gateway_url);

    try {
      $response = $this->httpClient->get($health_check_url, ['http_errors' => false]);
      $status = $response->getStatusCode();
      $response_body = $response->getBody()->getContents();

      if ($status == 200) {
        $build['#markup'] = $this->t('Health check successful.<br>Status: @status<br>Response: @response', [
          '@status' => $status,
          '@response' => $response_body,
        ]);
      } else {
        $build['#markup'] = $this->t('Health check failed.<br>Status: @status<br>Response: @response', [
          '@status' => $status,
          '@response' => $response_body,
        ]);
      }
    }
    catch (\Exception $e) {
      $build['#markup'] = $this->t('Health check failed with an exception.<br>Error: @error', [
        '@error' => $e->getMessage(),
      ]);
    }

    return $build;
  }

  public function listAssets($category = NULL) {
    $asset_map_yaml = $this->config->get('asset_map');
    $build = [];

    if (empty($asset_map_yaml)) {
      $build['#markup'] = $this->t('No assets have been configured.');
      return $build;
    }

    try {
      $asset_map = Yaml::parse($asset_map_yaml);
    }
    catch (\Exception $e) {
      $this->logger->error('Error parsing asset map YAML: @error', ['@error' => $e->getMessage()]);
      $build['#markup'] = $this->t('There was an error parsing the asset map configuration.');
      return $build;
    }

    if (empty($asset_map) || !is_array($asset_map)) {
        $build['#markup'] = $this->t('No assets have been configured or the format is incorrect.');
        return $build;
    }

    $assets = [];
    foreach ($asset_map as $asset_id => $asset_info) {
      if (!$category || (isset($asset_info['category']) && $asset_info['category'] === $category)) {
        $assets[$asset_id] = $asset_info;
        $assets[$asset_id]['url'] = Url::fromRoute('access_request.asset', ['asset' => $asset_id], ['query' => ['method' => 'website']]);
      }
    }

    $build['asset_list'] = [
      '#theme' => 'access_request_asset_cards',
      '#assets' => $assets,
      '#title' => $this->t('Available Assets'),
    ];

    return $build;
  }
  public function initiateRequest($asset) {
    // Rate limiting.
    if (!$this->flood->isAllowed('access_request.form_submit', 10, 60)) {
      $this->messenger->addError($this->t('You have made too many requests in a short time. Please try again later.'));
      return new RedirectResponse(Url::fromRoute('access_request.asset', ['asset' => $asset])->toString());
    }

    // The service gets the asset from the route, so we don't need to resolve
    // it here. The service also handles all normalization, payload creation,
    // and the actual HTTP request. This centralizes the logic.
    $result = $this->accessRequestService->performAccessRequest();

    // The service returns the status code and body.
    $code = $result['http_status'];
    $body = $result['body'];

    // The service respects dry_run mode, so we just need to handle the result.
    if ($code === 201) {
      $this->messenger->addStatus($this->t('Card accepted. Door/tool enabled.'));
    }
    else {
      $user = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());
      $denial_reason_found = FALSE;

      if ($user) {
        // Check for denial reasons in the specified priority order.
        if ($user->hasField('field_access_override') && $user->get('field_access_override')->value === 'deny') {
          $this->displayDenialMessage('override_message', 'Your access has been manually overridden by staff.');
          $denial_reason_found = TRUE;
        }
        elseif ($user->hasField('field_manual_pause') && (bool) $user->get('field_manual_pause')->value) {
          $this->displayDenialMessage('manual_pause_message', 'Your account has been manually paused.');
          $denial_reason_found = TRUE;
        }
        elseif ($user->hasField('field_payment_failed') && (bool) $user->get('field_payment_failed')->value) {
          $this->displayDenialMessage('unpaid_message', 'Your account has been flagged as unpaid. Please update your payment information.');
          $denial_reason_found = TRUE;
        }
        elseif ($user->hasField('field_chargebee_payment_pause') && (bool) $user->get('field_chargebee_payment_pause')->value) {
          $this->displayDenialMessage('chargebee_pause_message', 'Your account has been paused due to a payment issue.');
          $denial_reason_found = TRUE;
        }
        elseif (!$user->hasRole('member')) {
          $this->displayDenialMessage('no_member_role_message', 'Access denied. You must be an active member.');
          $denial_reason_found = TRUE;
        }
      }

      if (!$denial_reason_found) {
        $default_message = $this->config->get('default_denial_message');
        if (!empty($default_message)) {
          $this->displayDenialMessage('default_denial_message', 'Access Denied.');
        }
        else {
          $this->messenger->addError($this->t('Access system error (HTTP @c): @m', ['@c' => $code, '@m' => mb_substr($body, 0, 300)]));
        }
      }
    }

    // Register the flood event after the attempt.
    $this->flood->register('access_request.form_submit', 60);

    // Redirect to the manual request page.
    $url = Url::fromRoute('access_request.asset', ['asset' => $asset]);
    return new RedirectResponse($url->toString());
  }

  /**
   * Displays a configured denial message.
   *
   * @param string $message_key
   *   The configuration key for the message to display.
   * @param string $default_message
   *   The default message to show if the configured one is empty.
   */
  protected function displayDenialMessage($message_key, $default_message) {
    $message_text = $this->config->get($message_key);

    if (empty($message_text)) {
      $this->messenger->addError($this->t($default_message));
      return;
    }

    $payment_portal_url = $this->config->get('payment_portal_url');
    $button = '';

    if (!empty($payment_portal_url) && strpos($message_text, '[payment_portal_button]') !== false) {
      if (filter_var($payment_portal_url, FILTER_VALIDATE_URL)) {
        $url = Url::fromUri($payment_portal_url);
      }
      else {
        $url = Url::fromUserInput($payment_portal_url);
      }
      $button_link = [
        '#type' => 'link',
        '#title' => $this->t('Update Payment Information'),
        '#url' => $url,
        '#attributes' => [
          'class' => ['button', 'button--primary'],
        ],
      ];
      $button = $this->renderer->render($button_link);
    }

    $final_message = str_replace('[payment_portal_button]', $button, $message_text);
    $this->messenger->addError(Markup::create($final_message));
  }
}
