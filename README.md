# AI Log Analysis

The AI Log Analysis module helps analyze Drupal system logs using AI to provide
 insights, possible causes, and suggested solutions for errors and warnings. It
 includes a Drush command for CLI-based analysis, a web interface for reviewing
 logs, and configuration for AI provider integration.

## Table of contents

- Requirements
- Installation
- Usage
- Configuration
- Limitations
- Troubleshooting
- Maintainers

## Requirements

- Drupal 10 or later
- An AI provider module integrated with Drupal’s plugin system (e.g., OpenAI
   integration)
- PHP 8.x (compatible with Drupal 10)
- Functional database connection

## Installation

1. Place the `ai_log_analysis` module folder into your Drupal installation’s
    `modules/custom` directory.
2. Enable the module via Drupal admin UI or Drush:
   - drush en ai_log_analysis -y

## Usage

### Drush Command

Analyze recent Drupal log entries directly from the command line:

```bash drush ai_log_analysis:analyze --severity=error --start_date=YYYY-MM-DD
 --end_date=YYYY-MM-DD ```

- --severity (optional): Filter logs by severity level (e.g., error, warning).
- --start_date (optional): Start date to filter logs (format: YYYY-MM-DD).
- --end_date (optional): End date to filter logs (format: YYYY-MM-DD).

### Web Interface

Access the AI Log Analysis interface via Drupal Admin:

- Navigate to Reports > AI Log Analysis.
- View analyzed log entries with AI-generated insights and suggested solutions.

### Notes

- You will be prompted to select which log entry to analyze when running 
   the Drush command.
- The module relies on an AI provider for analysis, ensure it is properly 
   configured.

## Configuration

### AI Provider Integration

This module requires an AI provider module that integrates with Drupal’s
 plugin system. Ensure you have configured your AI provider properly:

- Set your API keys or credentials in the provider’s configuration form.
- Verify that the AI service is reachable from your Drupal environment.

## Limitations

- The AI analysis is based on logs stored in the custom_log table;
   ensure this table is properly populated.
- The quality of AI insights depends on the completeness and accuracy of
   the log data available.
- AI usage may incur API costs or rate limits; monitor usage accordingly.
- Real-time log analysis is not supported; analysis is performed on a
   fixed set of recent logs.
- AI-generated suggestions should be reviewed manually for accuracy and
   relevance.
- The module is tested on Drupal 10 and may require updates for compatibility
   with future versions.

## Troubleshooting

- Ensure the `custom_log` database table is populated and accessible by
   the module.
- Verify the AI provider credentials and configuration are correct and active.
- Check for any database connection issues that may prevent log retrieval.
- Confirm your AI provider’s API limits and quota have not been exceeded.
- Review Drupal watchdog or server logs for errors related to the
   AI Log Analysis module.
- Ensure your environment meets the module requirements, including PHP version
   and Drupal core compatibility.
