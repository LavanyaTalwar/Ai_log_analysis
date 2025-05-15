<?php

namespace Drupal\ai_log_analysis\Logger;

use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LogMessageParserInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Custom logger that logs to the custom_log table.
 */
class CustomLogger implements LoggerInterface {

  protected Connection $database;
  protected LogMessageParserInterface $parser;

  public function __construct(Connection $database, LogMessageParserInterface $parser) {
    $this->database = $database;
    $this->parser = $parser;
  }

  public function emergency(string|\Stringable $message, array $context = []): void {
    $this->log(LogLevel::EMERGENCY, $message, $context);
  }

  public function alert(string|\Stringable $message, array $context = []): void {
    $this->log(LogLevel::ALERT, $message, $context);
  }

  public function critical(string|\Stringable $message, array $context = []): void {
    $this->log(LogLevel::CRITICAL, $message, $context);
  }

  public function error(string|\Stringable $message, array $context = []): void {
    $this->log(LogLevel::ERROR, $message, $context);
  }

  public function warning(string|\Stringable $message, array $context = []): void {
    $this->log(LogLevel::WARNING, $message, $context);
  }

  public function notice(string|\Stringable $message, array $context = []): void {
    $this->log(LogLevel::NOTICE, $message, $context);
  }

  public function info(string|\Stringable $message, array $context = []): void {
    $this->log(LogLevel::INFO, $message, $context);
  }

  public function debug(string|\Stringable $message, array $context = []): void {
    $this->log(LogLevel::DEBUG, $message, $context);
  }

  public function log($level, string|\Stringable $message, array $context = []): void {
    unset($context['backtrace'], $context['exception']);

    // Parse placeholders (e.g. replace @username with actual value).
    $placeholders = $this->parser->parseMessagePlaceholders($message, $context);

    // Interpolate message placeholders with values.
    $interpolated_message = strtr($message, $placeholders);

    // Insert new log entry.
    $this->database->insert('custom_log')
      ->fields([
        'type' => $context['channel'] ?? 'custom_logger',
        'message' => $interpolated_message,
        'severity' => $level,
        'link' => $context['link'] ?? '',
        'location' => $context['request_uri'] ?? '',
        'referer' => $context['referer'] ?? '',
        'hostname' => $context['ip'] ?? '',
        'timestamp' => \Drupal::time()->getCurrentTime(),
      ])
      ->execute();

    // Check total log count.
    $count = $this->database->select('custom_log', 'cl')
      ->countQuery()
      ->execute()
      ->fetchField();

    if ($count > 1000) {
      $limit = 1000;

      $subquery = $this->database->select('custom_log', 'cl2')
        ->fields('cl2', ['id'])
        ->orderBy('timestamp', 'DESC')
        ->range(0, $limit);

      $this->database->delete('custom_log')
        ->condition('id', $subquery, 'NOT IN')
        ->execute();
    }
  }

}
