<?php

namespace Drupal\access_request\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Url;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\access_request\AccessRequestService;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Routing\RouteMatchInterface;

/**
 * Access request form that auto-submits and restores legacy reader naming.
 */
class AccessRequestForm extends FormBase implements ContainerInjectionInterface {

  /** @var \Drupal\Core\Config\ImmutableConfig */
  protected $config;

  /** @var \Drupal\access_request\AccessRequestService */
  protected $accessRequestService;

  /** @var \Drupal\Core\Flood\FloodInterface */
  protected $flood;

  /** @var \Drupal\Core\Session\AccountInterface */
  protected $currentUser;

  /** @var \Drupal\Core\Routing\RouteMatchInterface */
  protected $routeMatch;

  public function __construct(
    ConfigFactoryInterface $config_factory,
    AccessRequestService $access_request_service,
    FloodInterface $flood,
    AccountInterface $current_user,
    RouteMatchInterface $route_match
  ) {
    $this->config = $config_factory->get('access_request.settings');
    $this->accessRequestService = $access_request_service;
    $this->flood = $flood;
    $this->currentUser = $current_user;
    $this->routeMatch = $route_match;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('access_request.service'),
      $container->get('flood'),
      $container->get('current_user'),
      $container->get('current_route_match')
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
   *
   * @param string|null $asset_identifier
   */
  public function buildForm(array $form, FormStateInterface $form_state, $asset_identifier = NULL) {
    // Blocked user check (optional field name in config).
    $user_block_field = $this->config->get('user_block_field');
    if ($user_block_field) {
      /** @var \Drupal\user\UserInterface $user */
      $user = \Drupal\user\Entity\User::load($this->currentUser->id());
      if ($user && $user->hasField($user_block_field) && (bool) $user->get($user_block_field)->value) {
        $this->messenger()->addError($this->t('Your access to this system has been revoked. Please contact an administrator.'));
        return [];
      }
    }

    // Require login.
    if ($this->currentUser->isAnonymous()) {
      $url = Url::fromRoute('user.login', [], [
        'query' => ['destination' => $this->getRequest()->getRequestUri()],
      ]);
      return new RedirectResponse($url->toString());
    }

    // Ensure the user has a card on file.
    $card_id = $this->accessRequestService->fetchCardIdForUser($this->currentUser->id());
    if (empty($card_id)) {
      $this->messenger()->addError($this->t('No card found associated with your account. Please contact support.'));
      return [];
    }

    // Auto-submit on first load.
    if (!$form_state->isSubmitted()) {
      $this->submitForm($form, $form_state);
    }

    // Fallback manual resubmit button.
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
    // Rate limiting.
    if (!$this->flood->isAllowed('access_request.form_submit', 10, 60)) {
      $this->messenger()->addError($this->t('You have made too many requests in a short time. Please try again later.'));
      return;
    }

    // Resolve asset identifier from route parameter.
    $asset_identifier = $this->routeMatch->getParameter('asset_identifier');
    if (!$asset_identifier || !is_string($asset_identifier)) {
      $this->messenger()->addError($this->t('Bad request: missing asset identifier.'));
      return;
    }

    // Legacy behavior: append "reader" unless it already ends with "reader".
    $reader_name = $asset_identifier;
    if (!preg_match('/reader$/', $reader_name)) {
      $reader_name .= 'reader';
    }

    // Permission policy: mirror asset id like before.
    // (Change to 'door' if you want a single shared door badge.)
    $permission_id = $asset_identifier;

    // Fetch user + card info.
    $uid = (int) $this->currentUser->id();
    $email = method_exists($this->currentUser, 'getEmail') ? (string) $this->currentUser->getEmail() : '';

    $card_id = $this->accessRequestService->fetchCardIdForUser($uid);
    if (empty($card_id)) {
      $this->messenger()->addError($this->t('No card found associated with your account. Please contact support.'));
      return;
    }

    // Endpoint from config; fallback to dev if not set.
    $endpoint = (string) ($this->config->get('endpoint_url') ?? '');
    if ($endpoint === '') {
      $endpoint = 'https://server.dev.access.makehaven.org/toolauth/req';
    }

    // Build legacy-style payload (richer for logging and clarity).
    $payload = [
      'reader_name'   => $reader_name,
      'card_id'       => $card_id,
      'uid'           => $uid,
      'email'         => $email,
      'asset_id'      => $asset_identifier,
      'permission_id' => $permission_id,
      'source'        => 'website',
      'method'        => 'website',
    ];

    try {
      $client = \Drupal::httpClient();
      $res = $client->post($endpoint, [
        'headers' => [
          'Accept' => 'text/plain',
          'Content-Type' => 'application/json',
        ],
        'json' => $payload,
        'http_errors' => FALSE,
        'timeout' => 8,
      ]);

      $code = $res->getStatusCode();
      $this->flood->register('access_request.form_submit', 60);

      switch ($code) {
        case 201:
          $this->messenger()->addStatus($this->t('Card accepted. Door/tool enabled.'));
          break;

        case 403:
          $this->messenger()->addError($this->t('Access denied: missing required badge/permission.'));
          break;

        case 401:
          $this->messenger()->addError($this->t('Unauthorized card format or number.'));
          break;

        case 400:
          $this->messenger()->addError($this->t('Bad request: unknown reader. Check the QR’s reader key or server config.'));
          break;

        default:
          $this->messenger()->addError($this->t('Access system error (HTTP @c). Try again or contact an admin.', ['@c' => $code]));
          break;
      }
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Access system error. Please try again or contact an admin.'));
      \Drupal::logger('access_request')->error('Access request failed: @m', ['@m' => $e->getMessage()]);
    }

    // Redirect to the front page after showing the message.
    $form_state->setRedirect('<front>');
  }
}
