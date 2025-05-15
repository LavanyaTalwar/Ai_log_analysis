<?php

namespace Drupal\ai_log_analysis\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Settings form for AI Log Analysis.
 */
class LogAnalysisSettingsForm extends ConfigFormBase {

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
    return 'ai_log_analysis_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('ai_log_analysis.settings');

    $form['log_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Number of Logs to Analyze'),
      '#default_value' => $config->get('log_limit') ?? 5,
      '#min' => 1,
      '#max' => 1000,
      '#description' => $this->t('Select how many recent logs should be analyzed by the AI module.'),
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('ai_log_analysis.settings')
      ->set('log_limit', $form_state->getValue('log_limit'))
      ->save();

    \Drupal::service('cache_tags.invalidator')->invalidateTags(['ai_log_analysis']);
    \Drupal::messenger()->addMessage($this->t('Settings have been saved.'));

    parent::submitForm($form, $form_state);
  }

}
