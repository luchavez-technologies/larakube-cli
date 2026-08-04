# Plan: MatrixRTC "oldest_membership" Reconnect Loop (Upstream, Not LaraKube's)

**Status:** Active / Tracking Only — no fix available in LaraKube's control
**Created:** 2026-08-04
**Parent doc:** `plans/active/matrix-voice-video-calling.md`

---

## 1. Symptom

Two real users (`@james`, `@eman`) on a live call in "Meeting Room": video appears briefly, then the other side sees "Waiting for media." Confirmed via live logs this isn't a one-off — both participants' LiveKit sessions cycle (`participant closing` → `starting RTC session`) roughly every 15-17 seconds, in near-lockstep, for the entire duration of the call. Every infrastructure layer underneath this (Coturn, LiveKit routing, JWT exchange, firewalls) was independently verified working during this same debugging session — this is a separate, later-stage issue.

## 2. Root cause (confirmed via live room state, not guesswork)

Pulled the room's live state via Synapse's admin API (`GET /_synapse/admin/v1/rooms/{roomId}/state`) during the call. Every participant's `org.matrix.msc3401.call.member` event contains:

```json
"focus_active": { "type": "livekit", "focus_selection": "oldest_membership" }
```

`"oldest_membership"` means the client elects whichever participant's membership event is oldest as the authoritative session anchor. But each client also **refreshes its own membership event periodically** (a fresh `PUT .../state/org.matrix.msc3401.call.member/...`, confirmed succeeding with `200` every time in Synapse's access log — not a server-side rejection). Every refresh gets a new `origin_server_ts`, which flips "who is oldest" between the two participants on every heartbeat. Both clients see the active focus change and both tear down + rebuild their LiveKit session — hence the synchronized ~15s cycling, and hence "camera shows, then waiting for media" every cycle as the remote track gets torn down and re-subscribed.

`focus_selection: "oldest_membership"` is written by the **client** (Element Call, bundled inside Cinny) into its own state event content — it is not something `homeserver.yaml`, Synapse config, or any LaraKube-rendered manifest sets or can override. There is no server-side knob for this.

## 3. What was ruled out

- Not a Cinny version issue: diffed `v4.12.3` (pinned in `matrix.blade.php`) against latest `v4.12.6` — no commits touching call/RTC/focus/member logic in that range, only dependency bumps and an unrelated device-management endpoint fix. Bumping the image wouldn't help.
- Not a Synapse-side rejection/rate-limit: every membership PUT returns `200`, no errors, no permission issues, correlates with nothing else in Synapse's logs at the reconnect timestamps.
- Not our firewall/routing work from earlier in this session: all confirmed independently working (STUN reachable, JWT exchange succeeds, LiveKit `CreateRoom`/track-publish all succeed) — this cycling happens *after* a successful connection, not because one failed to establish.

## 4. Options going forward

1. **Track upstream.** Check `element-hq/element-call` (the actual embedded client, vendored into Cinny as `@element-hq/element-call-embedded`) and `matrix-org/matrix-js-sdk`'s issue trackers for known reports of `oldest_membership` focus-selection instability / reconnect loops. Not yet done — next step if this needs to keep moving.
2. **Try `focus_selection: "oldest_membership"` alternatives** — MSC4143 doesn't currently define another algorithm as far as this investigation went; worth checking if a newer matrix-js-sdk / Element Call version (independent of Cinny's own release cadence — it's a vendored sub-dependency) has moved to a stabler selection strategy.
3. **No LaraKube-side workaround identified.** This isn't a `chat:init`/Kubernetes/Traefik config problem, so there's nothing to change in `matrix.blade.php` or the Synapse/Coturn/LiveKit setup for it specifically.

## 5. What's confirmed working (for contrast — don't re-litigate these)

Signaling, TURN relay (Coturn, real STUN response verified from inside the node and via DNAT rule inspection), DO cloud firewall + host UFW (both open for Coturn/LiveKit's UDP/TCP ports), the `.well-known/matrix/client` `rtc_foci` key, federation `.well-known/matrix/server` delegation, and the `lk-jwt-service` JWT exchange (`/livekit/jwt/sfu/get` routing + strip-prefix Middleware) — all fixed and live-verified earlier in this same session. Calls do connect and do publish video/audio; the only remaining problem is the periodic focus-selection-driven reconnect described above.
