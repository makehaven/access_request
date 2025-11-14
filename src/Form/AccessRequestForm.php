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
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Render\Markup;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

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

  /** @var \Drupal\Core\Entity\EntityTypeManagerInterface */
  protected $entityTypeManager;

  /** @var \Drupal\Core\Render\RendererInterface */
  protected $renderer;

  public function __construct(
    ConfigFactoryInterface $config_factory,
    AccessRequestService $access_request_service,
    FloodInterface $flood,
    AccountInterface $current_user,
    EntityTypeManagerInterface $entity_type_manager,
    RendererInterface $renderer
  ) {
    $this->config = $config_factory->get('access_request.settings');
    $this->accessRequestService = $access_request_service;
    $this->flood = $flood;
    $this->currentUser = $current_user;
    $this->entityTypeManager = $entity_type_manager;
    $this->renderer = $renderer;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('access_request.service'),
      $container->get('flood'),
      $container->get('current_user'),
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
      $user = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());
      if ($user && $user->hasField($user_block_field) && (bool) $user->get($user_block_field)->value) {
        $message = $this->config->get('user_block_message') ?: $this->t('Your access to this system has been revoked. Please contact an administrator.');
        $this->messenger()->addError($message);
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

    // Auto-submit on first load, then forward to the manual form so the user
    // lands on a page with an explicit resend button instead of another
    // automatic submission.
    if (!$form_state->isSubmitted()) {
      $this->submitForm($form, $form_state);

      try {
        $manual_url = Url::fromRoute('access_request.manual', ['asset' => $asset]);
      }
      catch (RouteNotFoundException $e) {
        // Fall back to a direct path if routing metadata is stale.
        $manual_url = Url::fromUserInput("/access-request/manual/{$asset}");
      }
      return new RedirectResponse($manual_url->toString());
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
          $this->messenger()->addError($this->t('Access system error (HTTP @c): @m', ['@c' => $code, '@m' => mb_substr($body, 0, 300)]));
        }
      }
    }

    // Register the flood event after the attempt.
    $this->flood->register('access_request.form_submit', 60);

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
      $this->messenger()->addError($this->t($default_message));
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
    $this->messenger()->addError(Markup::create($final_message));
  }
}
