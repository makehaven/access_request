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
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Render\Markup;

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

  /** @var \Drupal\Core\Entity\EntityTypeManagerInterface */
  protected $entityTypeManager;

  /** @var \Drupal\Core\Render\RendererInterface */
  protected $renderer;

  public function __construct(
    ConfigFactoryInterface $config_factory,
    AccessRequestService $access_request_service,
    FloodInterface $flood,
    AccountInterface $current_user,
    RouteMatchInterface $route_match,
    EntityTypeManagerInterface $entity_type_manager,
    RendererInterface $renderer
  ) {
    $this->config = $config_factory->get('access_request.settings');
    $this->accessRequestService = $access_request_service;
    $this->flood = $flood;
    $this->currentUser = $current_user;
    $this->routeMatch = $route_match;
    $this->entityTypeManager = $entity_type_manager;
    $this->renderer = $renderer;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('access_request.service'),
      $container->get('flood'),
      $container->get('current_user'),
      $container->get('current_route_match'),
      $container->get('entity_type.manager'),
      $container->get('renderer')
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
   * @param string|null $asset
   *   The asset identifier from the URL.
   */
  public function buildForm(array $form, FormStateInterface $form_state, $asset = NULL) {
    // If no asset is specified in the URL, redirect to the asset list page.
    if (empty($asset)) {
      $url = Url::fromRoute('access_request.list');
      return new RedirectResponse($url->toString());
    }

    // Store the asset for the submit handler.
    $form_state->set('asset', $asset);

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

    // The service gets the asset from the route, so we don't need to resolve
    // it here. The service also handles all normalization, payload creation,
    // and the actual HTTP request. This centralizes the logic.
    $result = $this->accessRequestService->performAccessRequest();

    // The service returns the status code and body.
    $code = $result['http_status'];
    $body = $result['body'];

    // The service respects dry_run mode, so we just need to handle the result.
    if ($code === 201) {
      $this->messenger()->addStatus($this->t('Card accepted. Door/tool enabled.'));
    }
    else {
      $user = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());
      if ($user && $user->hasField('field_payment_failed') && (bool) $user->get('field_payment_failed')->value) {
        $unpaid_message = $this->config->get('unpaid_message');

        if (!empty($unpaid_message)) {
          $payment_portal_url = $this->config->get('payment_portal_url');
          $button = '';

          if (!empty($payment_portal_url) && strpos($unpaid_message, '[payment_portal_button]') !== false) {
            $url = Url::fromUserInput($payment_portal_url);
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

          $message = str_replace('[payment_portal_button]', $button, $unpaid_message);
          $this->messenger()->addError(Markup::create($message));
        }
        else {
          $this->messenger()->addError($this->t('Your account has been flagged as unpaid. Please update your payment information.'));
        }
      }
      else {
        // Use the improved error message from the user's instructions.
        // The service log already contains the full details.
        $this->messenger()->addError($this->t('Access system error (HTTP @c): @m', ['@c' => $code, '@m' => mb_substr($body, 0, 300)]));
      }
    }

    // Register the flood event after the attempt.
    $this->flood->register('access_request.form_submit', 60);

    // No redirect; allow the page to reload so the user can see the message
    // and the resend button.
  }
}
