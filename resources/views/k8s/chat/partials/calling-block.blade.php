@if($meetJwtUrl)
experimental_features:
  msc3401_enabled: true
  msc3266_enabled: true
  msc4140_enabled: true
max_event_delay_duration: 24h
rc_message:
  per_second: 0.5
  burst_count: 30
rc_delayed_event_mgmt:
  per_second: 1
  burst_count: 20
@endif
@if($meetJwtUrl || $masPublicIssuer)
extra_well_known_client_content:
@if($meetJwtUrl)
  "org.matrix.msc4143.rtc_foci":
    - type: livekit
      livekit_service_url: "{{ $meetJwtUrl }}"
@endif
@if($masPublicIssuer)
  "org.matrix.msc2965.authentication":
    issuer: "{{ $masPublicIssuer }}"
    account: "{{ $masPublicIssuer }}account/"
@endif
@endif
