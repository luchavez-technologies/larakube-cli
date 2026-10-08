# SignPath Foundation Windows Code Signing Plan & Application Guide

> **Target**: LaraKube Desktop (`desktop/`)  
> **Repository**: [luchavez-technologies/larakube-desktop](https://github.com/luchavez-technologies/larakube-desktop)  
> **Service**: [SignPath Foundation](https://signpath.org) (Free Code Signing for Open Source Software)  
> **Created**: 2026-10-09  

---

## 📌 Executive Summary

> [!NOTE]
> **Status: DEFERRED / STRATEGIC PIVOT**  
> Rather than navigating SignPath Foundation's early-stage reputation gatekeeping (media coverage, download thresholds), Windows code signing is deferred to **Microsoft Trusted Signing ($9.99/month)** under the official organization identity once cloud monetization launches.  
> In the interim, Windows releases build as standard NSIS installers (`.exe`) with the standard *"More info → Run anyway"* guidance, while macOS releases are 100% signed and notarized via Apple Developer ID.

---

## 🔍 Technical Feasibility: NativePHP + SignPath

### How hard is this to integrate?
**Difficulty: Low (1–2 GitHub Action steps, zero changes to PHP code).**

### Architecture:
* **Current State**: On the `windows-latest` runner, NativePHP executes `php artisan native:build win x64 --no-interaction`. Without local certificates or Azure credentials, `electron-builder` outputs an unsigned NSIS installer: `out/LaraKube-Desktop-windows-x64-setup.exe`.
* **SignPath Integration**: Instead of wrestling with raw certificate files or Azure tenants inside NativePHP, we use a post-build CI step:
  1. The Windows runner builds the unsigned `.exe`.
  2. The runner uploads the `.exe` as a temporary GitHub Action artifact.
  3. The official action `signpath/github-action-submit-signing-request@v1` sends the artifact to SignPath's Hardware Security Module (HSM).
  4. SignPath signs the binary with an authentic Microsoft Authenticode certificate and writes the signed `.exe` back to `out/`.
  5. The existing `publish` job uploads the signed `.exe` to GitHub Releases.

Zero changes to NativePHP internals are required because signing occurs as a clean pipeline post-processor.

---

## 📋 Application Submission Form (Copy & Paste)

Navigate to 👉 **[https://signpath.org/apply.html](https://signpath.org/apply.html)** and fill in the following details:

### 1. General Project Details
* **Project Name**: `LaraKube Desktop`
* **Project Slug / Handle**: `larakube-desktop`
* **Project Homepage**: `https://cli.larakube.app` (or `https://larakube.app`)
* **Source Code Repository**: `https://github.com/luchavez-technologies/larakube-desktop`
* **License**: `MIT License` (OSI-approved, specified in `composer.json`)
* **Primary Contact Name**: `James Carlo Luchavez`
* **Contact Email**: `jamescarloluchavez@icloud.com` (or your preferred contact email)

### 2. Project Description & Questions
* **Brief Description of Software**:
  > LaraKube Desktop is a free, open-source companion application for Kubernetes cluster administration and 1-click cloud server provisioning. Built with Laravel and NativePHP (Electron), it allows developers to configure environments, inspect container fleet health, and orchestrate self-hosted developer tools on VPS clusters.
* **Are you the author and maintainer of the project?**:
  > Yes. I am the creator, primary maintainer, and repository owner at Luchavez Technologies.
* **Is all source code published under an OSI-approved license without commercial dual-licensing?**:
  > Yes. The entire repository is published under the standard MIT license without any dual-licensing or proprietary binary blobs.
* **Is the software already released in the format you intend to sign?**:
  > Yes. LaraKube Desktop releases include automated cross-platform installers, including Windows x64 NSIS installers (`.exe`) distributed publicly via GitHub Releases.
* **Do all maintainers use Multi-Factor Authentication (MFA)?**:
  > Yes. MFA is strictly enforced on all GitHub accounts with write access.

---

## 📜 Mandatory Policy Snippet for README

SignPath requires that accepted projects include a standard "Code signing policy" section in their public repository (e.g. in `README.md`).

```markdown
### 🔏 Code Signing Policy
Free code signing is provided by [SignPath.io](https://about.signpath.io), using a certificate issued to the [SignPath Foundation](https://signpath.org).
* **Maintainers & Reviewers**: [Luchavez Technologies](https://github.com/luchavez-technologies)
* **Release Approver**: [James Carlo Luchavez](https://github.com/jsluchavez)
* **Privacy Policy**: LaraKube Desktop does not collect or transmit personal user data to third-party tracking services. All server connections and cluster operations are directed strictly to user-configured infrastructure.
```

---

## ⚙️ Target CI/CD Workflow (`release.yml`)

Once SignPath Foundation approves the project, they will provide:
1. `SIGNPATH_API_TOKEN` (Stored in **GitHub Secrets**)
2. `SIGNPATH_ORGANIZATION_ID` (Stored in **GitHub Variables** or Secrets)

The Windows matrix step in [`desktop/.github/workflows/release.yml`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/.github/workflows/release.yml) will be updated as follows:

```yaml
      - name: Build ${{ matrix.target }}-${{ matrix.arch }}
        shell: bash
        run: php artisan native:build ${{ matrix.target }} ${{ matrix.arch }} --no-interaction

      - name: Collect installers
        shell: bash
        run: |
          mkdir -p out
          find nativephp/electron/dist -maxdepth 1 -type f \( -name '*.dmg' -o -name '*.zip' -o -name '*.exe' -o -name '*.AppImage' -o -name '*.deb' \) -exec cp {} out/ \;
          find nativephp/electron/dist -maxdepth 1 -type f \( -name 'latest.yml' -o -name 'latest-linux.yml' \) -exec cp {} out/ \;
          cd out
          for f in *.dmg; do
            [ -e "$f" ] || continue
            case "$f" in
              *arm64*) cp "$f" LaraKube-Desktop-mac-arm64.dmg ;;
              *x64*) cp "$f" LaraKube-Desktop-mac-x64.dmg ;;
            esac
          done
          for f in *.exe; do [ -e "$f" ] && cp "$f" LaraKube-Desktop-windows-x64-setup.exe; done
          cd ..

      # SignPath Windows Signing Step
      - name: Upload Windows installer for SignPath
        if: matrix.target == 'win'
        id: upload_win_installer
        uses: actions/upload-artifact@043fb46d1a93c77aae656e7c1c64a875d1fc6a0a # v7.0.1
        with:
          name: unsigned-win-setup
          path: out/LaraKube-Desktop-windows-x64-setup.exe

      - name: Sign Windows installer via SignPath Foundation
        if: matrix.target == 'win'
        uses: signpath/github-action-submit-signing-request@v1
        with:
          api-token: ${{ secrets.SIGNPATH_API_TOKEN }}
          organization-id: ${{ secrets.SIGNPATH_ORGANIZATION_ID }}
          project-slug: 'larakube-desktop'
          signing-policy-slug: 'release-signing'
          github-artifact-id: ${{ steps.upload_win_installer.outputs.artifact-id }}
          wait-for-completion: true
          output-artifact-directory: 'out'
```

---

## 🗓️ Next Steps
1. Navigate to [signpath.org/apply.html](https://signpath.org/apply.html) and submit the application using the template above.
2. Add the brief "Code Signing Policy" paragraph to `desktop/README.md`.
3. Upon approval (~2–4 business days), store `SIGNPATH_API_TOKEN` and `SIGNPATH_ORGANIZATION_ID` in GitHub Repository Secrets.
4. Update `release.yml` with the signing block.
