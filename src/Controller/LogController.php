<?php

namespace Drupal\ai_log_analysis\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\ai_log_analysis\Service\LogAnalyzer;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Url;

/**
 * Controller for displaying and analyzing crash logs.
 */
class LogController extends ControllerBase {

  /**
   * The log analyzer service.
   *
   * @var \Drupal\ai_log_analysis\Service\LogAnalyzer
   */
  protected $analyzer;

  /**
   * Constructs the controller.
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
   * Page showing recent logs with Analyze buttons.
   */
  public function logsPage() {
    $config = $this->config('ai_log_analysis.settings');
    $log_limit = (int) $config->get('log_limit') ?: 5;

    $logs = $this->analyzer->getRecentDblogs($log_limit);

    $headers = ['Timestamp', 'Type', 'Severity', 'Message', 'Operations'];
    $rows = [];

    foreach ($logs as $key => $log) {
      $url = Url::fromRoute('ai_log_analysis.analyze', ['key' => $key]);
      $rows[] = [
        $log['timestamp'],
        $log['type'],
        $log['severity'],
        substr($log['message'], 0, 100) . '...',
        [
          'data' => [
            '#type' => 'link',
            '#title' => $this->t('Analyze with AI'),
            '#url' => $url,
            '#attributes' => ['class' => ['button', 'button--primary']],
          ],
        ],
      ];
    }

    return [
      '#type' => 'table',
      '#header' => $headers,
      '#rows' => $rows,
      '#empty' => $this->t('No logs found.'),
    ];
  }

  /**
   * Handles analysis of a specific log entry.
   */
  public function analyze($key) {
    $config = $this->config('ai_log_analysis.settings');
    $log_limit = (int) $config->get('log_limit') ?: 5;

    $logs = $this->analyzer->getRecentDblogs($log_limit);

    if (!isset($logs[$key])) {
      $this->messenger()->addError($this->t('Invalid log entry selected.'));
      return new RedirectResponse(Url::fromRoute('ai_log_analysis.logs')->toString());
    }

    $result = $this->analyzer->analyzeWithAi([$logs[$key]]);

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-analysis']],
      'analysis' => [
        '#type' => 'markup',
        '#markup' => '<pre>' . htmlspecialchars($result['analysis'] ?? 'No analysis available.') . '</pre>',
      ],
    ];

    if (!empty($result['snippets'])) {
      $snippets_markup = '';
      foreach ($result['snippets'] as $entry) {
        $snippets_markup .= "<div><p><strong>Message:</strong> {$entry['message']}</p>";
        $snippets_markup .= '<pre>' . htmlspecialchars($entry['snippet'] ?? 'No snippet available.') . '</pre></div>';
      }

      $build['snippets'] = [
        '#type' => 'markup',
        '#markup' => $snippets_markup,
      ];
    }

    $build['back'] = [
      '#type' => 'link',
      '#title' => $this->t('← Back to logs'),
      '#url' => Url::fromRoute('ai_log_analysis.logs'),
      '#attributes' => ['class' => ['button']],
    ];

    return $build;
  }

}
