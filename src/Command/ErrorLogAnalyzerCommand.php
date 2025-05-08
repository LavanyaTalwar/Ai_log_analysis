<?php

namespace Drupal\ai_log_analysis\Command;

use Drupal\ai_log_analysis\Service\LogAnalyzer;
use Drush\Commands\DrushCommands;

/**
 * Provides a Drush command to analyze recent error logs.
 */
class ErrorLogAnalyzerCommand extends DrushCommands {

  /**
   * The log analyzer service.
   *
   * @var \Drupal\ai_log_analysis\Service\LogAnalyzer
   */
  protected $logAnalyzer;

  /**
   * Constructs a new ErrorLogAnalyzerCommand object.
   *
   * @param \Drupal\ai_log_analysis\Service\LogAnalyzer $log_analyzer
   *   The log analyzer service.
   */
  public function __construct(LogAnalyzer $log_analyzer) {
    $this->logAnalyzer = $log_analyzer;
  }

  /**
   * Analyze recent error logs.
   *
   * @command ai_log_analysis:analyze
   * @aliases ala-analyze
   */
  public function analyzeLogs() {
    $logs = $this->logAnalyzer->getRecentDblogs();
  
    if (empty($logs)) {
      $this->output()->writeln("No recent logs found.");
      return;
    }
  
    // Display logs for selection.
    $this->output()->writeln("\n====== 📝 Select a log to analyze ======\n");
  
    foreach ($logs as $index => $log) {
      $summary = substr(strip_tags($log['message']), 0, 80);
      $this->output()->writeln("[$index] {$log['timestamp']} - {$summary}");
    }
  
    // Prompt user to select a log.
    $selected = $this->io()->ask('Enter the number of the log you want to analyze');
  
    if (!is_numeric($selected) || !isset($logs[$selected])) {
      $this->output()->writeln("<error>Invalid selection. Exiting.</error>");
      return;
    }
  
    $selectedLog = [$logs[$selected]];
  
    $result = $this->logAnalyzer->analyzeWithGrok($selectedLog);
  
    // Output AI analysis.
    $this->output()->writeln("\n====== 🧠 AI Analysis ======\n");
  
    // Convert *text* to bold using ANSI escape codes.
    $analysis = preg_replace_callback('/\*(.*?)\*/', function ($matches) {
      return "\033[1m" . $matches[1] . "\033[0m";
    }, $result['analysis']);
  
    $this->output()->writeln($analysis);
  
    // Output code snippets if available.
    if (!empty($result['snippets'])) {
      $this->output()->writeln("\n====== 💻 Code Snippets ======\n");
  
      foreach ($result['snippets'] as $entry) {
        $this->output()->writeln("🕒 Timestamp: {$entry['timestamp']}");
        $this->output()->writeln("📘 Type: {$entry['type']}");
        $this->output()->writeln("⚠️ Severity: {$entry['severity']}");
        $this->output()->writeln("📝 Message: {$entry['message']}");
  
        if (!empty($entry['snippet'])) {
          $this->output()->writeln("📄 Snippet:\n" . $entry['snippet']);
        }
        else {
          $this->output()->writeln("📄 Snippet: Not available.");
        }
  
        $this->output()->writeln(str_repeat('-', 60));
      }
    }
  }
  

}
