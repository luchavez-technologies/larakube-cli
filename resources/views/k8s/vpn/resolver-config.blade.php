@php($sfx = ($instance ?? '') !== '' ? '-'.$instance : '')
---
apiVersion: v1
kind: ConfigMap
metadata:
  name: vpn-resolver-config{{ $sfx }}
  namespace: larakube-vpn
data:
  Corefile: |
    {{-- Port 5353, NOT 53. The NetBird client in this same pod already binds
         its own DNS server to <overlay-ip>:53 -- confirmed live 2026-08-30 with
         netstat inside the client container:

           udp  100.84.155.135:53   7/netbird

         A resolver on :53 here would collide with it, and pointing peers at
         the client's own :53 does not help either: it forwards to the cluster
         resolver, which answers these hosts from public DNS with the public
         address, which is the whole problem. The nameserver group registers
         this port explicitly. --}}
@foreach ($hosts as $host)
    {{-- Answer with the gateway peer's OWN overlay address: the peer then
         connects to the gateway over the mesh, and the socat sidecar forwards
         to Traefik, which sees a source address its allow-list permits. --}}
    {{ $host }}:5353 {
        {{-- No `fallthrough`. This block only ever sees queries for this one
             name, so falling through can only reach the end of the chain and
             return NXDOMAIN -- including for the AAAA query every client sends
             alongside the A. NXDOMAIN there means "this name does not exist",
             which is both untrue and, on macOS and iOS, enough to abandon the
             lookup. Answering authoritatively instead yields NOERROR with no
             records, which is what "no IPv6 address" is supposed to look
             like. --}}
        hosts {
            {{ $gatewayIp }} {{ $host }}
        }
        errors
    }
@endforeach
    {{-- Without this default block the resolver is authoritative for
         everything and hands back NXDOMAIN for the rest of the internet. Match
         domains mean peers should only ask about the hosts above, but an older
         or misconfigured client that asks about anything else must still get a
         real answer rather than a black hole. --}}
    .:5353 {
        {{-- Watches the Corefile and reloads the whole file in place, which is
             what lets the reconcile avoid restarting this pod. Restarting it
             re-enrols the NetBird client as a NEW peer on a NEW overlay
             address, invalidating the very records written moments earlier --
             observed live 2026-08-30, the group ended up aimed at a peer that
             was already disconnected. One `reload` covers every block. --}}
        reload 15s
        forward . 1.1.1.1 8.8.8.8
        cache 30
        errors
    }
