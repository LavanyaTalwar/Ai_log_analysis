<?php

declare(strict_types=1);

namespace Drupal\ai_log_analysis\Logger;

use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LogMessageParserInterface;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\Core\Config\ConfigFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Drupal\Component\Serialization\Json;

/**
 * Custom logger that logs to the custom_log table with rate limiting.
 *
 * This logger captures all log messages from all channels and stores them
 * in the custom_log table, independent of the dblog module.
 */
class CustomLogger implements LoggerInterface {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * The message parser for interpolating placeholders.
   *
   * @var \Drupal\Core\Logger\LogMessageParserInterface
   */
  protected LogMessageParserInterface $parser;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * Collection of forwarded loggers.
   *
   * @var array<\Psr\Log\LoggerInterface>
   */
  protected array $loggers = [];

  /**
   * Recent logs for rate limiting.
   *
   * @var array<string,int>
   */
  protected array $recentLogs = [];

  /**
   * Rate limit window in seconds (default 5 minutes).
   *
   * @var int
   */
  protected int $rateLimitWindow = 300;

  /**
   * Patterns to ignore in log messages.
   *
   * @var array<string>
   */
  protected array $ignoredPatterns = [
    'Since symfony/dependency-injection',
    'stat(): stat failed for',
    'yaml_parser_class',
    'The "yaml_parser_class" setting is deprecated',
  ];

  /**
   * Guard against re-entrancy.
   *
   * @var bool
   */
  protected bool $inLog = FALSE;

