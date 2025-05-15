# AI Log Analysis

The AI Log Analysis module helps analyze Drupal system logs using AI to provide insights, possible causes, and suggested solutions for errors and warnings. It includes a Drush command for CLI-based analysis, a web interface for reviewing logs, and configuration for AI provider integration.

## Table of contents

- Requirements
- Installation
- Usage
- Maintainers

## Requirements

This module requires an AI provider module integrated with Drupal’s plugin system. Drupal 10 or later is required.

## Installation

Install the module as you would normally install a contributed Drupal module.

## Usage

  Use the Drush command to analyze recent log entries:
    drush ai_log_analysis:analyze
  Access the web interface via Reports > AI Log Analysis to view analyzed log data and suggestions.

## Troubleshooting

  Ensure your AI provider credentials are correct and valid (if applicable).
  Verify the database connection is operational.
  If Drush commands do not work, clear caches:
