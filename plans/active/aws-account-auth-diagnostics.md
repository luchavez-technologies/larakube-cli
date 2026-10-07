# AWS Multi-Account Authentication Diagnostics & Error Clarity

## Problem Statement
When an operator attempts to create a server using an AWS profile whose IAM credentials have been disabled, revoked, or expired (e.g. `--aws-profile=james.chavez`), LaraKube CLI currently suppresses all stderr output from `aws sts get-caller-identity` via `2>/dev/null`.
Consequently, when AWS rejects the STS authentication call with error codes such as `InvalidClientTokenId` or `SignatureDoesNotMatch`, LaraKube discards the error details and falls back to a generic error message:
> `LARAKUBE No active AWS credentials detected. Pass --aws-access-key-id= and --aws-secret-access-key=, or configure via 'aws configure'.`

This is confusing because:
1. It misleads operators into believing LaraKube ignored `--aws-profile` or could not find their local credentials.
2. It fails to inform the operator that AWS itself rejected the request because the IAM user or access key is deactivated/disabled.

Additionally, user asked:
> "Wait, isn't that more dangerous? I'm pretty sure we can just call on the aws cli to do the removal, right? same with gcp cli."
AWS CLI (`aws configure`) does not have a `delete-profile` subcommand. Official AWS guidance is to remove the profile block from `~/.aws/credentials` and `~/.aws/config`. Conversely, GCP CLI does have `gcloud auth revoke <account>`.

## Objectives
1. **Accurate Error Reporting in `InteractsWithAws`**:
   - Stop piping `aws sts get-caller-identity` stderr to `/dev/null`.
   - Parse and surface exact AWS error codes and messages (e.g. `InvalidClientTokenId`, `SignatureDoesNotMatch`, `ExpiredToken`, `AccessDenied`).
   - When a profile is passed or active and fails authentication, report:
     `AWS authentication failed for profile '<profile>' (<ErrorCode>): <ErrorMessage>`
     `Hint: Target IAM user credentials may be deactivated, expired, or invalid in AWS IAM.`
   - Keep the generic missing credentials hint only when no credentials / error output exist.
2. **Profile Precedence in `buildAwsEnv`**:
   - Ensure explicit `--aws-profile` selections do not get overridden by stale `AWS_ACCESS_KEY_ID` values stored in global config.
3. **Informative Credential Status in `CloudProvidersCommand`**:
   - If AWS STS fails with a specific error code, reflect it in status hint (`AWS authentication failed (<ErrorCode>).`).
4. **Verification with Automated Tests**:
   - Write comprehensive tests in `cli/tests/Feature/CloudCreateAwsTest.php` and `cli/tests/Feature/CloudCreateAwsMultiAccountTest.php` covering deactivated/invalid AWS account STS errors.
