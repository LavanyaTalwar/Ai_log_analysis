<?php
namespace Drupal\ai_log_analysis\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\Client;

/**
 * Service to analyze logs using Grok AI.
 */
class LogAnalyzer {

  protected $database;
  protected $httpClient;
  protected $configFactory;

  public function __construct(Connection $database, Client $http_client, ConfigFactoryInterface $config_factory) {
    $this->database = $database;
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
  }

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
        if (is_array($variables)) {
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

  public function analyzeWithGrok(array $logs): array {
    if (empty($logs)) {
      return [
        'analysis' => 'No logs available for analysis.',
        'snippets' => [],
      ];
    }

    $logsToSend = $logs;
    $prompt = $this->buildPrompt($logsToSend);
    $logDetails = $this->extractLogDetails($logsToSend);
    $response = $this->callGrokApi($prompt);

    return $this->handleGrokResponse($response, $logDetails);
  }

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
      \Drupal::logger('ai_log_analysis')->error('Grok API error: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

  protected function handleGrokResponse(?array $response, array $logDetails): array {
    if (!empty($response['choices'][0]['message']['content'])) {
      return [
        'analysis' => $response['choices'][0]['message']['content'],
        'snippets' => $logDetails,
      ];
    }
    return [
      'analysis' => 'No valid response from Grok AI.',
      'snippets' => $logDetails,
    ];
  }

  protected function extractLogDetails(array $logs): array {
    $details = [];
    foreach ($logs as $log) {
      $snippet = $this->getCodeSnippetFromLog($log['message']);
      $details[] = $log + ['snippet' => $snippet];
    }
    return $details;
  }

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
      $lines = file($filePath);
      $start = max(0, $lineNumber - 11);
      $end = min(count($lines) - 1, $lineNumber + 9);

      $snippet = "Code snippet from $filePath around line $lineNumber:\n\n";
      for ($i = $start; $i <= $end; $i++) {
        $prefix = ($i + 1 === $lineNumber) ? '>> ' : '   ';
        $snippet .= $prefix . str_pad($i + 1, 4, ' ', STR_PAD_LEFT) . ': ' . $lines[$i];
      }
      return $snippet;
    }

    return NULL;
  }

}
