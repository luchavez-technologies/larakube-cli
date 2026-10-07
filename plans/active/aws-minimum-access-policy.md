# Plan: AWS Minimum IAM Access Policy & In-App Setup Guidance

## Goal Description
When provisioning an AWS server or dev box in LaraKube Desktop or CLI, operators must configure an AWS IAM user with appropriate permissions. Currently, operators are prompted for AWS Access Keys without clear guidance on what IAM permissions are required in AWS, leading to uncertainty about whether full account administrator rights or specific EC2 policies are needed.

This plan details:
1. The exact AWS permissions required by LaraKube's OpenTofu AWS VPS stack.
2. The recommended 1-click option (`AmazonEC2FullAccess`) vs. the minimal least-privilege JSON policy.
3. Adding an inline guidance card with a one-click "Copy Minimal IAM Policy JSON" button in LaraKube Desktop (`servers/create.tsx` and `readiness.tsx`).
4. Adding an inline guidance hint in the CLI credential prompts (`InteractsWithAws.php`).
5. Adding comprehensive documentation in `docs/docs/cloud/aws.md`.

---

## Technical Analysis of AWS Operations in LaraKube

LaraKube provisions single-node VPS servers (and dev boxes) using OpenTofu (`cli/resources/views/tofu/aws/vps.blade.php`). During creation, scaling, and destruction, OpenTofu interacts exclusively with EC2 APIs:

| Phase | Resource / Operation | Required Actions |
|---|---|---|
| **Discovery** | Default VPC, Subnets, AZ offerings, Ubuntu AMI | `ec2:DescribeVpcs`, `ec2:DescribeSubnets`, `ec2:DescribeInstanceTypeOfferings`, `ec2:DescribeImages` |
| **SSH Key** | Key pair creation & deletion | `ec2:ImportKeyPair`, `ec2:DescribeKeyPairs`, `ec2:DeleteKeyPair` |
| **Security Group** | Firewall for SSH (22), k3s API (6443), HTTP (80), HTTPS (443) | `ec2:CreateSecurityGroup`, `ec2:DescribeSecurityGroups`, `ec2:DeleteSecurityGroup`, `ec2:AuthorizeSecurityGroupIngress`, `ec2:AuthorizeSecurityGroupEgress`, `ec2:RevokeSecurityGroupIngress`, `ec2:RevokeSecurityGroupEgress` |
| **Instance & Storage** | EC2 VM, gp3 EBS root volume, Tagging | `ec2:RunInstances`, `ec2:DescribeInstances`, `ec2:DescribeInstanceStatus`, `ec2:StartInstances`, `ec2:StopInstances`, `ec2:TerminateInstances`, `ec2:ModifyInstanceAttribute`, `ec2:CreateVolume`, `ec2:AttachVolume`, `ec2:DescribeVolumes`, `ec2:DeleteVolume`, `ec2:CreateTags`, `ec2:DeleteTags`, `ec2:DescribeTags` |
| **Authentication Check** | `aws sts get-caller-identity` | Permitted by default for all authenticated IAM identities |

---

## Policy Options for Operators

### Option A: 1-Click AWS Managed Policy (Fastest)
- **Policy Name**: `AmazonEC2FullAccess`
- **Setup in AWS Console**: Step 2 of IAM User creation -> **Attach policies directly** -> Search `AmazonEC2FullAccess` -> Select checkbox -> Next.
- **Scope**: Grants full access to EC2 resources and related compute infrastructure. Ideal for standard accounts or sandbox environments.

