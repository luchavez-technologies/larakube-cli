# Plan: Existing Server Onboarding in Desktop UI & Ubuntu 26 Readiness

## 1. Objectives & Context

1. **Existing Server Onboarding in Desktop UI**:
   - Expose the ability to onboard an already-existing, fresh Ubuntu VPS (currently powered by `cli/app/Commands/Cloud/CloudProvisionCommand.php`, i.e. `larakube cloud:init` / `cloud:provision`) directly in the LaraKube Desktop UI (`desktop/resources/js/pages/servers/create.tsx`).
   - Upgrade `cloud:init` in the CLI to support non-interactive execution with strict flag validation (`RequiresFlagsWhenNonInteractive`), register the server as a global stack (`StackData`), and output machine-readable JSON (`--json`).
   - Provide an intuitive Desktop UX with native file picking (`FilePicker`) and auto-detection of common SSH keys from `~/.ssh/`.

2. **Ubuntu 26.04 LTS ("Resolute Raccoon") Readiness**:
   - Prepare LaraKube for Ubuntu 26.04 LTS across OpenTofu provisioning templates and CI/CD pipelines as GitHub Actions transitions `ubuntu-latest` to 26.04.
   - Centralize provider OS image mappings (AWS, DigitalOcean, Hetzner, GCP) supporting both Ubuntu 24.04 and 26.04.
   - Add CI matrix verification against `ubuntu-26.04` to catch cgroups v2, kernel 7.0, and AppArmor unprivileged namespace changes early.

---

## 2. Architectural Blueprint

```mermaid
flowchart TD
    subgraph Desktop UI [desktop/resources/js/pages/servers/create.tsx]
        A["Step 1: Choose Provider<br/>(DO, Hetzner, AWS, GCP, <b>Existing VPS</b>)"] -->|Selects Existing| B["Step 2: SSH Connection Details<br/>(IP, User, Port, Key + Browse/Detect)"]
        B --> C["Step 3: DNS & SSL Automation<br/>(Cloudflare API Token / ExternalDNS)"]
        C --> D["POST /servers<br/>(provider: existing)"]
    end

    subgraph Desktop Backend [ServerController.php]
        D --> E["ServerController::store"]
        E --> F["CliRunner::start('cloud:init', flags...)"]
        E --> G["Record Server in Local DB Cache"]
    end

    subgraph CLI Engine [CloudProvisionCommand.php]
        F --> H["RequiresFlagsWhenNonInteractive<br/>Validate --ip, --ssh-key, etc."]
        H --> I["ProvisionsK3sNode<br/>Harden -> k3s -> Kubeconfig -> Traefik"]
        I --> J["Register Stack in GlobalConfig<br/>(StackData: provider=custom/existing)"]
        J --> K["Emit JSON Result (--json)"]
    end

    subgraph Ubuntu 26 Engine [UbuntuImageResolver]
        L["cloud:create --ubuntu-version=24.04|26.04"] --> M["UbuntuImageResolver"]
        M --> N["Hetzner: ubuntu-26.04"]
        M --> O["DigitalOcean: ubuntu-26-04-x64"]
        M --> P["GCP: ubuntu-os-cloud/ubuntu-2604-lts-amd64"]
        M --> Q["AWS: ubuntu-resolute-26.04-amd64-server-*"]
    end
```

---

## 3. Implementation Details

### Phase 1: CLI Non-Interactive & Stack Registration Upgrade (`cli/`)

#### 1.1 Signature & Non-Interactive Flags in `CloudProvisionCommand.php`
- Update `$signature` to accept flags required for headless automation:
  ```php
  protected $signature = 'cloud:init
      {environment? : Inside a project, the environment to bind to this VPS.}
      {--ip= : Public IP address of the fresh VPS}
      {--ssh-user=root : SSH user with sudo privileges}
      {--ssh-key= : Path to SSH private key (default: ~/.ssh/id_rsa or ~/.ssh/id_ed25519)}
      {--ssh-port=22 : SSH port to connect to}
      {--stack-name= : Custom stack identifier (default: slugified from IP or environment)}
      {--admin-email= : Admin email address for Let\'s Encrypt / ACME}
      {--without-dns : Skip ExternalDNS setup}
      {--without-tls : Skip Cloudflare DNS challenge SSL setup}
      {--cloudflare : Automate DNS and TLS with Cloudflare}
      {--cloudflare-token= : Cloudflare API token}
      {--ssh-passphrase= : Passphrase for encrypted private keys (prompted if omitted or passed via LARAKUBE_SSH_PASSPHRASE)}
      {--context= : Custom kube-context name}
      {--json : Emit machine-readable JSON output}';
  ```
