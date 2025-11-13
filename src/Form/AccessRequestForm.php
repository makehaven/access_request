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
    $asset = $form_state->get('asset');
    $url = Url::fromRoute('access_request.initiate', ['asset' => $asset]);
    $form_state->setRedirectUrl($url);
  }
}
