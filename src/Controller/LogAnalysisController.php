<?php

namespace Drupal\ai_log_analysis\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\ai_log_analysis\Service\LogAnalyzer;

/**
 * Controller for analyzing logs and displaying AI-based analysis.
 */
class LogAnalysisController extends ControllerBase {

  /**
   * The analyzer service.
   *
   * @var \Drupal\ai_log_analysis\Service\LogAnalyzer
   */
  protected $analyzer;

  /**
   * Constructs the controller.
   *
   * @param \Drupal\ai_log_analysis\Service\LogAnalyzer $analyzer
   *   The log analyzer service.
   */
  public function __construct(LogAnalyzer $analyzer) {
    $this->analyzer = $analyzer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ai_log_analysis.log_analyzer')
    );
  }

  /**
   * Displays recent logs and AI analysis.
   *
   * @return array
   *   A render array containing the logs and AI analysis.
   */
  public function analyze() {
    // Get configured log limit.
    $config = $this->config('ai_log_analysis.settings');
    $log_limit = (int) $config->get('log_limit') ?: 5;

    // Clear any previous logs stored in temporary state.
    \Drupal::service('tempstore.private')->get('ai_log_analysis')->delete('logs');

    // Fetch recent logs.
    $logs = $this->analyzer->getRecentDblogs($log_limit);

    // Check if logs are available.
    if (empty($logs)) {
      return [
        '#type' => 'markup',
        '#markup' => $this->t('No dblog entries available for analysis.'),
      ];
    }

    // Analyze logs with the new AI method.
    $ai_response = $this->analyzer->analyzeWithAi($logs);

    // Prepare the render array.
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['error-log-analysis']],
    ];

    $build['logs_title'] = [
      '#markup' => '<h2>' . $this->t('Recent Dblog Entries') . '</h2>',
    ];

    foreach ($logs as $log) {
      $log_markup = '<pre>' . htmlspecialchars("[{$log['timestamp']}] [{$log['type']}] [Severity {$log['severity']}]: {$log['message']}") . '</pre>';

      // Try to get a code snippet for this log.
      $snippet = $this->analyzer->getCodeSnippetFromLog($log['message']);

      if (!empty($snippet)) {
        $snippet_string = is_string($snippet) ? $snippet : print_r($snippet, TRUE);
        $log_markup .= '<details style="margin-bottom:1em;"><summary><strong>' . $this->t('View Code Snippet') . '</strong></summary><pre>' .
          htmlspecialchars($snippet_string) .
          '</pre></details>';
      }

      $build[] = [
        '#markup' => $log_markup,
      ];
    }

    $build['analysis_title'] = [
      '#markup' => '<h2>' . $this->t('AI Analysis') . '</h2>',
    ];

    $ai_text = is_string($ai_response) ? $ai_response : print_r($ai_response, TRUE);
    $build['ai_output'] = [
      '#markup' => '<div style="background: #f8f9fa; border: 1px solid #ccc; padding: 1em; border-radius: 6px;"><pre>' .
        (!empty($ai_text) ? htmlspecialchars($ai_text) : $this->t('No analysis available.')) .
        '</pre></div>',
    ];

    return $build;
  }

}
