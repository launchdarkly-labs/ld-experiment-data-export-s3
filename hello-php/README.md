# LaunchDarkly Experiment Data Export to S3 - PHP Implementation

A reference implementation showing how to integrate LaunchDarkly experiment evaluation data with AWS S3 using Kinesis Firehose in PHP applications.

**Note**: This is the PHP implementation. For the unified project overview, see the [root README.md](../README.md).

## Overview

- Uses a `VariationDetailAnalyticsWrapper` wrapper class around `variationDetail()` method calls
- Automatically detects when users are in experiments and sends data to Firehose
- Follows the same pattern used in LaunchDarkly Mobile SDK integrations
- Data streams to S3 for integration with analytics platforms

```
LaunchDarkly SDK → PHP Wrapper → Kinesis Firehose → S3
                                                       ↓
                                           [Your Analytics Platform]
```

## Prerequisites

- **AWS Account** with permissions to create and manage:
  - Kinesis Firehose delivery streams
  - S3 buckets
  - IAM roles and policies
- **LaunchDarkly Account** with:
  - SDK key
  - Feature flags with experiments enabled
- **PHP 8.1+** with Composer

## Quick Start

### 1. Set Up AWS Resources

**Note**: For detailed AWS resource setup instructions (automated and manual), see the [root README.md](../README.md#1-set-up-aws-resources). The setup instructions are shared between Python and PHP implementations.

### 2. Install Dependencies

```bash
cd hello-php
composer install
```

### 3. Configure Environment

```bash
cp env.example .env
```

Edit `.env` with your credentials. See the [root README.md](../README.md#3-configure-environment) for required environment variables and AWS authentication options.

### 4. Test the Integration

```bash
php main.php
```

## Integration into Your Application

### Step 1: Copy Required Files

Copy these files to your application:

- `src/FirehoseSender.php` - AWS Kinesis Firehose integration
- `src/VariationDetailAnalyticsWrapper.php` - Wrapper for flag evaluation

### Step 2: Install Dependencies

Add to your `composer.json`:

```json
{
    "require": {
        "launchdarkly/server-sdk": "^6.0",
        "aws/aws-sdk-php": "^3.0",
        "vlucas/phpdotenv": "^5.0"
    }
}
```

Run `composer install`.

### Step 3: Initialize Components

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use LaunchDarkly\LDClient;
use LaunchDarkly\LDContext;
use LaunchDarkly\FirehoseSender;
use LaunchDarkly\VariationDetailAnalyticsWrapper;

// Initialize LaunchDarkly client
$ldClient = new LDClient($_ENV['LAUNCHDARKLY_SDK_KEY']);

// Initialize Firehose sender (optional - can be null for graceful degradation)
$firehoseSender = null;
try {
    $firehoseSender = new FirehoseSender($_ENV['FIREHOSE_STREAM_NAME']);
} catch (Exception $e) {
    // Log error but continue without Firehose
    error_log("Failed to initialize Firehose sender: " . $e->getMessage());
}

// Initialize wrapper
$analyticsWrapper = new VariationDetailAnalyticsWrapper($ldClient, $firehoseSender);
```

### Step 4: Replace Flag Evaluation Calls

**Before:**
```php
$evaluationDetail = $ldClient->variationDetail($flagKey, $context, $defaultValue);
$flagValue = $evaluationDetail->getValue();
```

**After:**
```php
$evaluationDetail = $analyticsWrapper->variationDetail($flagKey, $context, $defaultValue);
$flagValue = $evaluationDetail->getValue();
```

The wrapper automatically:
- Detects if the user is in an experiment
- Sends experiment data to Firehose/S3
- Returns the same `EvaluationDetail` object (fully backward compatible)

### Step 5: Gradual Migration

You can migrate incrementally - replace `variationDetail()` calls one at a time. The wrapper returns the same object type, so existing code continues to work.

## How It Works

### Experiment Detection

The wrapper checks if a user is in an experiment by examining the `EvaluationReason`:

```php
$reason = $evaluationDetail->getReason();
if ($reason->isInExperiment()) {
    // Send to Firehose
}
```

### Event Payload

When an experiment is detected, the following data is sent to S3:

```json
{
  "timestamp": "2024-11-24T14:30:00+00:00",
  "flag_key": "example-experiment-flag",
  "evaluation_context": {
    "key": "user-123",
    "kind": "user",
    "tier": "premium"
  },
  "flag_value": "treatment",
  "variation_index": 1,
  "reason_kind": "FALLTHROUGH",
  "metadata": {
    "source": "launchdarkly-php-wrapper",
    "version": "1.0"
  }
}
```

## Project Structure

```
hello-php/
├── README.md                          # This file
├── main.php                           # Example application
├── composer.json                      # PHP dependencies
├── env.example                        # Environment variable template
├── LICENSE.txt                        # License file
└── src/
    ├── FirehoseSender.php             # AWS Firehose integration
    └── VariationDetailAnalyticsWrapper.php  # Wrapper class
```

## Data Structure

### S3 Data Organization

Data is partitioned by `year/month/day/hour` for efficient querying:

```
s3://your-bucket/
├── experiments/
│   ├── year=2024/
│   │   ├── month=11/
│   │   │   ├── day=24/
│   │   │   │   ├── hour=14/
│   │   │   │   │   └── launchdarkly-experiments-stream-1-2024-11-24-14-00-00-abc123.json.gz
│   │   │   │   └── hour=15/
│   │   │   └── day=25/
│   │   └── month=12/
│   └── errors/
│       └── failed-records.json.gz
```

**Note**: Files are GZIP compressed and use newline-delimited JSON format (one event per line).

### Event Data Schema

Each event contains:
```json
{
  "timestamp": "2024-11-24T14:30:00+00:00",
  "flag_key": "example-experiment-flag",
  "evaluation_context": {
    "key": "user-123",
    "kind": "user",
    "tier": "premium",
    "country": "US"
  },
  "flag_value": "treatment",
  "variation_index": 1,
  "reason_kind": "FALLTHROUGH",
  "metadata": {
    "source": "launchdarkly-php-wrapper",
    "version": "1.0"
  }
}
```

## Analytics Platform Integration

Once your data is flowing to S3, configure your analytics platform to consume it.

### For Databricks Users

See [../DATABRICKS_INTEGRATION.md](../DATABRICKS_INTEGRATION.md) for guidance on:
- Auto Loader configuration
- Sample analysis queries
- Performance optimization tips

**Note**: The Databricks integration guide is provided as a starting point and has not been tested. Please verify and adapt the configuration for your environment. This guide applies to data from both Python and PHP implementations since they produce the same S3 data format.

### For Other Platforms

The S3 data is stored in a standard JSON format that can be consumed by:
- **Snowflake** - Use external tables or COPY commands
- **BigQuery** - Use external data sources
- **Athena** - Query directly from S3
- **Custom applications** - Use AWS SDKs to read the JSON files

## Monitoring and Troubleshooting

### Verify Data in S3

```bash
# List files in your bucket
aws s3 ls s3://your-bucket-name/experiments/ --recursive

# Download and view a sample file
aws s3 cp s3://your-bucket-name/experiments/year=2024/month=11/day=24/hour=14/sample.json.gz /tmp/
gunzip -c /tmp/sample.json.gz | head -n 1 | jq .
```

### Check Firehose Stream

```bash
aws firehose describe-delivery-stream --delivery-stream-name launchdarkly-experiments-stream
```

### Common Issues

1. **Expired AWS credentials**: Run `aws sso login` or refresh your credentials
2. **Permission denied**: Verify IAM role has proper S3 permissions
3. **Stream not found**: Ensure Firehose delivery stream exists and is active
4. **Data not appearing**: 
   - Firehose buffers data for up to 60 seconds
   - Wait 1-5 minutes after sending events
   - Check CloudWatch logs for delivery errors

### CloudWatch Monitoring

- Monitor Firehose delivery metrics
- Set up alarms for failed deliveries
- Check S3 access logs for data arrival

## Customization

### Adding Custom Context Attributes

The integration automatically captures any custom attributes in your LaunchDarkly contexts:

```php
$context = LDContext::builder('user-123')
    ->kind('user')
    ->name('John Doe')
    ->set('tier', 'premium')
    ->set('country', 'US')
    ->set('subscription_id', 'sub_123')
    ->build();
```

All custom attributes will be automatically included in the S3 data.

### Modifying Event Schema

Edit `src/FirehoseSender.php` to customize the event structure:

```php
$eventData = [
    'timestamp' => date('c'),
    'flag_key' => $flagKey,
    'evaluation_context' => $this->extractContextData($context),
    'flag_value' => $evaluationDetail->getValue(),
    'variation_index' => $evaluationDetail->getVariationIndex(),
    'reason_kind' => $this->getReasonKind($evaluationDetail),
    // Add custom fields here
    'custom_field' => 'custom_value',
    'metadata' => [
        'source' => 'launchdarkly-php-wrapper',
        'version' => '1.0',
    ],
];
```

## Key Differences from Python Implementation

- **No Hooks**: PHP SDK doesn't support hooks, so we use a wrapper pattern
- **Manual Integration**: Requires replacing `variationDetail()` calls with the wrapper
- **Same Data Format**: Event payload structure matches Python version for consistency

## Security Considerations

- Use IAM roles with minimal required permissions
- Enable S3 server-side encryption if needed
- Consider VPC endpoints for private network access
- Implement proper access controls for S3 buckets

## Cost Optimization

- Use appropriate Firehose buffering settings
- Implement S3 lifecycle policies for data retention
- Consider data compression for large volumes
- Monitor and optimize partition sizes

## License

This project is licensed under the Apache-2.0 License.

