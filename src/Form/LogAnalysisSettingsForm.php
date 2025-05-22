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
  protected function getEditableConfigNames(): array {
    return ['ai_log_analysis.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ai_log_analysis_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('ai_log_analysis.settings');

    $form['log_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Number of Logs to Analyze'),
      '#default_value' => $config->get('log_limit') ?? 5,
      '#min' => 1,
      '#max' => 1000,
      '#description' => $this->t('Maximum number of recent logs to analyze using AI.'),
    ];

    $form['log_retention_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Log Retention Period (in Days)'),
      '#default_value' => $config->get('log_retention_days') ?? 30,
      '#min' => 1,
      '#max' => 365,
      '#description' => $this->t('Number of days to retain logs before automatic cleanup via cron.'),
      '#required' => TRUE,
    ];

    $form['max_logs_per_type'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum Logs Per Type'),
      '#default_value' => $config->get('max_logs_per_type') ?? 1000,
      '#min' => 100,
      '#max' => 10000,
      '#description' => $this->t('Maximum number of logs to keep per log type (e.g., cron, page not found). Older logs will be automatically deleted.'),
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    if ($form_state->getValue('log_limit') < 1) {
      $form_state->setErrorByName('log_limit', $this->t('The number of logs must be at least 1.'));
    }
    if ($form_state->getValue('log_retention_days') < 1) {
      $form_state->setErrorByName('log_retention_days', $this->t('Retention period must be at least 1 day.'));
    }
    if ($form_state->getValue('max_logs_per_type') < 100) {
      $form_state->setErrorByName('max_logs_per_type', $this->t('Maximum logs per type must be at least 100.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('ai_log_analysis.settings')
      ->set('log_limit', (int) $form_state->getValue('log_limit'))
      ->set('log_retention_days', (int) $form_state->getValue('log_retention_days'))
      ->set('max_logs_per_type', (int) $form_state->getValue('max_logs_per_type'))
      ->save();

    $this->messenger()->addStatus($this->t('AI Log Analysis settings have been saved.'));
    parent::submitForm($form, $form_state);
  }

}
