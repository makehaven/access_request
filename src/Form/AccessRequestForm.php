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

class AccessRequestForm extends FormBase implements ContainerInjectionInterface {

  protected $config;
  protected $accessRequestService;
  protected $flood;
  protected $currentUser;

  public function __construct(ConfigFactoryInterface $config_factory, AccessRequestService $access_request_service, FloodInterface $flood, AccountInterface $current_user) {
    $this->config = $config_factory->get('access_request.settings');
    $this->accessRequestService = $access_request_service;
    $this->flood = $flood;
    $this->currentUser = $current_user;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('access_request.service'),
      $container->get('flood'),
      $container->get('current_user')
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
        \Drupal::messenger()->addError($this->t('Your access to this system has been revoked. Please contact an administrator.'));
        return [];
      }
    }

    if ($this->currentUser->isAnonymous()) {
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
    $card_id = $this->accessRequestService->fetchCardIdForUser($this->currentUser->id());
    if (empty($card_id)) {
      \Drupal::messenger()->addError($this->t('No card found associated with your account. Please contact support.'));
      return [];
    }

    // Store asset_identifier and method in form state.
    $form_state->set('asset_identifier', $asset_identifier);
    $form_state->set('method', \Drupal::request()->query->get('method'));
    $form_state->set('source', \Drupal::request()->query->get('source'));

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

    // Retrieve asset_identifier, method, and source.
    $asset_identifier = $form_state->get('asset_identifier');
    $method = $form_state->get('method');
    $source = $form_state->get('source');

    if ($this->isValidAssetIdentifier($asset_identifier)) {
      // Submit the access request and capture the result.
      $result = $this->accessRequestService->performAccessRequest($asset_identifier, $method, $source);

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
