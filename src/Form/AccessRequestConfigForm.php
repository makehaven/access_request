<?php

namespace Drupal\access_request\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

class AccessRequestConfigForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'access_request_config_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['access_request.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('access_request.settings');

    $form['python_gateway_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Python Gateway URL'),
      '#description' => $this->t('The URL of the Python gateway.'),
      '#default_value' => $config->get('python_gateway_url'),
    ];

    $form['timeout_seconds'] = [
      '#type' => 'number',
      '#title' => $this->t('Timeout in seconds'),
      '#description' => $this->t('The timeout for requests to the Python gateway.'),
      '#default_value' => $config->get('timeout_seconds'),
    ];

    $form['web_hmac_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('HMAC Secret'),
      '#description' => $this->t('The HMAC secret for signing requests. Leave empty to disable signing.'),
      '#default_value' => $config->get('web_hmac_secret'),
    ];

    $form['user_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('User Settings'),
    ];

    $form['user_settings']['user_block_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('User Block Field'),
      '#description' => $this->t('The machine name of a boolean field on the user entity. If this field is set to true for a user, they will be blocked from making access requests.'),
      '#default_value' => $config->get('user_block_field'),
    ];

    $form['view_assets_link'] = [
      '#markup' => $this->t('<a href=":url">View configured assets</a>', [':url' => Url::fromRoute('access_request.list')->toString()]),
    ];

    $form['asset_map'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Asset Map'),
      '#description' => $this->t('A YAML mapping of asset IDs to asset information. The key for each asset is its unique ID.<br>Each asset has the following properties:<br>- <strong>name</strong>: The display name of the asset.<br>- <strong>description</strong>: A short description of the asset.<br>- <strong>image</strong>: The URL of an image for the asset (optional).<br>- <strong>category</strong>: A category for grouping assets (e.g., doors, metalshop).<br><br>Example:<br><code>backdoor:<br>&nbsp;&nbsp;name: Back Door<br>&nbsp;&nbsp;description: Main rear entrance.<br>&nbsp;&nbsp;image: /modules/custom/access_request/images/backdoor.jpg<br>&nbsp;&nbsp;category: doors</code>'),
      '#default_value' => $config->get('asset_map'),
    ];

    $form['door_map'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Door Map'),
      '#description' => $this->t('A YAML mapping of asset IDs to permission IDs.'),
      '#default_value' => $config->get('door_map'),
    ];

    $form['dry_run'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Dry-run mode'),
      '#description' => $this->t('When enabled, the module will log the would-be payload and not send the request to the Python gateway.'),
      '#default_value' => $config->get('dry_run'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('access_request.settings')
      ->set('python_gateway_url', $form_state->getValue('python_gateway_url'))
      ->set('timeout_seconds', $form_state->getValue('timeout_seconds'))
      ->set('web_hmac_secret', $form_state->getValue('web_hmac_secret'))
      ->set('asset_map', $form_state->getValue('asset_map'))
      ->set('door_map', $form_state->getValue('door_map'))
      ->set('dry_run', $form_state->getValue('dry_run'))
      ->set('user_block_field', $form_state->getValue('user_block_field'))
      ->save();

    parent::submitForm($form, $form_state);
  }
}
