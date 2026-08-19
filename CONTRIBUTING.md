# Contributing to LaraKube CLI

Thank you for helping us craft the future of Laravel on Kubernetes! To ensure the project remains robust and maintainable, please follow these guidelines.

## 🎨 UI Consistency

LaraKube uses a custom output system built with **Termwind**. Every command should provide clear, branded feedback so the Artisan knows which step is currently running.

### Using `LaraKubeOutput`
All command classes must use the `App\Traits\LaraKubeOutput` trait.

- **Status Updates:** Use `$this->laraKubeInfo("Message")` for standard steps.
- **Failures:** Use `$this->laraKubeError("Message")` for errors.
- **Header:** Always call `$this->renderHeader()` at the start of the `handle()` method.

## 🛠 Local Development

Install PHP 8.4 locally. `kubectl` also needs to be on your `PATH` for anything that talks to a cluster.

Required PHP extensions (matches CI's `setup-php` step in `.github/workflows/ci.yml`): `mbstring`, `xml`, `ctype`, `iconv`, `intl`, `pdo_sqlite`, `bcmath`, `zip`, `pcntl`, `posix`, `openssl` — plus `phar` (with `phar.readonly=0`) if you're building standalone binaries yourself.

### 1. Running the CLI in dev mode
`./larakube` runs directly against your current source (as opposed to `/usr/local/bin/larakube`, the last **built** binary):

```bash
# Run chat in dev mode
./larakube chat

# Check cluster info
kubectl cluster-info
```

### 2. Dependency Management
```bash
composer require some/package
```

### 3. The Builder (`./build`)
Use this to compile and test the standalone binary locally — it now shells out to your host `php` directly.
```bash
# Build and install to /usr/local/bin/larakube
./build --local
```

## 🏗 Modular Architecture (The Lego System)

LaraKube is built on a modular "Lego" philosophy. When adding functionality, prioritize creating **Hybrid Tools**.

### Creating Hybrid Tools
Hybrid tools are compatible with both the native **AI SDK** (`larakube chat`) and the global **MCP Server** (`larakube mcp`).

1. **Base Class**: Always extend `App\Ai\Tools\LaraKubeTool`.
2. **Implementation**:
   - `run(array $arguments)`: Contains the core logic (must return a string).
   - `callTool(array $arguments)`: Returns a `\Laravel\Mcp\Response` (usually calls `$this->runMcp($arguments)`).
3. **Registration**: Register new tools in both `App\Ai\Agents\LaraKubeAssistantAgent.php` and `App\Mcp\LaraKubeServer.php`.

## ✅ Development Workflow

1. **Transparency First**: Any command that modifies the repository or cluster (`new`, `init`, `add`) MUST provide an architectural preview and obtain user consent by default.
2. **Active Hooks**: Activate the professional guardrails:
   ```bash
   git config core.hooksPath .githooks
   ```
3. **Linting**: We use **Laravel Pint**.
   ```bash
   ./vendor/bin/pint
   ```

## 🧪 Deployment Testing
When adding a feature, please test it in a real cluster or using **OrbStack / Docker Desktop** to ensure the Kubernetes manifests are valid.
