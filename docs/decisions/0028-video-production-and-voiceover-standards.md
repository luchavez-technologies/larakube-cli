# 0028 — Video Production, AI Voiceover, and OpenScreen Remastering Standards

**Status:** Accepted (2026-11-08)

## Context

LaraKube tutorial videos published on YouTube require continuous visual and auditory refinement to maximize developer engagement, retention, and accessibility across desktop and mobile devices. Initial silent takes with small caption text caused audience friction. Additionally, naïve AI voice synthesis introduced audio distortions (mangling across speed regions, boundary pops across clip cuts, and tokenizer glitches with hyphenated names).

To ensure every tutorial clip maintains studio-grade consistency, clarity, and reliability, we establish an explicit production standard.

## Decision

All video remasters and production tutorials in OpenScreen must strictly adhere to the following 7 invariants:

### 1. TTS Engine & Model Selection
- **Model**: Must use ElevenLabs **`eleven_v4`** (`model_id: "eleven_v4"`). Earlier models (`eleven_multilingual_v2`) suffer from word-stuttering, robotic artifacts, and hallucinated phonemes on custom clones.
- **Voice**: Must use the calibrated **"James Carlo"** cloned voice (`ID: MygA9aaydxOchycGVIMI`).
- **Voice Settings**:
  - `stability`: `0.70` – `0.75` (high stability eliminates cadence wavering and word stuttering).
  - `similarity_boost`: `0.75` (prevents amplifying microphone sample noise).
  - `style`: `0.0` (exaggeration off to prevent artificial pitch spikes).
  - `use_speaker_boost`: `true`.

### 2. Pronunciation Dictionary Invariant
- Whenever generating speech via the ElevenLabs API, requests **must explicitly pass** `pronunciation_dictionary_locators` with the active workspace dictionary (`{"pronunciation_dictionary_id": "j2KLW9JVRqfuHUuM3Pdc", "version_id": "2pGB88vwBhxGC4Uc1EDx"}`).
- The script text passed to the API **must use natural casing** (e.g. `LaraKube`, `K3s`) rather than manual hyphens (`Lara-cube`, `K-3-s`), as raw hyphens confuse the model tokenizer into unnatural syllable pauses. The linked dictionary handles phonetic mapping under the hood.

### 3. Speed Region Invariant (Zero Fast-Forward Mangling)
- Voiceover tracks **must never overlap or cross into** a fast-forward / speed region (e.g. 4× or 6×).
- Because OpenScreen's playback engine accelerates audio sitting inside speed regions, voiceover must conclude **at least 0.5s before** any speed region begins.
- Fast-forward sections play silently (or with background audio/sound effects), accompanied by a clear descriptive subtitle (e.g. *"Fast-forwarding: building your automated private cloud"*). Spoken voiceover resumes only after the speed region has fully completed.

### 4. Clip Boundary & Fragment Invariant (Zero Audio Scratching)
- Each audio track must sit completely within a single video clip.
- Voiceover tracks **must never be anchored across a video clip junction cut** (which causes OpenScreen to split the audio into fragmented buffer segments, producing audible pops/scratches).
- Every audio track placed with `addAudio` should include `fadeInMs: 50` for smooth acoustic onset.

### 5. Mobile-First High-Visibility Subtitles
- Completely eliminate small (26px–34px) paragraph text annotations.
- Subtitles must be bold, concise, centered bottom cards:
  - `fontSize`: **58px–65px** (scaled to 4K / 3840×2100).
  - `y`: `90` (centered bottom).
  - `color`: `#ffffff`.
  - `background`: `rgba(0, 0, 0, 0.85)` (dark high-contrast pill).
  - `animation`: `fade`.

### 6. Mobile-First Targeted Zooms
- Videos recorded on 4K / high-resolution monitors must feature proactive zoom-ins on active UI interactions:
  - Magnification: **1.50× to 1.80×** (`depth 2` or `3`).
  - Framing: Centered on active dropdowns, form inputs, credentials, and live health badges.
  - Duration: Hold across the entire duration of the user action.

### 7. Story-Driven, Jargon-Free Narrative Arc
- Frame tutorials around real-world developer outcomes ("Why you should care") rather than dry mechanical clicks:
  - The "Private App Cloud" perspective: Self-healing, free automated SSL, and one-click microservices without paying \$50–\$200/month to Heroku, Render, or Forge.
  - Natural developer language over intimidating enterprise jargon (e.g. explain *automatic crash recovery* rather than *K8s replica reconciliation loops*).

### 8. Product Naming Standard ("LaraKube Desktop")
- In all tutorial videos, screen demos, and video narration, the graphical application **must explicitly be called "LaraKube Desktop"**.
- Because "LaraKube" refers to the core underlying Kubernetes orchestration engine and CLI, emphasizing **"LaraKube Desktop"** clarifies to viewers that they are using the visual native desktop interface that eliminates terminal commands.

## Consequences

- Zero regression in audio clarity, pacing, or mobile readability across future video releases (n8n, PocketBase, WordPress, etc.).
- Complete reproducibility across OpenScreen project remasters.
- Fast, deterministic voiceover synthesis without repeated trial-and-error re-takes.