### Option B: Strict Least-Privilege Custom Policy (Enterprise / Locked Down)
Operators can create a custom IAM policy with only the specific EC2 actions required by LaraKube:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "LaraKubeEC2LeastPrivilege",
      "Effect": "Allow",
      "Action": [
        "ec2:Describe*",
        "ec2:ImportKeyPair",
        "ec2:DeleteKeyPair",
        "ec2:CreateSecurityGroup",
        "ec2:DeleteSecurityGroup",
        "ec2:AuthorizeSecurityGroupIngress",
        "ec2:AuthorizeSecurityGroupEgress",
        "ec2:RevokeSecurityGroupIngress",
        "ec2:RevokeSecurityGroupEgress",
        "ec2:RunInstances",
        "ec2:StartInstances",
        "ec2:StopInstances",
        "ec2:TerminateInstances",
        "ec2:ModifyInstanceAttribute",
        "ec2:CreateVolume",
        "ec2:AttachVolume",
        "ec2:DetachVolume",
        "ec2:DeleteVolume",
        "ec2:ModifyVolume",
        "ec2:ModifyVolumeAttribute",
        "ec2:CreateNetworkInterface",
        "ec2:AttachNetworkInterface",
        "ec2:DeleteNetworkInterface",
        "ec2:ModifyNetworkInterfaceAttribute",
        "ec2:CreateTags",
        "ec2:DeleteTags"
      ],
      "Resource": "*"
    }
  ]
}
```

---

## User Review Required
> [!NOTE]
> The default single-node server and dev box provisioning only requires EC2 permissions. No VPC creation, IAM role creation, Route53, or S3 permissions are required for server provisioning.
> If managed EKS clusters (`cloud:create --provider=aws` without `--vps`) are used in the future via CLI, additional EKS (`eks:*`) and IAM (`iam:CreateRole`, `iam:AttachRolePolicy`) permissions would be needed, but for standard VPS servers and DevBoxes, the above policy is 100% complete and self-contained.

---

## Proposed Changes

### Component 1: LaraKube Desktop UI

#### [MODIFY] `desktop/resources/js/pages/servers/create.tsx`
- In the `needsAwsKeys` section (around line 258):
  - Add an inline guidance card above the Access Key ID / Secret fields with:
    - **Header**: "AWS IAM Permissions"
    - **Quick setup note**: "Attach **AmazonEC2FullAccess** to your IAM user under *Attach policies directly*."
    - **Copy Minimal Policy button**: A button with `Copy` / `Check` icon (`LucideIcon` standard) that copies the minimal JSON policy to the user's clipboard.
    - Notification/tooltip confirming copy: "Minimal IAM policy copied to clipboard!"

#### [MODIFY] `desktop/resources/js/pages/readiness.tsx`
- In the AWS account connection modal (around line 812):
  - Add the same concise guidance note and "Copy Minimal Policy JSON" action.

---

### Component 2: LaraKube CLI

#### [MODIFY] `cli/app/Traits/InteractsWithAws.php`
- When prompting for manual AWS Access Key ID and Secret Access Key (around line 160):
  - Output a concise helper line:
    ```php
    $this->line('  <fg=gray>IAM Permissions: Attach</> <fg=yellow>AmazonEC2FullAccess</> <fg=gray>or a custom EC2 policy.</>');
    ```

---

### Component 3: Documentation

#### [NEW] or [MODIFY] `docs/docs/cloud/aws.md` (or relevant cloud documentation)
- Add a dedicated section on "AWS IAM Permissions & Least Privilege" with:
  - Step-by-step console guide for creating an IAM user for LaraKube.
  - Option 1: `AmazonEC2FullAccess` managed policy.
  - Option 2: Full minimal JSON snippet ready to copy into AWS Policy editor.

---

## Verification Plan

### Automated Tests
- Run `cli/` test suite:
  ```bash
  composer format && composer analyse && composer test
  ```
- Run `desktop/` tests:
  ```bash
  npm run build
  ```

### Manual Verification
- In LaraKube Desktop:
  1. Navigate to **Servers** -> **Create a server**.
  2. Select **Amazon Web Services**.
  3. Verify the inline guidance card appears with clear instructions and the "Copy Minimal Policy JSON" button.
  4. Click the copy button and verify clipboard content matches the JSON policy.
  5. Check **Tools & Logins** (Setup) -> Connect AWS Account modal for parity.
- In CLI:
  1. Run `php cli/larakube cloud:credentials --provider=aws` or simulate missing credentials.
  2. Verify the IAM guidance line is printed cleanly.
