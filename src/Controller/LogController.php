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

  protected $analyzer;

  public function __construct(LogAnalyzer $analyzer) {
    $this->analyzer = $analyzer;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ai_log_analysis.log_analyzer')
    );
  }

  /**
   * Page showing recent logs with Analyze buttons.
   */
  public function logsPage() {
    $logs = $this->analyzer->getRecentDblogs(10);
    $headers = ['Timestamp', 'Type', 'Severity', 'Message', 'Operations'];
    $rows = [];

    foreach ($logs as $key => $log) {
      $url = Url::fromRoute('ai_log_analysis.analyze', ['key' => $key]);
      $rows[] = [
        date('Y-m-d H:i:s', (int) $log['timestamp']),
        $log['type'],
        $log['severity'],
        substr($log['message'], 0, 100) . '...',
        [
          'data' => [
            '#type' => 'link',
            '#title' => $this->t('Analyze with Grok'),
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
      '#attached' => [
        'library' => [
          'core/drupal.dialog.ajax',
        ],
      ],
    ];
  }

  /**
   * Handle analysis of a specific log entry.
   */
  public function analyze($key) {
    $logs = $this->analyzer->getRecentDblogs(10);
    if (!isset($logs[$key])) {
      $this->messenger()->addError($this->t('Invalid log entry.'));
      return new RedirectResponse(Url::fromRoute('ai_log_analysis.logs')->toString());
    }

    $result = $this->analyzer->analyzeWithGrok([$logs[$key]]);

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-analysis']],
      'analysis' => [
        '#type' => 'markup',
        '#markup' => '<pre>' . htmlspecialchars($result['analysis']) . '</pre>',
      ],
    ];

    if (!empty($result['snippets'])) {
      $snippets_markup = '';
      foreach ($result['snippets'] as $entry) {
        $snippets_markup .= "<div><p><strong>Message:</strong> {$entry['message']}</p>";
        $snippets_markup .= '<pre>' . htmlspecialchars($entry['snippet'] ?? 'No snippet available') . '</pre></div>';
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
