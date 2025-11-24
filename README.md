# LaunchDarkly Experiment Data Export to S3 Reference Implementations

Reference implementations showing how to integrate LaunchDarkly experiment evaluation data with AWS S3 using Kinesis Firehose. This enables real-time streaming of experiment data to your analytics platform.

This project includes implementations in **Python** and **PHP**, each demonstrating how to capture experiment evaluation data and stream it to S3 for analytics integration.

## Overview

The implementations capture LaunchDarkly experiment evaluation data and stream it to AWS S3 via Kinesis Firehose, enabling integration with analytics platforms like Databricks, Snowflake, BigQuery, or Athena.

### Architecture

```
LaunchDarkly SDK → [Language-Specific Integration] → Kinesis Firehose → S3
                                                      ↓
                                              [Your Analytics Platform]
```

### Implementation Approaches

- **Python**: Uses LaunchDarkly SDK's `after_evaluation` hook to automatically intercept flag evaluations
- **PHP**: Uses a `VariationDetailAnalyticsWrapper` wrapper class around `variationDetail()` method (following the [mobile SDK pattern](https://gist.github.com/durw4rd/bb08008434ef20ac69b745b1c4b0192a))

## Prerequisites

- **AWS Account** with permissions to create and manage:
  - Kinesis Firehose delivery streams
  - S3 buckets
  - IAM roles and policies
- **LaunchDarkly Account** with:
  - SDK key
  - Feature flags with experiments enabled
- **Language Requirements**:
  - Python 3.9+ with Poetry (for Python implementation)
  - PHP 8.1+ with Composer (for PHP implementation)

## Quick Start

### 1. Set Up AWS Resources

Run the shared setup script from the project root:

```bash
./setup.sh
```

This script creates:
- S3 bucket for experiment data
- IAM role for Firehose with S3 permissions
- Kinesis Firehose delivery stream with partitioning

**Note**: The setup script is language-agnostic and works for both implementations.

### 2. Choose Your Implementation

- **[Python Implementation](hello-python/README.md)** - Uses SDK hooks for automatic experiment capture
- **[PHP Implementation](hello-php/README.md)** - Uses wrapper class pattern (coming soon)

### 3. Configure Environment

Each implementation has its own `env.example` file. Copy it to `.env` and fill in your credentials:

```bash
# For Python
cd hello-python
cp env.example .env

# For PHP
cd hello-php
cp env.example .env
```

Required environment variables:
- `LAUNCHDARKLY_SDK_KEY` - Your LaunchDarkly SDK key
- `LAUNCHDARKLY_FLAG_KEY` - Feature flag key to evaluate
- `AWS_REGION` - AWS region (e.g., us-east-1)
- `AWS_ACCESS_KEY_ID` - AWS access key
- `AWS_SECRET_ACCESS_KEY` - AWS secret key
- `AWS_SESSION_TOKEN` - AWS session token (only for temporary credentials like SSO)
- `FIREHOSE_STREAM_NAME` - Name of the Firehose stream (default: `launchdarkly-experiments-stream`)

### 4. Run the Implementation

**Python:**
```bash
cd hello-python
poetry install
poetry run python main.py
```

**PHP:**
```bash
cd hello-php
composer install
php main.php
```

## Project Structure

```
.
├── README.md                    # This file - project overview
├── setup.sh                     # Shared AWS resource setup script
├── LICENSE.txt                  # Project license
├── hello-python/                # Python implementation
│   ├── README.md               # Python-specific documentation
│   ├── main.py                 # Main application
│   ├── firehose_sender.py      # AWS Firehose integration
│   ├── pyproject.toml          # Python dependencies
│   ├── env.example             # Environment variable template
│   └── DATABRICKS_INTEGRATION.md # Databricks integration guide
└── hello-php/                  # PHP implementation
    ├── README.md               # PHP-specific documentation
    ├── main.php                # Main application
    ├── FirehoseSender.php      # AWS Firehose integration
    ├── VariationDetailAnalyticsWrapper.php # Wrapper for flag evaluation
    ├── composer.json           # PHP dependencies
    └── env.example             # Environment variable template
```

## Data Structure

### S3 Data Organization

Data is partitioned by `year/month/day/hour` for efficient querying:

```
s3://your-bucket/
├── experiments/
│   ├── year=2024/
│   │   ├── month=01/
│   │   │   ├── day=15/
│   │   │   │   ├── hour=14/
│   │   │   │   │   └── launchdarkly-experiments-stream-1-2024-01-15-14-00-00-abc123.json.gz
│   │   │   │   └── hour=15/
│   │   │   └── day=16/
│   │   └── month=02/
│   └── errors/
│       └── failed-records.json.gz
```

### Event Data Schema

Each event contains:
```json
{
  "timestamp": "2024-01-15T14:30:00.123456+00:00",
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
    "source": "launchdarkly-python-hook" or "launchdarkly-php-wrapper",
    "version": "1.0"
  }
}
```

## Analytics Platform Integration

Once your data is flowing to S3, configure your analytics platform to consume it.

### For Databricks Users

See [hello-python/DATABRICKS_INTEGRATION.md](hello-python/DATABRICKS_INTEGRATION.md) for guidance on:
- Auto Loader configuration
- Sample analysis queries
- Performance optimization tips

**Note**: The Databricks integration guide is provided as a starting point and has not been tested. Please verify and adapt the configuration for your environment.

### For Other Platforms

The S3 data is stored in a standard JSON format that can be consumed by:
- **Snowflake** - Use external tables or COPY commands
- **BigQuery** - Use external data sources
- **Athena** - Query directly from S3
- **Custom applications** - Use AWS SDKs to read the JSON files

## Implementation Details

### Python Implementation

- Uses LaunchDarkly SDK's hook system
- Automatically captures all flag evaluations
- See [hello-python/README.md](hello-python/README.md) for details

### PHP Implementation

- Uses wrapper class pattern (similar to mobile SDK integrations)
- Requires replacing `variationDetail()` calls with wrapper
- See [hello-php/README.md](hello-php/README.md) for details (coming soon)

## Monitoring and Troubleshooting

### Check Data Flow

```bash
# Verify S3 data
aws s3 ls s3://your-launchdarkly-experiments-bucket/experiments/ --recursive

# Check Firehose metrics
aws firehose describe-delivery-stream --delivery-stream-name launchdarkly-experiments-stream
```

### Common Issues

1. **Expired AWS credentials**: Run `aws sso login` or refresh your credentials
2. **Permission denied**: Verify IAM role has proper S3 permissions
3. **Stream not found**: Ensure Firehose delivery stream exists and is active
4. **Data not appearing**: Check Firehose buffering settings and error logs

### CloudWatch Monitoring

- Monitor Firehose delivery metrics
- Set up alarms for failed deliveries
- Check S3 access logs for data arrival

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

