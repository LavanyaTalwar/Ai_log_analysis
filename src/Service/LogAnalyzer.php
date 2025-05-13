<?php

namespace Drupal\ai_log_analysis\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use GuzzleHttp\Client;

/**
 * Service to analyze logs using Grok AI.
 */
class LogAnalyzer {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The HTTP client for making API requests.
   *
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * Constructs the LogAnalyzer service.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \GuzzleHttp\Client $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache backend.
   */
  public function __construct(Connection $database, Client $http_client, ConfigFactoryInterface $config_factory, CacheBackendInterface $cache) {
    $this->database = $database;
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
    $this->cache = $cache;
  }

  /**
   * Fetches recent log entries from the custom_log table.
   *
   * @param int $limit
   *   The number of logs to fetch.
   *
   * @return array
   *   An array of log entries.
   */
  public function getRecentDblogs($limit = 10): array {
    $query = $this->database->select('custom_log', 'w')
      ->fields('w', ['id', 'type', 'message', 'severity', 'timestamp'])
      ->orderBy('timestamp', 'DESC')
      ->range(0, $limit);

    $results = $query->execute()->fetchAll();

    $logs = [];
    foreach ($results as $row) {
      $message = $row->message;
      if (!empty($row->variables)) {
        $variables = @unserialize($row->variables, ['allowed_classes' => FALSE]);
        if ($variables && is_array($variables)) {
          $message = strtr($message, $variables);
        }
      }

      $logs[] = [
        'type' => $row->type,
        'message' => $message,
        'severity' => $row->severity,
        'timestamp' => date('Y-m-d H:i:s', $row->timestamp),
      ];
    }

    return $logs;
  }

  /**
   * Analyzes logs using Grok AI with caching.
   *
   * @param array $logs
   *   An array of logs to analyze.
   *
   * @return array
   *   An associative array with analysis and log snippets.
   */
  public function analyzeWithGrok(array $logs): array {
    if (empty($logs)) {
      return [
        'analysis' => 'No dblog entries available for analysis.',
        'snippets' => [],
      ];
    }

    $logsToSend = $this->prepareLogsForAnalysis($logs);
    $cacheKey = 'grok_analysis:' . md5(serialize($logsToSend));

    if ($cached = $this->cache->get($cacheKey)) {
      return $cached->data;
    }

    $prompt = $this->buildPrompt($logsToSend);
    $logDetails = $this->extractLogDetails($logsToSend);
    $response = $this->callGrokApi($prompt);
    $result = $this->handleGrokResponse($response, $logDetails);

    $this->cache->set($cacheKey, $result, time() + 3600); // Cache for 1 hour.
    return $result;
  }

  /**
   * Prepares logs for analysis by slicing to max 5 entries.
   *
   * @param array $logs
   *   All available logs.
   *
   * @return array
   *   Logs trimmed to 5 most recent entries.
   */
  protected function prepareLogsForAnalysis(array $logs): array {
    return array_slice($logs, 0, 5);
  }

  /**
   * Builds the prompt string for Grok AI based on logs.
   *
   * @param array $logs
   *   Logs to include in the prompt.
   *
   * @return string
   *   The constructed prompt.
   */
  protected function buildPrompt(array $logs): string {
    $prompt = "🚨 The Drupal site has encountered errors. Analyze the logs below and suggest causes and fixes. Format your response with these rules:
               1. Use clear heading hierarchy:
                  - Main headings in UPPERCASE
                  - Subheadings with > prefix
                  - Sub-points with - prefix
               2. Add a blank line between sections
               3. Use dashes (-) for bullet points
               4. Emphasize with *stars* for highlights
               5. Indent sub-points properly\n\n";

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
   * Calls Grok API with the given prompt.
   *
   * @param string $prompt
   *   The full prompt string.
   *
   * @return array|null
   *   Decoded API response or NULL on failure.
   */
  protected function callGrokApi(string $prompt): ?array {
    try {
      $apiKey = $this->configFactory->get('ai_log_analysis.settings')->get('grok_api_key');

      if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
      }

      $response = $this->httpClient->post('https://api.groq.com/openai/v1/chat/completions', [
        'headers' => [
          'Authorization' => 'Bearer ' . $apiKey,
          'Content-Type' => 'application/json',
        ],
        'json' => [
          'model' => 'llama3-8b-8192',
          'messages' => [['role' => 'user', 'content' => $prompt]],
          'max_tokens' => 800,
        ],
      ]);

      return json_decode($response->getBody()->getContents(), TRUE);
    }
    catch (\Exception $e) {
      \Drupal::logger('ai_log_analysis')->error('Error calling Grok AI: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Processes the API response and formats the output.
   *
   * @param array|null $response
   *   The decoded response from Grok.
   * @param array $logDetails
   *   The array of processed log details.
   *
   * @return array
   *   The final analysis output.
   */
  protected function handleGrokResponse(?array $response, array $logDetails): array {
    if ($response && isset($response['choices'][0]['message']['content'])) {
      return [
        'analysis' => $response['choices'][0]['message']['content'],
        'snippets' => $logDetails,
      ];
    }
    else {
      return [
        'analysis' => 'Unexpected or no response from Grok AI.',
        'snippets' => $logDetails,
      ];
    }
  }

  /**
   * Extracts detailed information and snippets for each log.
   *
   * @param array $logs
   *   The logs to extract from.
   *
   * @return array
   *   Logs enriched with code snippets.
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
   * Extracts code snippet (±10 lines) from a file based on an error log message.
   *
   * @param string $message
   *   The log message.
   *
   * @return string|null
   *   The code snippet or NULL if not applicable.
   */
  public function getCodeSnippetFromLog(string $message): ?string {
    if (preg_match('/in ([^\s]+\.php) on line (\d+)/', $message, $matches)) {
      $filePath = $matches[1];
      $lineNumber = (int) $matches[2];
    }
    elseif (preg_match('/\(line (\d+) of ([^)]+\.php)\)/', $message, $matches)) {
      $lineNumber = (int) $matches[1];
      $filePath = $matches[2];
    }
    else {
      return NULL;
    }

    if (file_exists($filePath)) {
      $fileLines = file($filePath);
      $start = max(0, $lineNumber - 11);
      $end = min(count($fileLines) - 1, $lineNumber + 9);

      $snippet = "Code snippet from $filePath around line $lineNumber:\n\n";
      for ($i = $start; $i <= $end; $i++) {
        $prefix = ($i + 1 === $lineNumber) ? '>> ' : '   ';
        $snippet .= $prefix . str_pad($i + 1, 4, ' ', STR_PAD_LEFT) . ': ' . $fileLines[$i];
      }
      return $snippet;
    }

    return NULL;
  }

}