- Use `use EmitsJsonOutput, RequiresFlagsWhenNonInteractive;`.
- Use `flagOrPrompt()` for non-interactive execution:
  - `--ip`: required when non-interactive.
  - `--ssh-user`: defaults to `root`.
  - `--ssh-port`: defaults to `22`.
  - `--ssh-key`: defaults to detected key in `~/.ssh/id_ed25519` or `~/.ssh/id_rsa`.
  - `--admin-email`: required or retrieved from global config.
- Non-interactive mode must throw `MissingFlagException` if `--ip` or required flags are missing (strict ADR & AGENTS.md rule).

#### 1.2 Passphrase Detection & Ephemeral Unlock Pipeline
- **Passphrase Detection (`App\Services\SshKeyInspector`)**:
  - Direct binary check on key file without subprocesses:
    - OpenSSH (`-----BEGIN OPENSSH PRIVATE KEY-----`): decode base64 header. If `ciphername !== 'none'`, the key is encrypted.
    - PEM (`Proc-Type: 4,ENCRYPTED`): encrypted.
    - PKCS#8 (`-----BEGIN ENCRYPTED PRIVATE KEY-----`): encrypted.
  - Subprocess validation: `ssh-keygen -y -P "" -f <keyPath>`.
    - Exit code 0: unencrypted.
    - Exit code 255: encrypted.
  - Agent check: `ssh-add -l` comparing key fingerprint. If the identity is loaded in `ssh-agent`, OpenSSH connects without passphrase even with `-o BatchMode=yes`.
- **Ephemeral Key Decryption during Provisioning**:
  - If the key is encrypted and not in `ssh-agent`, `cloud:init` expects `--ssh-passphrase=` (or `LARAKUBE_SSH_PASSPHRASE` env).
  - Rather than modifying the user's private key, `CloudProvisionCommand`:
    1. Copies `$keyPath` to a temporary directory (`Spatie\TemporaryDirectory`) with `chmod 0600`.
    2. Runs `ssh-keygen -p -P "$passphrase" -N "" -f "$tempKey"` to unlock the copy in-place.
    3. Uses `$tempKey` across all provisioning SSH steps (`waitForSsh`, `hardenServer`, `installK3s`, `syncKubeconfig`).
    4. Automatically purges the temporary directory upon command completion.
    5. The user's original encrypted key remains untouched and secured.

#### 1.3 Global Stack Registration
- In `CloudProvisionCommand::handle()`, once `provisionK3sNode` succeeds:
  - Register the stack into `GlobalConfig` (`$this->getGlobalConfig()->putStack($stack)`).
  - Model attributes:
    - `name`: `$stackName` (e.g. `vps-123-45-67-89` or user-supplied name).
    - `provider`: `'custom'`.
    - `kind`: `'vps'`.
    - `region`: `'custom'`.
    - `ip`: `$ip`.
    - `port`: (int) `$port`.
    - `context`: `$contextName`.
    - `sshKey`: `$keyPath`.
    - `role`: `'deploy'`.
- This ensures `larakube cloud:stacks --json` and Desktop's `StackCatalog` immediately index the server as a first-class stack.

#### 1.3 Machine-Readable JSON Output
- If `--json` is specified, emit:
  ```json
  {
    "success": true,
    "name": "vps-123-45-67-89",
    "ip": "123.45.67.89",
    "context": "larakube-123.45.67.89",
    "provider": "custom",
    "kind": "vps",
    "warnings": []
  }
  ```

---

### Phase 2: Desktop UI & Controller Implementation (`desktop/`)

#### 2.1 Backend Support in `ServerController.php` & `FilePicker`
- **SSH Key Picker & Inspection Endpoints**:
  Add `pickKey(FilePicker $picker, SshKeyInspector $inspector): JsonResponse` to `ServerController.php` (route: `GET /servers/pick-key`):
  ```php
  public function pickKey(FilePicker $picker, SshKeyInspector $inspector): JsonResponse
  {
      $path = $picker->pick('Select SSH Private Key', [], 'SSH Keys / All Files');
      if (! $path) {
          return response()->json(['path' => null]);
      }
      $status = $inspector->inspect($path); // ['encrypted' => bool, 'in_agent' => bool]
      return response()->json(['path' => $path, ...$status]);
  }
  ```
  Add `inspectKey(Request $request, SshKeyInspector $inspector): JsonResponse` (route: `POST /servers/inspect-key`):
  - Validates given file path, returns `['encrypted' => bool, 'in_agent' => bool, 'valid_passphrase' => bool]`.

- **Detected Keys Endpoint / Helper**:
  Add `detectedKeys(): JsonResponse` or include in Inertia props:
  - Scans `~/.ssh/` for existing private key files (`id_ed25519`, `id_rsa`, `id_ecdsa`).
  - Passes list of discovered relative/absolute paths and their encryption status to the frontend.

- **Store Action Handling**:
  In `ServerController::store`:
  ```php
  if ($provider === 'existing' || $provider === 'custom') {
      $ip = $request->string('ip')->toString();
      $sshUser = $request->string('ssh_user', 'root')->toString();
      $sshKey = $request->string('ssh_key')->toString();
      $sshPort = $request->integer('ssh_port', 22);
      $sshPassphrase = $request->string('ssh_passphrase')->toString();
      $stackName = $request->string('stack_name', 'vps-' . str_replace('.', '-', $ip))->slug()->toString();

      $dnsArgs = [];
      if ($request->filled('cloudflare_token') || $request->connectsCloudflare()) {
          $dnsArgs[] = '--cloudflare';
      } else {
          $dnsArgs[] = '--without-dns';
          $dnsArgs[] = '--without-tls';
      }

      $secretEnv = $request->secretEnvironment();
      if ($sshPassphrase !== '') {
          $secretEnv['LARAKUBE_SSH_PASSPHRASE'] = $sshPassphrase;
      }

      $run = $runner->start(
          label: "Provision existing server {$stackName} ({$ip})",
          arguments: [
              'cloud:init',
              "--ip={$ip}",
              "--ssh-user={$sshUser}",
              "--ssh-key={$sshKey}",
              "--ssh-port={$sshPort}",
              "--stack-name={$stackName}",
              '--json',
              ...$dnsArgs,
              ...($project !== null ? [ProjectController::ENVIRONMENT] : []),
          ],
          secretEnvironment: $secretEnv,
          kind: RunKind::CreateServer,
          subject: $stackName,
          meta: ['targetKind' => 'vps', 'provider' => 'custom', ...($project !== null ? ['project' => (string) $project->id] : [])],
          cwd: $project?->path,
          targetType: 'server',
          targetName: $stackName,
          projectId: $project?->id,
          projectName: $project !== null ? basename($project->path) : null,
          environment: $project !== null ? ProjectController::ENVIRONMENT : 'production',
          serverName: $stackName,
      );

      // Record in local Server database model
      Server::updateOrCreate(
          ['name' => $stackName],
          [
              'provider' => 'custom',
              'ip' => $ip,
              'ssh_port' => $sshPort,
              'context' => "larakube-{$ip}",
              'status' => 'provisioning',
          ]
      );

      return to_route('runs.show', $run);
  }
  ```

#### 2.2 Desktop UI in `desktop/resources/js/pages/servers/create.tsx`
- **Step 1: 5th Provider Card**:
  - Add card:
    - ID: `'existing'`
    - Name: `Existing VPS / Custom Server`
    - Subtitle: `Any fresh Ubuntu 24/26 server with SSH root access`
    - Icon: `Server` (Lucide)
    - Badge: `Any Provider`
- **Step 2 Dynamic Fields (When `provider === 'existing'`)**:
  - **Server IP**: Required text input with placeholder `123.45.67.89`.
  - **SSH User**: Text input defaulting to `root`.
  - **SSH Port**: Number input defaulting to `22`.
  - **SSH Private Key**:
    - Text input defaulting to `~/.ssh/id_ed25519` (or `~/.ssh/id_rsa`).
    - "Browse..." secondary button with `Folder` / `Key` icon calling `/servers/pick-key`.
    - Auto-suggest badges for detected keys in `~/.ssh/` (clicking badge fills the input).
  - **SSH Key Passphrase (Conditional)**:
    - Automatically revealed when the selected key is encrypted and NOT in `ssh-agent`.
    - Password input with reveal/hide icon (`Eye` / `EyeOff`) and validation status.
    - Helper text: *"Your SSH private key is encrypted with a passphrase. Enter it here or add it to your agent via `ssh-add`."*
  - **Server Name**: Optional text input (defaults to `vps-<ip>`).
- **Step 3: DNS & SSL Automation**:
  - Reuses the existing `DnsTlsFields` component (Cloudflare API Token / ExternalDNS).
- **Icons & Styling Standard**:
  - All action buttons and tabs use appropriate Lucide icons (`Server`, `Key`, `FolderOpen`, `Eye`, `EyeOff`, `ArrowRight`, `ArrowLeft`).

---

### Phase 3: Ubuntu 26 Readiness & OpenTofu Provider Mapping

#### 3.1 Centralized Ubuntu Image Resolver (`cli/app/Services/Cloud/UbuntuImageResolver.php`)
- Create a dedicated resolver class/service in the CLI:
  ```php
  namespace App\Services\Cloud;

  class UbuntuImageResolver
  {
      public const DEFAULT_VERSION = '24.04';
      public const SUPPORTED_VERSIONS = ['24.04', '26.04'];

      public static function resolve(string $provider, string $version = self::DEFAULT_VERSION): array
      {
          return match ($provider) {
              'hetzner' => [
                  'image' => match ($version) {
                      '26.04' => 'ubuntu-26.04',
                      default => 'ubuntu-24.04',
                  },
              ],
              'do' => [
                  'image' => match ($version) {
                      '26.04' => 'ubuntu-26-04-x64',
                      default => 'ubuntu-24-04-x64',
                  },
              ],
              'gcp' => [
                  'image' => match ($version) {
                      '26.04' => 'ubuntu-os-cloud/ubuntu-2604-lts-amd64',
                      default => 'ubuntu-os-cloud/ubuntu-2404-lts-amd64',
                  },
              ],
              'aws' => [
                  'amiPattern' => match ($version) {
                      '26.04' => 'ubuntu/images/hvm-ssd-gp3/ubuntu-resolute-26.04-amd64-server-*',
                      default => 'ubuntu/images/hvm-ssd-gp3/ubuntu-noble-24.04-amd64-server-*',
                  },
              ],
              default => [],
          };
      }
  }
  ```

#### 3.2 Update OpenTofu Templates (`cli/resources/views/tofu/`)
- Update `cli/resources/views/tofu/aws/vps.blade.php`:
  ```hcl
  data "aws_ami" "ubuntu" {
    most_recent = true
    owners      = ["099720109477"] # Canonical

    filter {
      name   = "name"
      values = ["{{ $amiPattern ?? 'ubuntu/images/hvm-ssd-gp3/ubuntu-noble-24.04-amd64-server-*' }}"]
    }
    ...
  }
  ```
- Hetzner, DO, GCP templates already use `{{ $image ?? '...' }}` and seamlessly accept the resolved image variable.

#### 3.3 Pass Version in `CloudCreateCommand.php`
- Add `{--ubuntu-version=24.04 : Target Ubuntu LTS version (24.04 or 26.04)}` to `cloud:create`.
- Pass resolved `$image` and `$amiPattern` to `view("tofu.{$provider}.vps", ...)`.

#### 3.4 GitHub Actions CI/CD Readiness
- Update `cli/.github/workflows/ci.yml` and `desktop/.github/workflows/tests.yml`:
  - Add `ubuntu-26.04` to the test matrix or run a compatibility test job on `ubuntu-26.04` alongside `ubuntu-latest`.
  - Verify that PHP 8.4, Docker, and k3d execute cleanly on Linux Kernel 7.0 / cgroups v2.
  - Document AppArmor unprivileged user namespace behavior in `.github/workflows/README.md` or ADR.

---

## 4. Verification Plan

### Automated Testing
1. **CLI Tests**:
   - `cli/tests/Unit/CloudProvisionCommandTest.php`:
     - Test running `cloud:init` with flags non-interactively (`--ip`, `--ssh-user`, `--ssh-key`, `--stack-name`, `--json`).
     - Test that missing `--ip` throws `MissingFlagException` in non-interactive mode.
     - Test that the stack is successfully registered into `GlobalConfig`.
   - `cli/tests/Unit/UbuntuImageResolverTest.php`:
     - Verify correct image slugs and AMI patterns for all 4 providers across `24.04` and `26.04`.
   - Run `composer test` and `composer analyse` in `cli/`.
2. **Desktop Tests**:
   - `desktop/tests/Feature/ServerControllerTest.php`:
     - Test `GET /servers/pick-key` returns JSON response from `FilePicker`.
     - Test `POST /servers` with `provider: 'existing'` dispatches `cloud:init` via `CliRunner` with valid flags.
   - Run `composer test` and `composer analyse` in `desktop/`.
   - Run `npm run lint` / `tsc --noEmit` in `desktop/`.

### Manual Validation
1. Open Desktop, navigate to `/servers/create`.
2. Choose "Existing VPS / Custom Server".
3. Verify that Step 2 shows IP, User, Port, Key, and Name fields.
4. Click "Browse..." on SSH Key to verify native file dialog picker triggers and populates the path.
5. Verify Step 3 DNS/TLS settings work as expected.
