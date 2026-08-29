# Web3 Companion Architecture & Low-Footprint Strategy

## 🎯 Executive Summary & Context

Web3 enables permissionless global micropayments (USDC), smart contracts, self-sovereign cryptographic identity, and verifiable data storage. However, blockchain nodes (especially Solana and Ethereum full nodes) are notoriously heavy and incompatible with low-memory servers (e.g., standard 2GB RAM k3s droplets/VPS).

This document outlines the architectural strategy for introducing Web3 capabilities into LaraKube CLI without bloating server footprints, while delivering high utility to developers, smart contract creators (e.g., Rust Anchor tipping/commerce dApps), and application backends.

---

## 🛑 Memory Constraint Audit (2GB RAM k3s Reality Check)

On a standard 2GB RAM k3s node, system processes (K3s, Containerd, Traefik) and Plex Commons (Postgres, Redis, SeaweedFS) consume ~800MB–1.1GB of RAM, leaving **~900MB–1.2GB of usable headroom**.

### 1. Infeasible Workloads (Strictly Prohibited on 2GB RAM)
* ❌ **Solana Local Validator (`solana-test-validator`)**: Requires **16GB–32GB RAM** minimum. Crashes immediately with kernel `OOMKilled` on a 2GB node.
* ❌ **Full Ethereum / Bitcoin RPC Nodes (Geth, Besu, Erigon)**: Requires **16GB–64GB RAM** + 2TB NVMe SSD.
* ❌ **Full Block Explorers (Blockscout)**: Requires **4GB–8GB RAM** for Erlang/Elixir indexers.

### 2. High-Risk Workloads
* ⚠️ **Kubo (IPFS Node)**: Consumes **1.5GB–3GB RAM** for DHT peer discovery and content routing, starving surrounding applications.

### 3. Feasible & High-Performance Workloads (<350MB RAM)
* ✅ **Foundry Anvil (`anvil`)**: **~80MB–150MB RAM**. Ultra-fast local EVM sandbox in Rust.
* ✅ **Thirdweb Engine**: **~250MB–350MB RAM**. Open-source Web3 REST API gateway (Node.js/Express) that integrates with existing Plex Commons (Postgres + Redis).
* ✅ **Ponder / Subsquid (Micro-Indexers)**: **~150MB–250MB RAM**. Streams on-chain events from managed remote RPCs into Commons Postgres.
* ✅ **Hook0 / Svix (Webhook Gateway)**: **~80MB–150MB RAM**. Ingests high-frequency on-chain webhooks with HMAC verification and guaranteed retries.
* ✅ **LaraKube Flow (`n8n` / `windmill`)**: **Existing Tool**. Direct webhook trigger ➔ multi-channel alert pipeline.

---

## 🏛️ Proposed Architecture

```
┌─────────────────────────────────────────────────────────────────────────┐
│                           LaraKube Cluster                              │
│                                                                         │
│  ┌───────────────────────┐       ┌───────────────────────────────────┐  │
│  │   Foundry Anvil       │       │         Thirdweb Engine           │  │
│  │   (Local EVM Testnet) │       │   (Self-Hosted REST Gateway)      │  │
│  │   • RAM: ~100MB       │       │   • RAM: ~300MB                   │  │
│  │   • Instant <100ms    │       │   • REST API: /wallet, /contract  │  │
│  │   • Port: 8545 (RPC)  │       │   • Uses Commons Postgres & Redis │  │
│  └───────────────────────┘       └─────────────────┬─────────────────┘  │
│                                                    │                    │
│  ┌───────────────────────────────────────────────┐ │                    │
│  │          LaraKube Flow (n8n / Windmill)       │ │                    │
│  │  • Webhook Node: receives on-chain events     │ │                    │
│  │  • Actions: Discord, Telegram, Stalwart Mail  │ │                    │
│  └───────────────────────▲───────────────────────┘ │                    │
│                          │                         │                    │
│                          ▼                         ▼                    │
│  ┌───────────────────────────────────────────────────────────────────┐  │
│  │                       Plex Commons & Security                     │  │
│  │  • PostgreSQL: `thirdweb_engine` / `web3_events` tenant          │  │
│  │  • Redis: Transaction queues & event workers                      │  │
│  │  • OpenBao: Backend signing keys & wallet KMS                     │  │
│  │  • Zitadel SSO: Admin dashboard RBAC gating                       │  │
│  └───────────────────────▲───────────────────────────────────────────┘  │
│                          │                                              │
└──────────────────────────┼──────────────────────────────────────────────┘
                           │ HTTPS Webhooks (Helius / QuickNode / Alchemy)
                           ▼
               ┌───────────────────────────────────────┐
               │    Solana / Ethereum Blockchains      │
               │                                       │
               │  • Rust Anchor Program (e.g. Tipping) │
               │  • Phantom / Solflare Wallet Tx       │
               │  • Helius Event Stream (Free 100k/mo) │
               └───────────────────────────────────────┘
```

