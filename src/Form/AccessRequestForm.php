<?php

namespace Drupal\access_request\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Url;

class AccessRequestForm extends FormBase {

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

      if ($result['success']) {
        \Drupal::messenger()->addMessage($this->t('Request submitted successfully.'));
      }
      else {
        \Drupal::messenger()->addError($this->t('Failed to submit access request. Response from server: @response', [
          '@response' => $result['response'],
        ]));
      }
    }
    else {
      \Drupal::messenger()->addError($this->t('Invalid asset identifier provided.'));
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
    // URL for the access request.
    $url = 'https://server.dev.access.makehaven.org/toolauth/req';

    $client = \Drupal::httpClient();
    try {
      $response = $client->request('POST', $url, [
        'headers' => [
          'Content-Type' => 'application/json',
        ],
        'body' => $payload,
      ]);

      $response_body = $response->getBody()->getContents();
      \Drupal::logger('access_request')->debug('HTTP request result: <pre>@result</pre>', [
        '@result' => $response_body,
      ]);

      if (strpos($response_body, 'Card accepted') !== FALSE) {
        return ['success' => TRUE];
      }
      else {
        return [
          'success'  => FALSE,
          'response' => $response_body,
        ];
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('access_request')->error('Request failed with error: @error', [
        '@error' => $e->getMessage(),
      ]);
      return [
        'success'  => FALSE,
        'response' => $e->getMessage(),
      ];
    }
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
    return !empty($asset_identifier) && preg_match('/^[a-zA-Z0-9_-]+$/', $asset_identifier);
  }
}
