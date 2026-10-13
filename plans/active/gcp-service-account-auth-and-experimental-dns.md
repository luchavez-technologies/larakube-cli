# Implementation Plan: GCP Service Account Authentication & Experimental DNS

## Background & Objectives
1. **Experimental Provider Markings**:
   - The operator cannot live-validate AWS Route 53 and Google Cloud DNS at this time.
   - Mark both providers as `(Experimental)` in `servers/create.tsx` (in the provider dropdown and with dedicated warning/info callouts).
   - Display clear IAM and permission requirements for each (Route 53: `route53:ChangeResourceRecordSets`; Google Cloud DNS: `roles/dns.admin` & Cloud DNS API).
2. **Architectural Clarification on GCP DNS vs Server Project**:
   - In solo/small setups, the server compute project and DNS project are often the same.
   - In enterprise architectures, DNS apex zones frequently reside in a centralized root/networking project (e.g. `shared-services`), separated from compute clusters for least privilege.
   - Asking for `project_id` separately allows this flexibility, but asking users to re-type it manually when pasting a Service Account JSON is redundant because the JSON already contains `project_id` and `client_email`.
   - Enhance the UX with auto-parsing on paste, a native JSON file picker, and a quick-select to use the server's GCP project if available.
3. **GCP Service Account Authentication (Provider Support)**:
   - Expand Google Cloud authentication beyond the OAuth device flow to support **Service Account Key JSON** files (standard in Firebase, GCP IAM, Terraform/OpenTofu, and CI/CD).
   - Support `cloud:credentials --provider=gcp` in the CLI to save, configure, and activate Service Accounts (`gcloud auth activate-service-account`).
   - Add Service Account upload and management in `CloudAccountsModal` and `CloudAuthController`.

## Affected Components
- `cli/plans/active/gcp-service-account-auth-and-experimental-dns.md` (this plan)
- `cli/app/Commands/Cloud/CloudCredentialsCommand.php`
- `cli/app/Commands/Cloud/CloudAccountsCommand.php`
- `cli/tests/Feature/CloudCredentialsCommandTest.php`
- `cli/tests/Feature/CloudAccountsCommandTest.php`
- `desktop/app/Http/Controllers/CloudAuthController.php`
- `desktop/routes/web.php`
- `desktop/tests/Feature/CloudAuthControllerTest.php`
- `desktop/resources/js/components/cloud-accounts-modal.tsx`
- `desktop/resources/js/pages/servers/create.tsx`

## Verification Strategy
- Run `composer format`, `composer analyse`, `composer test` in `cli/`.
- Run `npm run check:fix`, `npm run types:check`, `composer lint:check`, `composer types:check`, `vendor/bin/pest` in `desktop/`.
- Ensure zero breaking changes and full backward compatibility.
