# Plan: Multi-Node k3s (grow a VPS server into a cluster)

**Status:** Deferred (2026-11-22). Cross-provider migration of a whole k3s VPS comes first; then this. Nothing built yet.

## Why

Today a LaraKube VPS server is one machine running a one-node k3s cluster. When it runs out of room there are two options:

- `cloud:scale` resizes the one machine (vertical). It has a ceiling, and the server is still a single point of failure.
- `cloud:migrate` to managed Kubernetes (DOKS/GKE/EKS). The provider runs the control plane, at a price, and only on that provider.

Multi-node k3s is the third option, scaling out:

- Add worker VPSes to the same cluster. Their CPU and RAM join the pool, apps spread across them, and if a worker dies its pods restart elsewhere.
- It stays on cheap VPSes, on any provider LaraKube supports, under the user's control.

## Facts this plan rests on

Checked 2026-11-22.

- **Joining a node:** an agent joins with `K3S_URL=https://<server>:6443` and `K3S_TOKEN`.
  - The server's join token is in `/var/lib/rancher/k3s/server/node-token`.
  - `K3S_AGENT_TOKEN` is an agent-only secret: it can join agents but not servers. ([k3s agent CLI](https://docs.k3s.io/cli/agent), [server CLI](https://docs.k3s.io/cli/server))
- **Nodes on different networks or providers:**
  - Servers run `--node-external-ip=<ip> --flannel-backend=wireguard-native --flannel-external-ip`.
  - Agents run `--node-external-ip=<ip>`.
  - Pod traffic between nodes goes through WireGuard, so every node needs the WireGuard tools. ([k3s distributed/multicloud](https://docs.k3s.io/networking/distributed-multicloud))
- **Sizing:**
  - A k3s agent needs at least 1 core and 512 MB; a server needs 2 cores and 2 GB. ([k3s requirements](https://docs.k3s.io/installation/requirements))
  - Embedded etcd for 3-server high availability needs an odd number of servers.
- **Longhorn:** it recommends 3 nodes at 4 vCPU / 4 GiB each for production ([Longhorn best practices](https://documentation.suse.com/cloudnative/storage/1.7.0/en/installation-setup/best-practices.html)). That's too heavy for LaraKube's small-VPS audience, so it's not the default.
- **DigitalOcean block storage:** `csi-digitalocean` is a general CSI driver, mostly tested on Kubernetes. Running it on self-managed k3s (outside DOKS) is not confirmed: test it before relying on it.
  - Volumes are regional and ReadWriteOnce. ([csi-digitalocean](https://github.com/digitalocean/csi-digitalocean), [DO volume features](https://docs.digitalocean.com/products/kubernetes/details/volume-features))
  - Hetzner's `hcloud-csi` and GCP's PD CSI still need the same check.

Re-verify versions and flags before building (repo rule: check the latest release before pinning).

## How the install works today

- `ProvisionsK3sNode::installK3s()` installs a single-node server with these flags:
  - `--disable=traefik`
  - `--tls-san=<public ip>`
  - `--node-name=$(hostname -s)`
  - `--secrets-encryption`
  - default flannel (VXLAN)
- Volumes use `local-path`, which binds each PersistentVolumeClaim to the node it was first scheduled on.
- The Plex Commons (MariaDB, Postgres, Redis, SeaweedFS) and every Cluster Tool with a volume are single replicas on local disk.
- DNS (ExternalDNS) points each host at the one server IP, where Traefik takes the traffic.

## Design

### Phase 1: add worker nodes (capacity)

The goal is more room on cheap VPSes, without solving storage yet.

1. **New servers are created ready for workers.**
   - `installK3s()` adds `--flannel-backend=wireguard-native --flannel-external-ip --node-external-ip=<ip>` and installs `wireguard-tools`.
   - The server node gets the label `larakube.io/role=primary`.
   - Existing servers are not converted in place (pre-release rule: no one-time migration code). To use workers, a server is created again.
2. **Commands** (standalone, per the no-hidden-flags rule; names to confirm):
   - `cloud:node:add {server} --size=` creates a VPS in the same provider, account and region, and joins it as an agent with the server's `K3S_AGENT_TOKEN` and `--node-external-ip`. It's labelled `larakube.io/role=worker` and named `{server}-worker-{n}`.
   - `cloud:node:remove {server} {node}` runs `kubectl drain`, deletes the node from the cluster, then destroys the VPS. It refuses if the node holds a local volume.
   - `cloud:nodes {server} --json` lists each node: role, IP, size, status, and allocatable vs used CPU and memory.
3. **OpenTofu:** the server's stack gains a `workers` map, keyed by node name and holding the size. Adding or removing a worker is a `tofu apply` of the same stack, so state stays in one place.
   - The firewall opens 6443/tcp (agents to server), 51820/udp (WireGuard, between nodes) and 10250/tcp (kubelet, metrics) to the other nodes' IPs only, never to the world.
4. **Stateful workloads stay on the primary node.**
   - The Commons and every Cluster Tool with a volume get `nodeAffinity` to `larakube.io/role=primary`.
   - Stateless pods (project web and queue pods, apps with no volume) can spread.
   - This keeps `local-path` data safe: nothing with a disk lands on a worker that can be removed.
5. **Traffic:** DNS and Traefik stay on the primary. Traefik reaches pods on workers over the cluster network, so no load balancer is needed yet.
6. **Capacity guard:** sum allocatable resources across nodes, so "will it fit" sees the whole cluster.
7. **Desktop:**
   - The server page gets a **Nodes** card: one row per node with role, size, IP and CPU/RAM use, plus "Add worker" and "Remove" (with typed-name confirmation).
   - The memory breakdown becomes per node.

### Phase 2: storage that can move (resilience)

- Install the provider's CSI driver as the default StorageClass where it works on self-managed k3s: DigitalOcean Volumes, Hetzner Volumes, GCP PD. Verify each first.
- Then stateful workloads no longer need pinning to the primary node, and a lost node's volumes reattach elsewhere. Commons and tools move to the CSI StorageClass on their next `*:init`. Moving existing data needs the backup/restore path (`backup:run` then `backup:restore`), not in-place code.
- Longhorn stays opt-in for 3+ node clusters that meet its sizing.

### Phase 3: high availability

- Three server nodes with embedded etcd (`--cluster-init` on the first, `--server` on the others).
- A load balancer, or DNS with several A records, in front of Traefik.
- Replicated Commons (Postgres and MariaDB replicas) when needed.

## Naming

Once a server has workers, "Server" describes one machine inside a cluster. Desktop's Servers page keeps the name for now. The server page shows its nodes, and the label is reconsidered when Phase 1 ships.

## Open questions

1. ~~Same-provider or cross-provider?~~ **Decided:** same provider first.
2. Should workers be allowed on a server created before Phase 1? The rule says recreate; check that this is acceptable for luchtech-vps.
3. Check the minimum worker size the capacity guard should accept against real usage on a 1 GB VPS.
4. How Desktop shows a worker that went unreachable: reuse the server-health gate (`ContextHealth`).

## Tests

- **Unit:**
  - install flags, the `workers` tofu map, firewall rules scoped to node IPs;
  - affinity rendered on every stateful manifest. Add a matrix test so a new tool can't skip it.
- **Feature:**
  - `cloud:node:add`, `remove` and `cloud:nodes` with faked tofu, ssh and kubectl;
  - `remove` refuses a node holding a local volume.
- **Live verification:**
  - create a server, add a worker, and deploy a stateless app with 3 replicas: pods should land on both nodes;
  - remove the worker: pods should reschedule, with no data lost.
