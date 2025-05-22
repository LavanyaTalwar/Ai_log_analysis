<?php

namespace Drupal\ai_log_analysis\Command;

use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Question\Question;
use Drupal\ai_log_analysis\Service\LogAnalyzer;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Provides a Drush command to analyze recent error logs using AI module.
 */
class ErrorLogAnalyzerCommand extends DrushCommands {

  /**
   * The log analyzer service.
   *
   * @var \Drupal\ai_log_analysis\Service\LogAnalyzer
   */
  protected LogAnalyzer $logAnalyzer;

  /**
   * Constructs a new ErrorLogAnalyzerCommand object.
   *
   * @param \Drupal\ai_log_analysis\Service\LogAnalyzer $log_analyzer
   *   The log analyzer service.
   */
  public function __construct(LogAnalyzer $log_analyzer) {
    parent::__construct();
    $this->logAnalyzer = $log_analyzer;
  }

  /**
   * Define CLI options and mark them as accepting values.
   *
   * @var array
   */
  protected $options = [
    'severity' => [
      'description' => 'Filter logs by severity level (e.g., error).',
      'value' => 'required',
    ],
    'start_date' => [
      'description' => 'Start date for logs (YYYY-MM-DD).',
      'value' => 'required',
    ],
    'end_date' => [
      'description' => 'End date for logs (YYYY-MM-DD).',
      'value' => 'required',
    ],
  ];

  /**
   * Analyze recent error logs using AI module.
   *
   * @command ai_log_analysis:analyze
   * @aliases ala-analyze
   *
   * @option severity Filter logs by severity level (e.g., error).
   * @option start_date Start date for logs (YYYY-MM-DD).
   * @option end_date End date for logs (YYYY-MM-DD).
   *
   * @usage drush ai_log_analysis:analyze --severity=error
   *   Analyze recent error logs with severity "error".
   */
  public function analyzeLogs(InputInterface $input, OutputInterface $output): void {
    $severity = $input->getOption('severity');
    $start_date = $input->getOption('start_date');
    $end_date = $input->getOption('end_date');
    // Fetch recent logs with optional filters.
    $logs = $this->logAnalyzer->getRecentDblogs(
    // Default limit of 10 logs.
      10,
      $severity ? (string) $severity : NULL,
      $start_date,
      $end_date
    );

    if (empty($logs)) {
      $output->writeln("<comment>No recent logs found with the specified criteria.</comment>");
      return;
    }

    $output->writeln("\n====== 📝 Select a log to analyze ======\n");

    foreach ($logs as $index => $log) {
      $summary = mb_substr(strip_tags($log['message']), 0, 80);
      $output->writeln("[$index] {$log['timestamp']} - {$summary}");
    }

    // Use Symfony's Question helper directly.
    $question = new Question('Enter the number of the log you want to analyze: ');
    $questionHelper = new QuestionHelper();
    $selected = $questionHelper->ask($input, $output, $question);

    if (!is_numeric($selected) || !isset($logs[$selected])) {
      $output->writeln("<error>Invalid selection. Exiting.</error>");
      return;
    }

    $selectedLog = [$logs[(int) $selected]];

    try {
      // Use the AI-based analysis method from LogAnalyzer service.
      $result = $this->logAnalyzer->analyzeWithAi($selectedLog);
    }
    catch (\Exception $e) {
      $output->writeln("<error>AI analysis failed: {$e->getMessage()}</error>");
      return;
    }

    $output->writeln("\n====== 🧠 AI Analysis ======\n");

    // Make *text* bold in output.
    $analysis = preg_replace_callback('/\*(.*?)\*/', function ($matches) {
      return "\033[1m" . $matches[1] . "\033[0m";
    }, $result['analysis']);

    $output->writeln($analysis);

    if (!empty($result['snippets'])) {
      $output->writeln("\n====== 💻 Code Snippets ======\n");

      foreach ($result['snippets'] as $entry) {
        $output->writeln("🕒 Timestamp: {$entry['timestamp']}");
        $output->writeln("📘 Type: {$entry['type']}");
        $output->writeln("⚠️ Severity: {$entry['severity']}");
        $output->writeln("📝 Message: {$entry['message']}");

        $snippet = $entry['snippet'] ?? 'Not available.';
        $output->writeln("📄 Snippet:\n" . $snippet);

        $output->writeln(str_repeat('-', 60));
      }
    }
  }

}
