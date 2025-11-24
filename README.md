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
- **PHP**: Uses a `VariationDetailAnalyticsWrapper` wrapper class around `variationDetail()` method.

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

This integration requires the following AWS resources:

- **S3 Bucket**: Stores experiment data with hourly partitioning (`experiments/year=YYYY/month=MM/day=DD/hour=HH/`)
- **IAM Role**: Allows Kinesis Firehose to write to the S3 bucket
- **Kinesis Firehose Delivery Stream**: Streams experiment events from your application to S3

You can create these resources in two ways:

#### Option A: Automated Setup (Recommended)

Run the shared setup script from the project root:

```bash
# First, ensure AWS CLI is configured with your credentials
aws configure
# OR if using SSO:
aws sso login

# Then run the setup script
./setup.sh
```

**Important**: The `setup.sh` script uses AWS CLI credentials (configured via `aws configure` or `aws sso login`), **not** the `.env` file. The `.env` file is only used later by your application code to send data to Firehose.

The script will:
- Prompt you for an S3 bucket name and AWS region
- Create the S3 bucket
- Create an IAM role (`launchdarkly-firehose-role`) with S3 write permissions
- Create a Kinesis Firehose delivery stream (`launchdarkly-experiments-stream`)

#### Option B: Manual Setup

If you prefer to create resources manually or integrate with existing infrastructure:

##### Create S3 Bucket
```bash
aws s3 mb s3://your-launchdarkly-experiments-bucket
```

##### Create IAM Role for Firehose
```bash
# Create trust policy
cat > firehose-trust-policy.json << 'EOF'
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Principal": {
        "Service": "firehose.amazonaws.com"
      },
      "Action": "sts:AssumeRole"
    }
  ]
}
EOF

# Create the role
aws iam create-role \
  --role-name launchdarkly-firehose-role \
  --assume-role-policy-document file://firehose-trust-policy.json

# Create S3 access policy
cat > firehose-s3-policy.json << EOF
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": [
        "s3:AbortMultipartUpload",
        "s3:GetBucketLocation",
        "s3:GetObject",
        "s3:ListBucket",
        "s3:ListBucketMultipartUploads",
        "s3:PutObject"
      ],
      "Resource": [
        "arn:aws:s3:::your-launchdarkly-experiments-bucket",
        "arn:aws:s3:::your-launchdarkly-experiments-bucket/*"
      ]
    },
    {
      "Effect": "Allow",
      "Action": [
        "logs:CreateLogGroup",
        "logs:CreateLogStream",
        "logs:PutLogEvents"
      ],
      "Resource": "arn:aws:logs:*:*:log-group:/aws/kinesisfirehose/*"
    }
  ]
}
EOF

# Attach policy to role
aws iam put-role-policy \
  --role-name launchdarkly-firehose-role \
  --policy-name FirehoseS3Policy \
  --policy-document file://firehose-s3-policy.json

# Clean up temporary files
rm -f firehose-trust-policy.json firehose-s3-policy.json
```

##### Create Kinesis Firehose Delivery Stream
```bash
# Get your account ID
ACCOUNT_ID=$(aws sts get-caller-identity --query Account --output text)

# Create Firehose delivery stream
aws firehose create-delivery-stream \
  --delivery-stream-name launchdarkly-experiments-stream \
  --delivery-stream-type DirectPut \
  --s3-destination-configuration \
  "RoleARN=arn:aws:iam::${ACCOUNT_ID}:role/launchdarkly-firehose-role,BucketARN=arn:aws:s3:::your-launchdarkly-experiments-bucket,Prefix=experiments/year=!{timestamp:yyyy}/month=!{timestamp:MM}/day=!{timestamp:dd}/hour=!{timestamp:HH}/,ErrorOutputPrefix=errors/,BufferingHints={SizeInMBs=1,IntervalInSeconds=60},CompressionFormat=GZIP,EncryptionConfiguration={NoEncryptionConfig=NoEncryption}"
```

**Note**: The setup script is language-agnostic and works for both implementations. You can also use AWS Console, CloudFormation, or Terraform to create these resources with the same specifications.

### 2. Choose Your Implementation

- **[Python Implementation](hello-python/README.md)** - Uses SDK hooks for automatic experiment capture
- **[PHP Implementation](hello-php/README.md)** - Uses wrapper class pattern

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
- `AWS_REGION` - AWS region (e.g., us-east-1) - must match the region where you created resources
- `AWS_ACCESS_KEY_ID` - AWS access key (for your application to send data to Firehose)
- `AWS_SECRET_ACCESS_KEY` - AWS secret key
- `AWS_SESSION_TOKEN` - AWS session token (see AWS Authentication Options below)
- `FIREHOSE_STREAM_NAME` - Name of the Firehose stream (default: `launchdarkly-experiments-stream`)

**Note**: These credentials are used by your application code to send data to Firehose. They can be the same credentials you used for `setup.sh`, or different credentials with appropriate permissions (Firehose `PutRecord` permission).

### AWS Authentication Options

**Option 1: Temporary Credentials (SSO, STS, IAM Roles) - Development/Testing Only**

- Include `AWS_SESSION_TOKEN` in your `.env` file
- Get credentials from AWS Console or `aws sso login`
- Credentials expire and need to be refreshed regularly
- Suitable for local development and testing only

**Option 2: Permanent IAM User Credentials**

- Create IAM user with programmatic access
- Use only `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY`
- Leave `AWS_SESSION_TOKEN` empty
- Credentials don't expire (unless rotated)
- Use IAM roles (e.g., EC2 instance roles, ECS task roles) for even better security in production

**Option 3: IAM Roles (Best for Production)**

- Use IAM roles attached to your compute resources (EC2 instance roles, ECS task roles, Lambda execution roles)
- No credentials to manage or rotate
- Automatic credential rotation
- Most secure option for production deployments
- AWS SDK automatically uses the role credentials - no need to set `AWS_ACCESS_KEY_ID` or `AWS_SECRET_ACCESS_KEY` in `.env`

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
├── DATABRICKS_INTEGRATION.md   # Databricks integration guide (applies to both implementations)
├── hello-python/                # Python implementation
│   ├── README.md               # Python-specific documentation
│   ├── main.py                 # Main application
│   ├── firehose_sender.py      # AWS Firehose integration
│   ├── pyproject.toml          # Python dependencies
│   └── env.example             # Environment variable template
└── hello-php/                  # PHP implementation
    ├── README.md               # PHP-specific documentation
    ├── main.php                # Example application
    ├── composer.json           # PHP dependencies
    ├── env.example             # Environment variable template
    └── src/
        ├── FirehoseSender.php  # AWS Firehose integration
        └── VariationDetailAnalyticsWrapper.php # Wrapper for flag evaluation
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

See [DATABRICKS_INTEGRATION.md](DATABRICKS_INTEGRATION.md) for guidance on:
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

## Implementation Details

### Python Implementation

- Uses LaunchDarkly SDK's hook system
- Automatically captures all flag evaluations
- See [hello-python/README.md](hello-python/README.md) for details

### PHP Implementation

- Uses wrapper class pattern (similar to mobile SDK integrations)
- Requires replacing `variationDetail()` calls with wrapper
- See [hello-php/README.md](hello-php/README.md) for details

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

