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

class AccessRequestForm extends FormBase implements ContainerInjectionInterface {

  protected $config;
  protected $accessRequestService;
  protected $flood;
  protected $currentUser;
  protected $routeMatch;

  public function __construct(ConfigFactoryInterface $config_factory, AccessRequestService $access_request_service, FloodInterface $flood, AccountInterface $current_user, RouteMatchInterface $route_match) {
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
   */
  public function buildForm(array $form, FormStateInterface $form_state, $asset_identifier = NULL) {
    $user_block_field = $this->config->get('user_block_field');

    if ($user_block_field) {
      $user = \Drupal\user\Entity\User::load($this->currentUser->id());
      if ($user->hasField($user_block_field) && $user->get($user_block_field)->value) {
        $this->messenger()->addError($this->t('Your access to this system has been revoked. Please contact an administrator.'));
        return [];
      }
    }

    if ($this->currentUser->isAnonymous()) {
      $url = Url::fromRoute('user.login', [], ['query' => ['destination' => $this->getRequest()->getRequestUri()]]);
      return new RedirectResponse($url->toString());
    }

    $card_id = $this->accessRequestService->fetchCardIdForUser($this->currentUser->id());
    if (empty($card_id)) {
      $this->messenger()->addError($this->t('No card found associated with your account. Please contact support.'));
      return [];
    }

    // Automatically submit the form on page load if not already submitted.
    if (!$form_state->isSubmitted()) {
      $this->submitForm($form, $form_state);
    }

    // This form is intended to be programmatically submitted.
    // The resend button is a fallback for when javascript might fail,
    // or for manual resubmission.
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

    $result = $this->accessRequestService->performAccessRequest();
    $this->flood->register('access_request.form_submit', 60);

    switch ($result['http_status']) {
      case 201:
        $this->messenger()->addStatus($this->t('Card accepted. Door/tool enabled.'));
        break;
      case 403:
        $this->messenger()->addError($this->t('Access denied: missing required badge/permission.'));
        break;
      case 400:
        $this->messenger()->addError($this->t('Bad request: unknown reader. Check the QR’s reader key or server config.'));
        break;
      default:
        $this->messenger()->addError($this->t('Access system error. Try again or contact an admin.'));
        break;
    }

    // Redirect to the front page after showing the message.
    $form_state->setRedirect('<front>');
  }
}
