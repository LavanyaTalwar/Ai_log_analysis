<?php

namespace Drupal\ai_log_analysis\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\ai_log_analysis\Service\LogAnalyzer;
use Drupal\Core\Database\Connection;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\TempStore\PrivateTempStoreFactory;

/**
 * Controller for displaying and analyzing logs.
 */
class LogController extends ControllerBase {

  /**
   * The log analyzer service.
   *
   * @var \Drupal\ai_log_analysis\Service\LogAnalyzer
   */
  protected LogAnalyzer $analyzer;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * The private tempstore for this module.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStoreFactory
   */
  protected $tempStore;

  /**
   * Constructs a LogController object.
   *
   * @param \Drupal\ai_log_analysis\Service\LogAnalyzer $analyzer
   *   The log analyzer service.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection service.
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $temp_store_factory
   *   The private tempstore factory.
   */
  public function __construct(LogAnalyzer $analyzer, Connection $database, PrivateTempStoreFactory $temp_store_factory) {
    $this->analyzer = $analyzer;
    $this->database = $database;
    $this->tempStore = $temp_store_factory->get('ai_log_analysis');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('ai_log_analysis.log_analyzer'),
      $container->get('database'),
      $container->get('user.private_tempstore')
    );
  }

  /**
   * Page showing paginated custom logs with Analyze buttons.
   *
   * @return array
   *   A render array containing the logs table with pagination.
   */
  public function logsPage(): array {
    // Check if the ai_log_analysis_table table exists.
    if (!$this->database->schema()->tableExists('ai_log_analysis_table')) {
      return [
        '#type' => 'markup',
        '#markup' => $this->t('The custom log table has not been created. Please uninstall and reinstall the module.'),
      ];
    }

    $build['clear_logs'] = [
      '#type' => 'link',
      '#title' => $this->t('Clear all logs'),
      '#url' => Url::fromRoute('ai_log_analysis.clear_logs'),
      '#attributes' => [
        'class' => ['button', 'button--danger'],
        'onclick' => 'return confirm("Are you sure you want to delete all logs?");',
      ],
      '#prefix' => '<div class="clear-logs-button" style="margin-bottom: 20px;">',
      '#suffix' => '</div>',
    ];

    $headers = [
      $this->t('Timestamp'),
      $this->t('Type'),
      $this->t('Severity'),
      $this->t('Message'),
      $this->t('Operations'),
    ];

    $query = $this->database->select('ai_log_analysis_table', 'cl')
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
        htmlspecialchars($row->type, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        htmlspecialchars((string) $row->severity, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        htmlspecialchars(mb_substr($row->message, 0, 100), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '...',
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

    $build['table'] = [
      '#type' => 'table',
      '#header' => $headers,
      '#rows' => $rows,
      '#empty' => $this->t('No logs found.'),
    ];

    $build['pager'] = [
      '#type' => 'pager',
    ];

    return $build;
  }

  /**
   * Handles analysis of a specific custom log entry by ID.
   *
   * @param int|string $key
   *   The log entry ID.
   *
   * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
   *   A render array with analysis results or a redirect response on error.
   */
  public function analyze($key) {
    $log = $this->database->select('ai_log_analysis_table', 'cl')
      ->fields('cl', ['id', 'timestamp', 'type', 'severity', 'message'])
      ->condition('id', $key)
      ->execute()
      ->fetchAssoc();

    if (!$log) {
      $this->messenger()->addError($this->t('Invalid log entry selected.'));
      return new RedirectResponse(Url::fromRoute('ai_log_analysis.logs')->toString());
    }

    $result = $this->analyzer->analyzeWithAi([$log]);

    $convertBoldMarkdown = function (string $text): string {
      // Remove triple backticks to avoid raw markdown fences in output.
      $text = preg_replace('/```/', '', $text);

      // Escape HTML special chars.
      $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

      // Convert **bold** markdown to <strong>.
      $with_bold = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $escaped);

      // Convert newlines to <br>.
      return nl2br($with_bold);
    };

    $analysis_markup = $convertBoldMarkdown($result['analysis'] ?? $this->t('No analysis available.'));

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

  /**
   * Clears all entries from the ai_log_analysis_table table.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect back to the logs page.
   */
  public function clearLogs(): RedirectResponse {
    $this->database->truncate('ai_log_analysis_table')->execute();
    $this->messenger()->addStatus($this->t('All logs have been cleared.'));
    return new RedirectResponse(Url::fromRoute('ai_log_analysis.logs')->toString());
  }

}
