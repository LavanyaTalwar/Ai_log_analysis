<?php

declare(strict_types=1);

namespace Drupal\ai_log_analysis\Logger;

use Drupal\Core\Database\Connection;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\Logger\LogMessageParserInterface;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\Core\Config\ConfigFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Component\Datetime\TimeInterface;

/**
 * AiLogAnalyzerLogger that logs to the ai_log_analysis table with rate limiting.
 *
 * This logger captures all log messages from all channels and stores them
 * in the ai_log_analysis table, independent of the dblog module.
 */
class AiLogAnalyzerLogger implements LoggerInterface {

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
   * The request stack service to access the current request.
   *
   * Used to retrieve data like request URI and client IP
   * without using \Drupal::request().
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected RequestStack $requestStack;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected TimeInterface $time;

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
    '/mkdir\(\): File exists/',
    '/Since symfony\/dependency-injection/',
    '/stat\(\): stat failed for/',
    '/yaml_parser_class/',
    '/The "yaml_parser_class" setting is deprecated/',
  ];

  /**
   * Guard against re-entrancy.
   *
   * @var bool
   */
  protected bool $inLog = FALSE;

  /**
   * Constructs a AiLogAnalyzerLogger object.
   */
  public function __construct(
    Connection $database,
    LogMessageParserInterface $parser,
    ConfigFactoryInterface $config_factory,
    RequestStack $request_stack,
    LoggerChannelFactoryInterface $logger_factory,
    TimeInterface $time,
  ) {
    $this->database = $database;
    $this->parser = $parser;
    $this->configFactory = $config_factory;
    $this->requestStack = $request_stack;
    $this->loggerFactory = $logger_factory;
    $this->time = $time;

    // Register PHP error handler with filtering.
    set_error_handler(function ($severity, $message, $file, $line) {
      // Skip ignored patterns.
      foreach ($this->ignoredPatterns as $pattern) {
        if (@preg_match($pattern, $message)) {
          if (preg_match($pattern, $message)) {
            return TRUE;
          }
        }
      }

      if ($severity <= E_USER_WARNING) {
        $request = $this->requestStack->getCurrentRequest();
        $this->database->insert('ai_log_analysis')
          ->fields([
            'type' => 'php',
            'message' => sprintf('%s in %s on line %d', $message, $file, $line),
            'severity' => 'error',
            'location' => $request?->getRequestUri() ?? '',
            'timestamp' => $this->time->getCurrentTime(),
            'variables' => Json::encode([
              'severity' => $severity,
              'ip' => $request?->getClientIp(),
            ]),
          ])
          ->execute();
      }
      return TRUE;
    });

    // Register uncaught exception handler with filtering.
    set_exception_handler(function (\Throwable $e) {
      $message = $e->getMessage();

      // Skip ignored patterns.
      foreach ($this->ignoredPatterns as $pattern) {
        if (strpos($message, $pattern) !== FALSE) {
          return;
        }
      }
      $request = $this->requestStack->getCurrentRequest();
      $this->database->insert('ai_log_analysis')
        ->fields([
          'type' => 'php',
          'message' => sprintf('Uncaught exception ai log: %s in %s on line %d', $message, $e->getFile(), $e->getLine()),
          'severity' => 'critical',
          'location' => $request?->getRequestUri() ?? '',
          'timestamp' => $this->time->getCurrentTime(),
          'variables' => Json::encode([
            'exception' => get_class($e),
            'ip' => $request?->getClientIp(),
            'trace' => $e->getTraceAsString(),
          ]),
        ])
        ->execute();
    });

    // Register shutdown handler for fatal errors with filtering.
    register_shutdown_function(function () {
      $error = error_get_last();
      if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE], TRUE)) {
        // Skip ignored patterns.
        foreach ($this->ignoredPatterns as $pattern) {
          if (strpos($error['message'], $pattern) !== FALSE) {
            return;
          }
        }
        $request = $this->requestStack->getCurrentRequest();
        $this->database->insert('ai_log_analysis')
          ->fields([
            'type' => 'php',
            'message' => sprintf('Fatal error: %s in %s on line %d', $error['message'], $error['file'], $error['line']),
            'severity' => 'critical',
            'location' => $request?->getRequestUri() ?? '',
            'timestamp' => $this->time->getCurrentTime(),
            'variables' => Json::encode([
              'ip' => $request?->getClientIp(),
              'type' => $error['type'],
            ]),
          ])
          ->execute();
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
      $now = $this->time->getCurrentTime();

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

      // Insert to ai_log_analysis.
      $this->database->insert('ai_log_analysis')
        ->fields([
          'type' => $channel,
          'message' => $interpolated,
          'severity' => $severity,
          'location' => $context['request_uri'] ?? '',
          'timestamp' => $now,
          'variables' => !empty($context) ? $this->safeSerialize($context) : NULL,
        ])
        ->execute();

      // Enforce log retention policy.
      $count = $this->database->select('ai_log_analysis', 'cl')
        ->countQuery()
        ->execute()
        ->fetchField();

      $limit = 1000;
      if ($count > $limit) {
        $subquery = $this->database->select('ai_log_analysis', 'cl2')
          ->fields('cl2', ['id'])
          ->orderBy('timestamp', 'DESC')
          ->range(0, $limit);

        $this->database->delete('ai_log_analysis')
          ->condition('id', $subquery, 'NOT IN')
          ->execute();
      }

    }
    catch (\Exception $e) {
      // Write to PHP error log to avoid recursion.
      error_log('ai_log_analysis failed to write to ai_log_analysis: ' . $e->getMessage());
    }
    finally {
      $this->inLog = FALSE;
    }
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
