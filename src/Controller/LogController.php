<?php

namespace Drupal\ai_log_analysis\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\ai_log_analysis\Service\LogAnalyzer;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Url;
use Drupal\Core\Database\Connection;

/**
 * Controller for displaying and analyzing custom logs.
 */
class LogController extends ControllerBase {

  /**
   * The log analyzer service.
   *
   * @var \Drupal\ai_log_analysis\Service\LogAnalyzer
   */
  protected $analyzer;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructs the controller.
   */
  public function __construct(LogAnalyzer $analyzer, Connection $database) {
    $this->analyzer = $analyzer;
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ai_log_analysis.log_analyzer'),
      $container->get('database')
    );
  }

  /**
   * Page showing paginated custom logs with Analyze buttons.
   */
  public function logsPage() {
    $headers = ['Timestamp', 'Type', 'Severity', 'Message', 'Operations'];

    $query = $this->database->select('custom_log', 'cl')
      ->extend('Drupal\Core\Database\Query\PagerSelectExtender')
      ->limit(10)
      ->fields('cl', ['id', 'timestamp', 'type', 'severity', 'message'])
      ->orderBy('timestamp', 'DESC');

    $results = $query->execute();
    $rows = [];

    foreach ($results as $row) {
      $url = Url::fromRoute('ai_log_analysis.analyze', ['key' => $row->id]);

      $rows[] = [
        date('Y-m-d H:i:s', $row->timestamp),
        $row->type,
        $row->severity,
        substr($row->message, 0, 100) . '...',
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
      '#type' => 'container',
      'table' => [
        '#type' => 'table',
        '#header' => $headers,
        '#rows' => $rows,
        '#empty' => $this->t('No logs found.'),
      ],
      'pager' => [
        '#type' => 'pager',
      ],
    ];
  }

  /**
   * Handles analysis of a specific custom log entry by ID.
   */
  public function analyze($key) {
    $log = $this->database->select('custom_log', 'cl')
      ->fields('cl', ['id', 'timestamp', 'type', 'severity', 'message'])
      ->condition('id', $key)
      ->execute()
      ->fetchAssoc();

    if (!$log) {
      $this->messenger()->addError($this->t('Invalid log entry selected.'));
      return new RedirectResponse(Url::fromRoute('ai_log_analysis.logs')->toString());
    }

    $result = $this->analyzer->analyzeWithAi([$log]);

    $convertBoldMarkdown = function(string $text): string {
      // Remove triple backticks to avoid raw markdown fences in output
      $text = preg_replace('/```/', '', $text);

      // Escape HTML special chars
      $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

      // Convert **bold** markdown to <strong>
      $with_bold = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $escaped);

      // Convert newlines to <br>
      return nl2br($with_bold);
    };

    $analysis_markup = $convertBoldMarkdown($result['analysis'] ?? 'No analysis available.');

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-analysis']],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Analysis Result'),
      ],
      'analysis' => [
        '#type' => 'markup',
        '#markup' => "<div class='analysis-text' style='background:#f9f9f9;padding:15px;border-radius:5px;line-height:1.5;font-family:monospace;'>{$analysis_markup}</div>",
      ],
    ];

    if (!empty($result['snippets'])) {
      $snippets_markup = "<h3>" . $this->t('Related Code Snippets') . "</h3>";
      foreach ($result['snippets'] as $entry) {
        $msg = htmlspecialchars($entry['message'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $snippet = htmlspecialchars($entry['snippet'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $snippets_markup .= "<div style='margin-bottom:20px;padding:10px;border:1px solid #ddd;border-radius:5px;'>";
        $snippets_markup .= "<p><strong>" . $this->t('Message:') . "</strong> {$msg}</p>";
        $snippets_markup .= "<pre style='background:#eee;padding:10px;border-radius:4px;overflow-x:auto;'>{$snippet}</pre>";
        $snippets_markup .= "</div>";
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
      '#prefix' => '<p>',
      '#suffix' => '</p>',
    ];

    return $build;
  }

}
