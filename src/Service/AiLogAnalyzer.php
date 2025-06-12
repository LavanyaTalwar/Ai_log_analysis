<?php

namespace Drupal\ai_log_analysis\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Service to analyze logs using the contrib AI module (via ai.provider).
 */
class AiLogAnalyzer {
  /**
   * The database connection used for storing and retrieving log entries.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * The AI provider plugin manager for discovering and instantiating plugins.
   *
   * @var \Drupal\ai\AiProviderPluginManager
   */
  protected AiProviderPluginManager $ai_provider_manager;

  /**
   * The configuration factory service for accessing configuration objects.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $config_factory;

  /**
   * The logger channel factory service for retrieving logger channels.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected LoggerChannelFactoryInterface $logger_factory;

  /**
   * Constructs the AiLogAnalyzer service.
   */
  public function __construct(
    Connection $database,
    AiProviderPluginManager $ai_provider_manager,
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->database = $database;
    $this->ai_provider_manager = $ai_provider_manager;
    $this->config_factory = $config_factory;
    $this->logger_factory = $logger_factory;
  }

  /**
   * Fetches recent custom log entries with optional filtering.
   *
   * @param int $limit
   *   The number of recent logs to retrieve.
   * @param string|null $severity
   *   Optional severity level to filter logs (e.g. 'error', 'warning').
   * @param string|null $start_date
   *   Optional start date (Y-m-d) to filter logs from.
   * @param string|null $end_date
   *   Optional end date (Y-m-d) to filter logs until.
   *
   * @return array
   *   An array of log entry data.
   */
  public function getRecentDblogs(int $limit = 10, ?string $severity = NULL, ?string $start_date = NULL, ?string $end_date = NULL): array {
    $query = $this->database->select('ai_log_analysis', 'w')
      ->fields('w', ['id', 'type', 'message', 'severity', 'timestamp'])
      ->orderBy('timestamp', 'DESC');

    if ($severity !== NULL) {
      $query->condition('severity', $severity);
    }

    if (!empty($start_date)) {
      $start_timestamp = strtotime($start_date);
      if ($start_timestamp !== FALSE) {
        $query->condition('timestamp', $start_timestamp, '>=');
      }
    }

    if (!empty($end_date)) {
      $end_timestamp = strtotime($end_date . ' 23:59:59');
      if ($end_timestamp !== FALSE) {
        $query->condition('timestamp', $end_timestamp, '<=');
      }
    }

    $query->range(0, $limit);

    $results = $query->execute()->fetchAll();

    $logs = [];
    foreach ($results as $row) {
      $message = $row->message;

      if (!empty($row->variables)) {
        $variables = @unserialize($row->variables, ['allowed_classes' => FALSE]);
        if (is_array($variables)) {
          $safe_variables = [];
          foreach ($variables as $key => $value) {
            if (is_scalar($value)) {
              $safe_variables[$key] = $value;
            }
          }
          $message = strtr($message, $safe_variables);
        }
      }

      $logs[] = [
        'type' => htmlspecialchars($row->type, ENT_QUOTES, 'UTF-8'),
        'message' => htmlspecialchars($message, ENT_QUOTES, 'UTF-8'),
        'severity' => (int) $row->severity,
        'timestamp' => date('Y-m-d H:i:s', (int) $row->timestamp),
      ];
    }

    return $logs;
  }

  /**
   * Analyze logs using AI.
   *
   * @param array $logs
   *   The log entries to analyze.
   *
   * @return array
   *   AI analysis response with optional code snippets.
   */
  public function analyzeWithAi(array $logs): array {
    if (empty($logs)) {
      return [
        'analysis' => 'No logs available for analysis.',
        'snippets' => [],
      ];
    }

    $prompt = $this->buildPrompt($logs);
    $log_details = $this->extractLogDetails($logs);
    $response = $this->callAiProvider($prompt);

    return $this->handleAiResponse($response, $log_details);
  }

  /**
   * Builds the prompt to send to the AI provider.
   */
  protected function buildPrompt(array $logs): string {
    $prompt = "🚨 The Drupal site has encountered errors. Analyze the logs below and suggest causes and fixes. Format your response:\n\n";

    foreach ($logs as $log) {
      $snippet = $this->getCodeSnippetFromLog($log['message']);
      if ($snippet) {
        $prompt .= "📄 Code Snippet:\n$snippet\n";
      }
      $prompt .= "🕒 Timestamp: {$log['timestamp']}\n";
      $prompt .= "📘 Type: {$log['type']}\n";
      $prompt .= "⚠️ Severity: {$log['severity']}\n";
      $prompt .= "📝 Message: {$log['message']}\n";
      $prompt .= "----------------------------------------\n";
    }

    $prompt .= "\n🔎 Please analyze the above logs and suggest potential causes and solutions.";
    return $prompt;
  }

  /**
   * Calls the AI provider for analysis.
   */
  protected function callAiProvider(string $prompt): ?ChatMessage {
    try {
      $default_provider = $this->ai_provider_manager->getDefaultProviderForOperationType("chat");

      if (empty($default_provider['provider_id']) || empty($default_provider['model_id'])) {
        $this->logger_factory->get('ai_log_analysis')->error("No default AI provider or model configured.");
        return NULL;
      }

      $provider = $this->ai_provider_manager->createInstance($default_provider['provider_id']);
      $provider->setChatSystemRole("You are a helpful assistant analyzing Drupal logs.");
      $input = new ChatInput([new ChatMessage("user", $prompt)]);

      return $provider->chat($input, $default_provider['model_id'], ['ai_log_analysis'])->getNormalized();
    }
    catch (\Exception $e) {
      $this->logger_factory->get('ai_log_analysis')->error("AI provider error: @message", ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Handles AI response and formats the result.
   */
  protected function handleAiResponse(?ChatMessage $response, array $log_details): array {
    if ($response) {
      return [
        'analysis' => $response->getText(),
        'snippets' => $log_details,
      ];
    }
    return [
      'analysis' => 'No valid response from AI provider.',
      'snippets' => $log_details,
    ];
  }

  /**
   * Extracts relevant code snippets from logs.
   */
  protected function extractLogDetails(array $logs): array {
    $details = [];
    foreach ($logs as $log) {
      $snippet = $this->getCodeSnippetFromLog($log['message']);
      $details[] = $log + ['snippet' => $snippet];
    }
    return $details;
  }

  /**
   * Extracts code snippet from a log message if possible.
   */
  public function getCodeSnippetFromLog(string $message): ?string {
    $file_path = '';
    $line_number = 0;

    if (preg_match('/in ([^\s]+\.php) on line (\d+)/', $message, $matches)) {
      $file_path = $matches[1];
      $line_number = (int) $matches[2];
    }
    elseif (preg_match('/\(line (\d+) of ([^)]+\.php)\)/', $message, $matches)) {
      $line_number = (int) $matches[1];
      $file_path = $matches[2];
    }

    if ($file_path && is_file($file_path)) {
      $lines = @file($file_path, FILE_IGNORE_NEW_LINES);
      if (!$lines) {
        return NULL;
      }

      $start = max(0, $line_number - 11);
      $end = min(count($lines) - 1, $line_number + 9);

      $snippet = "Code snippet from $file_path around line $line_number:\n\n";
      for ($i = $start; $i <= $end; $i++) {
        $prefix = ($i + 1 === $line_number) ? '>> ' : '   ';
        $snippet .= $prefix . str_pad($i + 1, 4, ' ', STR_PAD_LEFT) . ': ' . $lines[$i] . "\n";
      }
      return $snippet;
    }

    return NULL;
  }

}
