<?php
namespace Drupal\ai_log_analysis\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;

/**
 * Service to analyze logs using the contrib ai module (via ai.provider).
 */
class LogAnalyzer {

  protected $database;
  protected $aiProviderManager;
  protected $configFactory;

  public function __construct(Connection $database, AiProviderPluginManager $ai_provider_manager, ConfigFactoryInterface $config_factory) {
    $this->database = $database;
    $this->aiProviderManager = $ai_provider_manager;
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

  public function analyzeWithAi(array $logs): array {
    if (empty($logs)) {
      return [
        'analysis' => 'No logs available for analysis.',
        'snippets' => [],
      ];
    }

    $logsToSend = $logs;
    $prompt = $this->buildPrompt($logsToSend);
    $logDetails = $this->extractLogDetails($logsToSend);
    $response = $this->callAiProvider($prompt);

    return $this->handleAiResponse($response, $logDetails);
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

  protected function callAiProvider(string $prompt): ?ChatMessage {
    try {
      // Use the default provider (and model) as configured in the ai module (via ai.settings) for the chat operation type.
      $defaultProvider = $this->aiProviderManager->getDefaultProviderForOperationType("chat");
      if (empty($defaultProvider) || !isset($defaultProvider['provider_id']) || !isset($defaultProvider['model_id'])) {
         \Drupal::logger('ai_log_analysis')->error("No default provider (or model) configured for chat operation type.");
         return NULL;
      }
      $provider = $this->aiProviderManager->createInstance($defaultProvider['provider_id']);
      // Optionally, set a system role (if desired).
      $provider->setChatSystemRole("You are a helpful assistant analyzing Drupal logs.");
      // Create a ChatInput with a single ChatMessage (role "user" and content $prompt).
      $input = new ChatInput([new ChatMessage("user", $prompt)]);
      // Call the chat method (using the default model) and get a normalized ChatMessage.
      $response = $provider->chat($input, $defaultProvider['model_id'], ['ai_log_analysis'])->getNormalized();
      return $response;
    } catch (\Exception $e) {
      \Drupal::logger('ai_log_analysis')->error("AI provider error: @message", ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

  protected function handleAiResponse(?ChatMessage $response, array $logDetails): array {
    if ($response) {
      return [
        'analysis' => $response->getText(),
        'snippets' => $logDetails,
      ];
    }
    return [
      'analysis' => 'No valid response from AI provider.',
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
