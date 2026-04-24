<?php

namespace Drupal\access_request\Form;

use Drupal\access_request\HomeAssistantClient;
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

    $form['home_assistant'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Home Assistant (direct backend)'),
      '#description' => $this->t('Optional parallel backend. When enabled, assets without an explicit <code>backend:</code> in the asset map will be handled by calling Home Assistant directly instead of the Python gateway. The Python gateway still handles every asset that has <code>backend: python</code> or defaults while this master switch is off.'),
    ];

    $form['home_assistant']['home_assistant_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Home Assistant as default backend'),
      '#description' => $this->t('Master switch. Per-asset <code>backend: python</code> or <code>backend: home_assistant</code> overrides still apply regardless of this toggle.'),
      '#default_value' => (bool) ($config->get('home_assistant.enabled') ?? FALSE),
    ];

    $form['home_assistant']['home_assistant_base_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Home Assistant base URL'),
      '#description' => $this->t('E.g. <code>https://homeassistant.dev.access.makehaven.org</code>. No trailing slash.'),
      '#default_value' => $config->get('home_assistant.base_url'),
    ];

    $form['home_assistant']['home_assistant_timeout_seconds'] = [
      '#type' => 'number',
      '#title' => $this->t('Home Assistant request timeout (seconds)'),
      '#default_value' => $config->get('home_assistant.timeout_seconds') ?? 5,
      '#min' => 1,
      '#max' => 30,
    ];

    $form['home_assistant']['home_assistant_authorize_service'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Authorize service'),
      '#description' => $this->t('Home Assistant service that handles the full authorization flow (notifies the reader and fires the activator). Format: <code>domain.service</code>. Default: <code>script.authorization_request</code>. Drupal POSTs <code>{card_serial, activator, reader}</code> to this service for every HA-backed asset that does not specify its own <code>ha_service:</code>.'),
      '#default_value' => $config->get('home_assistant.authorize_service') ?? 'script.authorization_request',
    ];

    $token_value = function_exists('pantheon_get_secret')
      ? pantheon_get_secret(HomeAssistantClient::TOKEN_ENV_VAR)
      : (getenv(HomeAssistantClient::TOKEN_ENV_VAR) ?: ($_ENV[HomeAssistantClient::TOKEN_ENV_VAR] ?? NULL));
    $token_present = is_string($token_value) && trim($token_value) !== '';
    $form['home_assistant']['home_assistant_token_status'] = [
      '#type' => 'item',
      '#title' => $this->t('Bearer token (via Pantheon Secrets)'),
      '#markup' => $token_present
        ? $this->t('Detected: secret <code>@var</code> is set.', ['@var' => HomeAssistantClient::TOKEN_ENV_VAR])
        : $this->t('<strong>Not configured.</strong> Set on Pantheon as a <em>Runtime, Web</em> secret: <code>terminus secret:site:set makehaven-website @var &lt;token&gt; --type=runtime --scope=web</code>.', ['@var' => HomeAssistantClient::TOKEN_ENV_VAR]),
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

    $form['user_settings']['user_block_message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('User Block Message'),
      '#description' => $this->t('Message to show to a user who is blocked by the User Block Field. This check happens before the gateway request is made.'),
      '#default_value' => $config->get('user_block_message'),
    ];

    $form['denial_messages'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Denial Messages'),
      '#description' => $this->t('Configure custom messages for specific denial reasons. All message fields support the <code>[payment_portal_button]</code> token.'),
    ];

    $form['denial_messages']['default_denial_message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Default Denial Message'),
      '#description' => $this->t('The default message to show when access is denied for a generic reason.'),
      '#default_value' => $config->get('default_denial_message'),
    ];

    $form['denial_messages']['unpaid_message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Message for Unpaid Users (Payment Failed)'),
      '#description' => $this->t('Based on the <code>field_payment_failed</code> boolean field.'),
      '#default_value' => $config->get('unpaid_message'),
    ];

    $form['denial_messages']['manual_pause_message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Message for Manual Pause'),
      '#description' => $this->t('Based on the <code>field_manual_pause</code> boolean field.'),
      '#default_value' => $config->get('manual_pause_message'),
    ];

    $form['denial_messages']['chargebee_pause_message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Message for Chargebee Pause'),
      '#description' => $this->t('Based on the <code>field_chargebee_payment_pause</code> boolean field.'),
      '#default_value' => $config->get('chargebee_pause_message'),
    ];

    $form['denial_messages']['override_message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Message for Access Override'),
      '#description' => $this->t('Based on the <code>field_access_override</code> list field having the value "deny".'),
      '#default_value' => $config->get('override_message'),
    ];

    $form['denial_messages']['no_member_role_message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Message for Missing Member Role'),
      '#description' => $this->t('Shown when the user does not have the "member" role.'),
      '#default_value' => $config->get('no_member_role_message'),
    ];

    $form['denial_messages']['payment_portal_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Payment Portal URL'),
      '#description' => $this->t('The URL or internal path (e.g., /portal) for the button that directs users to update their payment information. The button is inserted into the "Message for Unpaid Users" via the <code>[payment_portal_button]</code> token.'),
      '#default_value' => $config->get('payment_portal_url'),
    ];

    $form['view_assets_link'] = [
      '#markup' => $this->t('<a href=":url">View configured assets</a>', [':url' => Url::fromRoute('access_request.list')->toString()]),
    ];

    $form['asset_map'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Asset Map'),
      '#description' => $this->t('A YAML mapping of asset IDs to asset information. The key for each asset is its unique ID.<br>Each asset has the following properties:<br>- <strong>name</strong>: The display name of the asset.<br>- <strong>description</strong>: A short description of the asset.<br>- <strong>image</strong>: The URL of an image for the asset (optional).<br>- <strong>category</strong>: A category for grouping assets (e.g., doors, metalshop).<br>- <strong>permission_id</strong>: The permission ID required for this asset (optional, defaults to the asset ID).<br>- <strong>activator</strong>: Home Assistant activator name passed to the authorize service (optional, defaults to <code>{key}activator</code>).<br>- <strong>reader</strong>: Home Assistant reader name passed to the authorize service (optional, defaults to <code>{key}reader</code>).<br>- <strong>ha_service</strong>: Override the global authorize service for this asset, formatted as <code>domain.service</code>. Usually omitted.<br>- <strong>backend</strong>: Per-asset override, either <code>python</code> or <code>home_assistant</code>. Omit to defer to the master switch above.<br><br>Example:<br><code>backdoor:<br>&nbsp;&nbsp;name: Back Door<br>&nbsp;&nbsp;description: Main rear entrance.<br>&nbsp;&nbsp;category: doors</code>'),
      '#default_value' => $config->get('asset_map'),
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
      ->set('home_assistant.enabled', (bool) $form_state->getValue('home_assistant_enabled'))
      ->set('home_assistant.base_url', rtrim((string) $form_state->getValue('home_assistant_base_url'), '/'))
      ->set('home_assistant.timeout_seconds', (int) $form_state->getValue('home_assistant_timeout_seconds'))
      ->set('home_assistant.authorize_service', trim((string) $form_state->getValue('home_assistant_authorize_service')))
      ->set('asset_map', $form_state->getValue('asset_map'))
      ->set('dry_run', $form_state->getValue('dry_run'))
      ->set('user_block_field', $form_state->getValue('user_block_field'))
      ->set('user_block_message', $form_state->getValue('user_block_message'))
      ->set('unpaid_message', $form_state->getValue('unpaid_message'))
      ->set('payment_portal_url', $form_state->getValue('payment_portal_url'))
      ->set('default_denial_message', $form_state->getValue('default_denial_message'))
      ->set('manual_pause_message', $form_state->getValue('manual_pause_message'))
      ->set('chargebee_pause_message', $form_state->getValue('chargebee_pause_message'))
      ->set('override_message', $form_state->getValue('override_message'))
      ->set('no_member_role_message', $form_state->getValue('no_member_role_message'))
      ->save();

    parent::submitForm($form, $form_state);
  }
}
