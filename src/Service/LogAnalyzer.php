<?php

namespace Drupal\ai_log_analysis\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Config\ConfigFactoryInterface;
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
   * Constructs the LogAnalyzer service.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \GuzzleHttp\Client $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   */
  public function __construct(Connection $database, Client $http_client, ConfigFactoryInterface $config_factory) {
    $this->database = $database;
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
  }

  /**
   * Fetches recent dblog entries.
   *
   * @param int $limit
   *   The number of logs to fetch.
   *
   * @return array
   *   An array of recent dblog entries.
   */
  public function getRecentDblogs($limit = 10) {
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
   * Analyzes logs using Grok AI.
   *
   * @param array $logs
   *   An array of logs to analyze.
   *
   * @return string
   *   The AI analysis result.
   */
  public function analyzeWithGrok(array $logs): array {
    $config = $this->configFactory->get('ai_log_analysis.settings');
    $apiKey = $config->get('grok_api_key');

    if (empty($logs)) {
      return [
        'analysis' => 'No dblog entries available for analysis.',
        'snippets' => [],
      ];
    }

    $maxLogs = 5;
    $logsToSend = array_slice($logs, 0, $maxLogs);

    $prompt = "🚨 The Drupal site has encountered errors. Analyze the logs below and suggest causes and fixes. Format your response with these rules:
1. Use clear heading hierarchy:
   - Main headings in UPPERCASE (e.g., 'ERROR ANALYSIS')
   - Subheadings with > prefix (e.g., '> Connection Issues')
   - Sub-points with - prefix
2. Add a blank line between sections for better readability
3. Use dashes (-) for bullet points
4. If you need to emphasize text, use stars around it (e.g., *important text*) and it will be highlighted
5. Indent sub-points under their parent heading\n\n";
    $logDetails = [];

    foreach ($logsToSend as $log) {
      $snippet = $this->getCodeSnippetFromLog($log['message']);

      $prompt .= $snippet ? "📄 Code Snippet:\n$snippet\n" : '';
      $prompt .= "🕒 Timestamp: {$log['timestamp']}\n";
      $prompt .= "📘 Type: {$log['type']}\n";
      $prompt .= "⚠️ Severity: {$log['severity']}\n";
      $prompt .= "📝 Message: {$log['message']}\n";
      $prompt .= "----------------------------------------\n";

      // Store for printing later on the page.
      $logDetails[] = [
        'timestamp' => $log['timestamp'],
        'type' => $log['type'],
        'severity' => $log['severity'],
        'message' => $log['message'],
        'snippet' => $snippet,
      ];
    }

    $prompt .= "\n🔎 Please analyze the above logs and suggest potential causes and solutions.";

    if (session_status() === PHP_SESSION_ACTIVE) {
      session_write_close();
    }

    try {
      $response = $this->httpClient->post('https://api.groq.com/openai/v1/chat/completions', [
        'headers' => [
          'Authorization' => 'Bearer ' . $apiKey,
          'Content-Type' => 'application/json',
        ],
        'json' => [
          'model' => 'llama3-8b-8192',
          'messages' => [
            [
              'role' => 'user',
              'content' => $prompt,
            ],
          ],
          'max_tokens' => 800,
        ],
      ]);

      $body = json_decode($response->getBody()->getContents(), TRUE);

      if (isset($body['choices'][0]['message']['content'])) {
        return [
          'analysis' => $body['choices'][0]['message']['content'],
          'snippets' => $logDetails,
        ];
      }
      else {
        \Drupal::logger('ai_log_analysis')->error('Grok AI returned an unexpected response: @response', ['@response' => print_r($body, TRUE)]);
        return [
          'analysis' => 'Unexpected response from Grok AI.',
          'snippets' => $logDetails,
        ];
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('ai_log_analysis')->error('Error calling Grok AI: @message', ['@message' => $e->getMessage()]);
      return [
        'analysis' => 'Error calling Grok AI: ' . $e->getMessage(),
        'snippets' => $logDetails,
      ];
    }
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
    // Try traditional format first: "in /path/to/file.php on line 123"
    if (preg_match('/in ([^\s]+\.php) on line (\d+)/', $message, $matches)) {
      $filePath = $matches[1];
      $lineNumber = (int) $matches[2];
    }
    // Try alternative format: "(line 6 of /path/to/file.php)"
    elseif (preg_match('/\(line (\d+) of ([^)]+\.php)\)/', $message, $matches)) {
      $lineNumber = (int) $matches[1];
      $filePath = $matches[2];
    }
    else {
      return NULL;
    }

    // Check if the file exists.
    if (file_exists($filePath)) {
      $fileLines = file($filePath); // Read file lines into array
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
