<?php

namespace Drupal\ai_log_analysis\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 *
 */
class GrokSettingsForm extends ConfigFormBase {

  /**
   *
   */
  protected function getEditableConfigNames() {
    return ['ai_log_analysis.settings'];
  }

  /**
   *
   */
  public function getFormId() {
    return 'grok_settings_form';
  }

  /**
   *
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('ai_log_analysis.settings');

    $form['grok_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Grok API Key'),
      '#default_value' => $config->get('grok_api_key'),
      '#description' => $this->t('Enter your Grok API key securely.'),
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   *
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Save the API key.
    $config = $this->config('ai_log_analysis.settings');
    $config->set('grok_api_key', $form_state->getValue('grok_api_key'));
    $config->save();

    // Clear cache for the module to ensure settings are applied.
    \Drupal::service('cache_tags.invalidator')->invalidateTags(['ai_crash_analysis']);

    // Optionally, you can add a message to inform the user.
    \Drupal::messenger()->addMessage($this->t('Grok API key has been saved and cache cleared.'));

    parent::submitForm($form, $form_state);
  }

}
