<?php

namespace Drupal\ai_log_analysis\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Settings form for Grok AI integration.
 */
class GrokSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['ai_log_analysis.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'grok_settings_form';
  }

  /**
   * {@inheritdoc}
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

    $form['log_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Number of Logs to Analyze'),
      '#default_value' => $config->get('log_limit') ?? 5,
      '#min' => 1,
      '#max' => 50,
      '#description' => $this->t('Select how many recent logs should be sent to Grok AI for analysis.'),
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('ai_log_analysis.settings');
    $config
      ->set('grok_api_key', $form_state->getValue('grok_api_key'))
      ->set('log_limit', $form_state->getValue('log_limit'))
      ->save();

    \Drupal::service('cache_tags.invalidator')->invalidateTags(['ai_crash_analysis']);
    \Drupal::messenger()->addMessage($this->t('Settings have been saved.'));

    parent::submitForm($form, $form_state);
  }

}