  /**
   * Constructs a CustomLogger object.
   */
  public function __construct(
    Connection $database,
    LogMessageParserInterface $parser,
    ConfigFactoryInterface $config_factory,
  ) {
    $this->database = $database;
    $this->parser = $parser;
    $this->configFactory = $config_factory;

    // Register PHP error handler with filtering.
    set_error_handler(function ($severity, $message, $file, $line) {
      // Skip ignored patterns.
      foreach ($this->ignoredPatterns as $pattern) {
        if (strpos($message, $pattern) !== FALSE) {
          return TRUE;
        }
      }

      // Only log if severity is high enough.
      if ($severity <= E_USER_WARNING) {
        \Drupal::logger('php')->error('%message in %file on line %line', [
          '%message' => $message,
          '%file' => $file,
          '%line' => $line,
          'severity' => $severity,
          'channel' => 'php',
          'request_uri' => \Drupal::request()->getRequestUri(),
          'ip' => \Drupal::request()->getClientIp(),
        ]);
      }
      return TRUE;
    });

    // Register uncaught exception handler with filtering.
    set_exception_handler(function (\Throwable $e) {
      $message = $e->getMessage();

      // Skip ignored patterns.
      foreach ($this->ignoredPatterns as $pattern) {
        if (strpos($message, $pattern) !== FALSE) {
          // Skip logging.
          return;
        }
      }

      \Drupal::logger('php')->critical('Uncaught exception: %message in %file on line %line', [
        '%message' => $message,
        '%file' => $e->getFile(),
        '%line' => $e->getLine(),
        'exception' => $e,
        'channel' => 'php',
        'request_uri' => \Drupal::request()->getRequestUri(),
        'ip' => \Drupal::request()->getClientIp(),
      ]);
    });

    // Register shutdown handler for fatal errors with filtering.
    register_shutdown_function(function () {
      $error = error_get_last();
      if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE], TRUE)) {
        // Skip ignored patterns.
        foreach ($this->ignoredPatterns as $pattern) {
          if (strpos($error['message'], $pattern) !== FALSE) {
            // Skip logging.
            return;
          }
        }

        \Drupal::logger('php')->critical('Fatal error: %message in %file on line %line', [
          '%message' => $error['message'],
          '%file' => $error['file'],
          '%line' => $error['line'],
          'channel' => 'php',
          'request_uri' => \Drupal::request()->getRequestUri(),
          'ip' => \Drupal::request()->getClientIp(),
        ]);
      }
    });
  }

  /**
   * {@inheritdoc}
   */
  public function emergency(string|\Stringable $message, array $context = []): void {
    $this->log(LogLevel::EMERGENCY, $message, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function alert(string|\Stringable $message, array $context = []): void {
    $this->log(LogLevel::ALERT, $message, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function critical(string|\Stringable $message, array $context = []): void {
    $this->log(LogLevel::CRITICAL, $message, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function error(string|\Stringable $message, array $context = []): void {
    $this->log(LogLevel::ERROR, $message, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function warning(string|\Stringable $message, array $context = []): void {
    $this->log(LogLevel::WARNING, $message, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function notice(string|\Stringable $message, array $context = []): void {
    $this->log(LogLevel::NOTICE, $message, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function info(string|\Stringable $message, array $context = []): void {
    $this->log(LogLevel::INFO, $message, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function debug(string|\Stringable $message, array $context = []): void {
    $this->log(LogLevel::DEBUG, $message, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function log($level, string|\Stringable $message, array $context = []): void {
    // Prevent recursion if already inside log()
    if ($this->inLog) {
      return;
    }
    $this->inLog = TRUE;

    try {
      // Skip ignored patterns.
      $messageStr = (string) $message;
      foreach ($this->ignoredPatterns as $pattern) {
        if (strpos($messageStr, $pattern) !== FALSE) {
          $this->inLog = FALSE;
          return;
        }
      }

      // Parse level name.
      $severity = is_numeric($level)
        ? (RfcLogLevel::getLevels()[$level] ?? 'unknown')
        : $level;

      // Determine channel.
      $channel = $context['channel'] ?? 'system';

      // Rate-limit identical messages.
      $key = md5($channel . '|' . $messageStr);
      $now = \Drupal::time()->getCurrentTime();

      // Prune old entries.
      $this->recentLogs = array_filter(
        $this->recentLogs,
        fn($timestamp) => ($now - $timestamp) < $this->rateLimitWindow
      );

      if (isset($this->recentLogs[$key])) {
        $this->inLog = FALSE;
        return;
      }
      $this->recentLogs[$key] = $now;

      // Interpolate placeholders.
      $placeholders = $this->parser->parseMessagePlaceholders($messageStr, $context);
      $interpolated = strtr($messageStr, $placeholders);

      // Insert to custom_log.
      $this->database->insert('custom_log')
        ->fields([
          'type' => $channel,
          'message' => $interpolated,
          'severity' => $severity,
          'link' => $context['link'] ?? '',
          'location' => $context['request_uri'] ?? '',
          'referer' => $context['referer'] ?? '',
          'hostname' => $context['ip'] ?? '',
          'timestamp' => $now,
          'variables' => !empty($context) ? $this->safeSerialize($context) : NULL,
        ])
        ->execute();

      // Enforce log retention policy.
      $count = $this->database->select('custom_log', 'cl')
        ->countQuery()
        ->execute()
        ->fetchField();

      $limit = 1000;
      if ($count > $limit) {
        $subquery = $this->database->select('custom_log', 'cl2')
          ->fields('cl2', ['id'])
          ->orderBy('timestamp', 'DESC')
          ->range(0, $limit);

        $this->database->delete('custom_log')
          ->condition('id', $subquery, 'NOT IN')
          ->execute();
      }

      // Forward to other loggers.
      foreach ($this->loggers as $logger) {
        $logger->log($level, $message, $context);
      }
    }
    catch (\Exception $e) {
      // Write to PHP error log to avoid recursion.
      error_log('ai_log_analysis failed to write to custom_log: ' . $e->getMessage());
    }
    finally {
      $this->inLog = FALSE;
    }
  }

  /**
   * Allow other loggers to be added for forwarding.
   */
  public function addLogger(LoggerInterface $logger): void {
    $this->loggers[] = $logger;
  }

  /**
   * Serializes the given data safely.
   */
  protected function safeSerialize($data): string {
    try {
      return Json::encode($data);
    }
    catch (\Exception $e) {
      return serialize($data);
    }
  }

}
