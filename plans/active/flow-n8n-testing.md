# Walkthrough: n8n on a cloud cluster, per instance

Verifies `flow:init --engine=n8n` after the move to per-instance names
(`app/Tools/N8n.php`, `ToolInstance`), and that `flow:remove` keeps the
encryption key. Do steps 1–4 **before** handing the instance to anyone: step 4
removes and reinstalls it.

Replace `flow.example.com` with the real host. `--vpn-only` is optional.

## 1. Install
```bash
larakube flow:init production --engine=n8n --domain=flow.example.com
```
Expect: `✅ Flow (n8n) stack is live.` and the URL.

Read-only check that every name carries the instance (`flow-example-com`):
```bash
kubectl get deploy,svc,ingress,pvc,secret -n larakube-shared | grep flow-n8n
```
Expect `flow-n8n-flow-example-com` (Deployment, Service, Ingress),
`flow-n8n-storage-flow-example-com` (PVC), `flow-n8n-secrets-flow-example-com` (Secret).
Nothing named plain `flow-n8n` or `flow-secrets`.

## 2. Claim the owner account
Open `https://flow.example.com` immediately: n8n has no seeded admin, so the
first visitor becomes the owner.

## 3. Mail (password reset, invites)
```bash
larakube mail:wire production --tool=flow --domain=flow.example.com
```
Expect it to target `flow-n8n-flow-example-com`. In n8n, **Settings → Users →
Invite** someone; the invite email arrives.

## 4. Removal keeps credentials readable
1. In n8n, create any credential (e.g. a dummy HTTP Header Auth) and save it.
2. Remove without `--purge`:
   ```bash
   larakube flow:remove production --domain=flow.example.com --force
   ```
   Expect the warning to say the data volumes and encryption key are **preserved**.
3. Reinstall with the same command as step 1.
4. Sign in with the same owner account and open the credential from 1. It
   opens without "Credentials could not be decrypted".

## 5. (Optional) a second instance
```bash
larakube flow:init production --engine=n8n --domain=automation.example.com
```
Both hosts work, each with its own owner and database
(`n8n_flow_example_com`, `n8n_automation_example_com`). Then
`flow:remove production --domain=automation.example.com --force --purge`
removes only the second one; `flow.example.com` keeps working.

## 6. One engine per host
```bash
larakube flow:init production --engine=windmill --domain=flow.example.com
```
Expect a refusal naming `flow.example.com already runs n8n`, and no change.

## Result
- [ ] 1 names  - [ ] 2 owner  - [ ] 3 mail  - [ ] 4 key kept  - [ ] 5 second instance  - [ ] 6 refusal
