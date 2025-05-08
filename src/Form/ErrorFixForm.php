<?php

namespace Drupal\ai_log_analysis\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\ai_log_analysis\Service\CrashAnalyzer;

/**
 * Provides a form to select and analyze a specific error log.
 */
class ErrorFixForm extends FormBase {

  /**
   * The crash analyzer service.
   *
   * @var \Drupal\ai_log_analysis\Service\CrashAnalyzer
   */
  protected $analyzer;

  /**
   * Constructs a new ErrorFixForm object.
   *
   * @param \Drupal\ai_log_analysis\Service\CrashAnalyzer $analyzer
   *   The crash analyzer service.
   */
  public function __construct(CrashAnalyzer $analyzer) {
    $this->analyzer = $analyzer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ai_log_analysis.crash_analyzer')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ai_error_fix_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $logs = $this->analyzer->getRecentDblogs(10);
    $options = [];

    foreach ($logs as $key => $log) {
      $summary = substr($log['message'], 0, 100);
      $options[$key] = "[{$log['timestamp']}] [{$log['type']}] Severity {$log['severity']}: $summary...";
    }

    $form['selected_error'] = [
      '#type' => 'select',
      '#title' => $this->t('Select a log to analyze'),
      '#options' => $options,
      '#required' => TRUE,
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Analyze with Grok'),
    ];

    // Store logs in form state for access in submit handler.
    $form_state->setTemporaryValue('logs', $logs);

    // Display AI response if available.
    if ($ai_response = $form_state->get('ai_response')) {
      $form['ai_response'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['ai-analysis-container']],
        'styles' => [
          '#type' => 'html_tag',
          '#tag' => 'style',
          '#value' => '
            .ai-analysis-container {
              max-width: 1200px;
              margin: 20px 0;
              line-height: 1.6;
              font-size: 15px;
            }
            .ai-analysis-container strong {
              font-weight: 700;
              color: #2c3e50;
            }
            .ai-analysis-container h2 {
              font-size: 24px;
              color: #2c3e50;
              margin: 25px 0 15px;
              padding-bottom: 8px;
              border-bottom: 2px solid #eee;
            }
            .ai-analysis-container h3 {
              font-size: 18px;
              color: #2c3e50;
              margin: 20px 0 10px;
            }
            .ai-analysis-container ul {
              margin: 10px 0 10px 20px;
              list-style-type: none;
            }
            .ai-analysis-container ul li {
              position: relative;
              padding-left: 15px;
              margin-bottom: 8px;
            }
            .ai-analysis-container ul li:before {
              content: "-";
              position: absolute;
              left: 0;
            }
            .ai-analysis-container p {
              margin: 10px 0;
            }
            .log-entry {
              background: #f8f9fa;
              border-left: 4px solid #0678be;
              padding: 15px;
              margin: 15px 0;
              border-radius: 4px;
            }
            pre {
              background: #f8f9fa;
              padding: 12px;
              border-radius: 4px;
              border: 1px solid #e9ecef;
              overflow-x: auto;
              font-family: monospace;
              font-size: 13px;
              line-height: 1.4;
            }
          ',
        ],
        'analysis' => [
          '#type' => 'markup',
          '#markup' => nl2br(preg_replace(
            ['/\*(.*?)\*/', '/^([A-Z][A-Z\s]+)$/m', '/^> (.*)$/m'],
            ['<strong>$1</strong>', '<h2>$1</h2>', '<h3>$1</h3>'],
            htmlspecialchars($ai_response['analysis'])
          )),
        ],
      ];

      if (!empty($ai_response['snippets'])) {
        $snippets_html = '<h3>' . $this->t('Code Snippets') . '</h3>';
        foreach ($ai_response['snippets'] as $entry) {
          $snippets_html .= '<div class="log-entry">';
          $snippets_html .= '<p>Timestamp: ' . htmlspecialchars($entry['timestamp']) . '</p>';
          $snippets_html .= '<p>Type: ' . htmlspecialchars($entry['type']) . '</p>';
          $snippets_html .= '<p>Severity: ' . htmlspecialchars($entry['severity']) . '</p>';
          $snippets_html .= '<p>Message: ' . htmlspecialchars($entry['message']) . '</p>';

          if (!empty($entry['snippet'])) {
            $snippets_html .= '<pre>' . htmlspecialchars($entry['snippet']) . '</pre>';
          }
          else {
            $snippets_html .= '<p><em>No snippet available.</em></p>';
          }

          $snippets_html .= '</div>';
        }
        $form['ai_response']['snippets'] = [
          '#type' => 'markup',
          '#markup' => $snippets_html,
        ];
      }
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Clear previous logs from temporary state.
    $form_state->setTemporaryValue('logs', []);

    // Retrieve logs again to ensure we have the latest.
    $logs = $this->analyzer->getRecentDblogs(10);
    $selected_key = $form_state->getValue('selected_error');
    $selected_log = [$logs[$selected_key]];

    // Perform AI analysis.
    $ai_response = $this->analyzer->analyzeWithGrok($selected_log);

    // Save AI response to form state to display after submit.
    $form_state->set('ai_response', $ai_response);
    $form_state->setRebuild(TRUE);
  }

}