---

## 🛠️ Solana & Anchor Smart Contract Integration Model

### The Anchor Tipping / Micropayment Pattern
For applications running Solana smart contracts written in Rust (using **Anchor**):
1. **Contract Structure**:
   * `Cargo.toml`: Rust dependency manifest.
   * `programs/tipping/src/lib.rs`: Smart contract implementing instructions (e.g. `send_tip(amount, message)`).
   * `target/idl/tipping.json`: Interface Definition Language schema (similar to OpenAPI/Swagger JSON).
2. **Frontend Settlement**:
   * Supporters connect Phantom/Solflare wallets via `@solana/wallet-adapter`.
   * The transaction settles directly peer-to-peer in ~400ms for <$0.001.
3. **Backend Event & Notification Ingestion**:
   * **Tier 1 (Helius Webhook + Laravel / n8n)**: Helius monitors the Anchor Program ID and POSTs webhooks to `https://app.example.com/api/webhooks/solana` or to `flow-n8n`.
   * **Tier 2 (LaraKube Flow Pipelines)**: Ingests tip payload ➔ dispatches Telegram/Discord notifications + emails via Stalwart (`mail:init`) + logs to Teable (`sheet:init`).

---

## 🛠️ Companion Tool Blueprint

### 1. `chain:init` / `anvil:init` (Local EVM Sandbox)
* **Image**: `ghcr.io/foundry-rs/foundry:latest` (`anvil --host 0.0.0.0 --port 8545`)
* **Footprint**: ~80MB RAM, zero disk overhead (in-memory with optional persistence).
* **Endpoints**:
  * Local HTTP/WS RPC: `http://chain.dev.test:8545`
  * Pre-funded with 10 test accounts (10,000 ETH each).
* **Use Case**: Instant local blockchain environment for automated testing, CI/CD, and frontend wallet connection without external faucets.

### 2. `web3:init` (Thirdweb Engine REST Gateway)
* **Image**: `thirdweb/engine:latest`
* **Footprint**: ~250MB–350MB RAM.
* **Integrations**:
  * **Database**: Allocates `thirdweb_engine` tenant in Plex Commons Postgres.
  * **Queue**: Connects to Plex Commons Redis for resilient transaction queueing.
  * **Secrets / KMS**: Stores backend wallet private keys and master access tokens in OpenBao (`secrets:wire`).
  * **Ingress / Auth**: Exposes `https://web3.example.com` protected by Zitadel OIDC RBAC (`sso:wire`).
* **Use Case**: Gives any application (Laravel, Node, Python, Go) a clean REST API (`POST /wallet/transfer`, `POST /contract/read`, `POST /contract/write`) to interact with **both Solana and EVM chains** using managed backend wallets and gasless transactions.

### 3. Solana Remote Strategy (Managed RPC Relay)
* Because Solana nodes require 16GB+ RAM, Solana integration on a 2GB VPS relies on **managed RPC relays**:
  * The backend/Thirdweb Engine communicates with free-tier RPC providers (e.g., **Helius**, **QuickNode**, or **Alchemy**).
  * In-cluster daemons or LaraKube Flow (`n8n`) handle webhook consumption and state indexing directly into Postgres.

---

## 📋 Implementation Roadmap

### Phase 1: Local EVM Sandbox (`chain:init` / `anvil:init`)
- [ ] Add `ClusterTool::CHAIN` enum and `ChainTool` vendor class.
- [ ] Create Blade manifests for Anvil Deployment and Service (`chain-anvil-{$instance}`).
- [ ] Implement `ChainInitCommand` and `ChainRemoveCommand`.
- [ ] Write feature tests for deployment, ports, and lifecycle.

### Phase 2: Self-Hosted Web3 REST Engine (`web3:init`)
- [ ] Add `ClusterTool::WEB3` enum and `Web3Tool` vendor class.
- [ ] Create Blade templates for Thirdweb Engine Deployment, Service, and Ingress.
- [ ] Wire database tenant allocation in Commons Postgres and Redis queue configuration.
- [ ] Wire OpenBao static secret generation for encryption keys and master credentials.
- [ ] Connect Zitadel OIDC SSO for admin dashboard access.

### Phase 3: Webhook & Flow Automation Templates
- [ ] Add pre-built n8n / Windmill webhook workflows for Solana and EVM event ingestion (Discord/Telegram notifications + Postgres/Teable sync).
- [ ] Add documentation and starter guides for Solana Anchor dApp backend integrations in LaraKube.
