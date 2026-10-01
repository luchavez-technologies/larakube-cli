# LaraKube Mobile: NativePHP Pocket Operations & Incident Responder

## Goal Description
Deliver **LaraKube Mobile**, an iOS and Android app powered by **NativePHP Mobile**, designed specifically for the "on-call / weekend emergency" developer experience.

LaraKube Mobile is not intended for configuring complex ingress rules or authoring YAML on a phone. Instead, it serves as a high-leverage **Pocket Operations Center**: providing real-time native push notifications, at-a-glance cluster health, and one-tap emergency remediation actions (rollbacks, worker restarts, replica scaling, maintenance mode toggling).

---

## 1. The Mobile Problem Space & Key Personas

When production issues occur outside working hours, developers typically:
1. Rush to find their laptop and Wi-Fi.
2. Struggle to authenticate through 2FA/VPNs from a phone browser.
3. Attempt to run terminal commands on an on-screen keyboard.

### LaraKube Mobile Core Pillars
* **High-Signal Push Alerts**: Native notifications for critical events only (CrashLoopBackOff, disk > 85%, failed migrations, queue backlog spikes).
* **One-Tap Panic Buttons**: Pre-baked remediation actions executed securely in seconds.
* **Zero Kubeconfig on Device**: The phone never stores raw Kubernetes certificates or root SSH keys.
* **Biometric Authorization**: FaceID / TouchID required before executing destructive actions (e.g. Rollback or Maintenance Mode).

---

## 2. Architectural Relationship & Data Flow

```mermaid
flowchart TD
    subgraph Mobile ["LaraKube Mobile (NativePHP Mobile / iOS & Android)"]
        UI["Native Mobile UI\n(Inertia / Tailwind Mobile)"]
        FaceID["Biometric Guard\n(FaceID / TouchID)"]
        PushHandler["Push Notification Receiver\n(APNs / FCM)"]
    end

    subgraph Cloud ["LaraKube Cloud (Laravel 13 + FrankenPHP)"]
        API["Mobile REST API\n(Sanctum Bearer Auth)"]
        Reverb["Laravel Reverb\n(WSS Gateway)"]
        FCM["Push Notification Dispatcher\n(Firebase Cloud Messaging)"]
    end

    subgraph Cluster ["Customer Cluster (Private / On-Prem / Cloud)"]
        Agent["larakube-agent daemon\n(larakube-system)"]
        Job["Disposable CLI Job\n(ghcr.io/larakube/cli <verb>)"]
        Workload["Laravel Workloads & Workers"]
    end

    PushHandler <-- Push Alert --- FCM
    UI -->|1. Tap Action (e.g. Rollback)| FaceID
    FaceID -->|2. Authenticated Request| API
    API -->|3. Signed Task Payload| Reverb
    Reverb ===>|4. WSS Dispatch| Agent
    Agent -->|5. Spawn CLI Execution Job| Job
    Job -->|6. Perform Rolling Rollback| Workload
    Job -.->|7. Realtime Progress Logs| Agent
    Agent ===>|8. Stream Output| Reverb
    Reverb -->|9. Live Status Updates| UI
```

---

## 3. Core Feature Set (Phase 2 Roadmap)

### 3.1 One-Tap Emergency Remediation
1. **Instant Rollback**: Revert deployment to the previous immutable container image / git tag with zero downtime.
2. **Restart Workers / Octane**: Gracefully bounce queue workers (`php artisan queue:restart`) or reload FrankenPHP.
3. **Emergency Scaler**: Slide pod replicas from 2 to N during unexpected traffic spikes.
4. **Maintenance Mode Toggle**: Trigger `php artisan down` with bypass token or custom maintenance template.
5. **Cache & Redis Flush**: Flush application cache or Redis commons cluster with confirmation.

### 3.2 Live Telemetry & Log Tailing
* **Cluster Vitals**: Visual traffic light (Green / Yellow / Red) for cluster nodes, CPU, memory, and storage volumes.
* **Real-Time Pod Logs**: View the last 100 lines of streaming stdout/stderr directly from failing pods.
* **GlitchTip Error Stream**: Inspect latest PHP exceptions, stack traces, and affected user counts.

### 3.3 Deployment Approval Gates
* For teams using manual promotion gates: Send a rich push notification to team leads when a staging build passes tests, allowing one-tap approval to deploy to production.

---

## 4. How the LaraKube CLI Powers Mobile Operations

The mobile app does not invent new APIs or custom shell scripts. Every mobile button triggers the exact same standardized, battle-tested verbs in the **LaraKube CLI**:

| Mobile UI Action | CLI Verb Executed in Cluster |
| :--- | :--- |
| **Rollback Release** | `larakube project:rollback <environment> --json` |
| **Restart Workers** | `larakube run artisan queue:restart --json` |
| **Scale Deployment** | `larakube scale <workload> --replicas=<count> --json` |
| **Maintenance Mode** | `larakube run artisan down --secret=<token> --json` |
| **Flush Cache** | `larakube run artisan cache:clear --json` |
| **Cluster Health Check** | `larakube status --json` |

By running through the `larakube-agent` and disposable `batch/v1` Jobs, the mobile actions inherit all CLI safety checks, RBAC restrictions, and audit logs.

---

## 5. Technology Stack & NativePHP Mobile Synergy

* **Framework**: NativePHP Mobile (iOS & Android).
* **Application Core**: Laravel 13 with Inertia.js / React (or Native Views).
* **Authentication**: Laravel Sanctum with mobile device token rotation.
* **Biometrics**: NativePHP Biometrics API for FaceID/TouchID prompt before sensitive actions.
* **Push Notifications**: Firebase Cloud Messaging (FCM) & Apple Push Notification Service (APNs).
* **Real-Time Tailing**: Laravel Echo connecting to LaraKube Cloud's Laravel Reverb cluster.
