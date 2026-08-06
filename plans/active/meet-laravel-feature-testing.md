# Plan: verify `LaravelFeature::MEET` against a real Laravel app

**Status:** Active — code committed, never exercised
**Created:** 2026-08-07
**Commit:** `c858fdb` (`feat(Meet): add the MEET Laravel feature so apps can build on the shared SFU`)

---

## Context

`meet:init` / `meet:wire` are live and verified — Matrix calling works through
`meet.luchtech.dev`, sessions hold for minutes instead of rejoining every ~15s. The Laravel-app
half shipped the same day but **no Laravel project has ever run it**. Everything below is
unverified against reality; the unit tests only prove the I/O-free paths and a faked cluster.

The motivating use case is a timed-rotation app (5-minute rounds, participants reshuffled) — so
room lifecycle and server-side events matter more than raw media.

---

## ⚠️ Known gap: webhooks are not actually registered

The registry schema carries `webhookUrl`, `livekit.blade.php` renders a `webhook:` block from
it, and `meet:show` displays it — but **nothing ever sets it**. Both callers of
`allocateMeetKey()` pass three arguments, leaving `webhookUrl` null:

- `app/Enums/LaravelFeature.php:585`
- `app/Commands/Meet/MeetWireCommand.php:72`

So a project gets credentials and a room prefix, but LiveKit will never call it back. For a
timed-rotation app this is the missing half: without `participant_joined` / `room_finished` the
app has to poll `RoomService` to know when a round ends.

**To close it,** decide how the URL is supplied (in rough order of preference):

1. A `webhook` entry in the project's `EnvironmentData.hosts`, so it differs per environment —
   fits the existing per-env schema and needs no new flag.
2. A `--webhook-url=` option on `larakube add meet`, threaded into `onPostInstall()`.
3. Derived from the app URL as `<app-url>/livekit/webhook` by convention.

Whichever wins, `allocateMeetKey()` already accepts it as its 4th argument and the template
already handles the single-signer rule — this is a small change, not a redesign.

**The constraint that shapes it:** LiveKit signs webhooks with ONE key and sends every event to
every registered URL. The template therefore wires webhooks only while exactly one consumer has
a URL, and omits the block entirely otherwise (see `docs/decisions/0009-*.md`). A second
subscriber would receive events it cannot verify. Multi-app webhooks need a filtering relay,
which is deliberately not built.

---

## Prerequisites

1. `cd cli && ./build` — the working build predates the media-prune CronJob and this feature.
2. `larakube chat:init --env=production` — deploys `chat-media-prune`, still not live.
3. Meet must be installed on whichever cluster the test project targets. On **local** that means
   `larakube meet:init` first; `onPostInstall()` degrades to empty placeholders when Meet is
   absent, which looks like a bug but is the designed fallback.

Use a scratch project, not the existing test app.

---

## Test sequence

### 1. Scaffold and add the feature

```
larakube new meet-probe        # or an existing throwaway project
cd meet-probe
larakube add meet
```

Verify:
- `composer.json` gained `agence104/livekit-server-sdk`
- `.env` has `LIVEKIT_URL`, `LIVEKIT_API_KEY`, `LIVEKIT_API_SECRET`, `LIVEKIT_ROOM_PREFIX`
- `LIVEKIT_ROOM_PREFIX` is `meet-probe-`
- `LIVEKIT_URL` is the **shared** host — `wss://meet.<tld>`, never `meet.meet-probe.<tld>`
- `larakube meet:show` lists a new consumer `app-meet-probe`
- The key in `.env` matches the one in `meet-keys`, and `_system` + `chat` are untouched

### 2. Confirm re-running does not rotate

```
larakube add meet     # again
```

`allocateMeetKey()` keeps an existing pair on purpose — an app already holding the key in `.env`
must not be invalidated. Confirm the key is unchanged and `meet:show` still lists one consumer.

### 3. Mint a token and join

Server-side, with the SDK:

```php
$token = (new AccessToken($key, $secret))
    ->setIdentity('alice')
    ->setGrant((new VideoGrant)->setRoomJoin()->setRoomName(env('LIVEKIT_ROOM_PREFIX').'round-1'));
```

Join from two browsers against `LIVEKIT_URL`. Watch:

```
kubectl --context=larakube-159.89.205.239 logs deploy/meet-livekit -n larakube-shared -f
```

Pass: one `starting RTC session` per participant that persists, `[selected]` pairs on both
publisher and subscriber, no `unsupported datachannel`.

### 4. Confirm isolation is only a convention

Mint a token for a room **outside** the prefix (e.g. `matrix-anything`) and join it. It will
**succeed** — OSS LiveKit does not restrict a key to a room. This is expected and is exactly why
the prefix must be enforced in application code. Worth doing once so the limit is understood
rather than assumed.

### 5. Timed-rotation shape

Exercise what the real use case needs, using `RoomServiceClient`:
- create a room with `emptyTimeout` / `maxParticipants`
- move or disconnect participants at the round boundary
- confirm `deleteRoom` ends the session cleanly

Without webhooks this requires polling — which is the practical argument for closing the gap
above.

---

## Things most likely to break

- **`onPostInstall()` silently returning `[]`** when Meet isn't installed, leaving empty
  credentials in `.env`. Correct behaviour, confusing symptom. Check `meet:show` first.
- **`LIVEKIT_URL` wrong for cloud.** The local default is `wss://meet.<global tld>`;
  `onPostInstall()` overrides it from the tool registry. If a project is added while pointed at
  a cluster where `meet` is unregistered, the local default sticks.
- **A stale build.** `larakube add meet` runs from the compiled phar, so an un-rebuilt binary
  will not have this feature at all.
- **`meet:init` wiping the app's key** — fixed by `5372177` (jsonpath escaping), but if an app's
  credentials ever stop working, check `meet:show` before anything else.

## Definition of done

- A Laravel app joins a LiveKit room using credentials `larakube add meet` produced, with no
  hand-editing of `.env`.
- Re-running `add meet` is idempotent.
- The webhook gap is either closed or consciously deferred with the decision recorded here.
- `plans/testing-checklist.md` gains a dated section for this run.
