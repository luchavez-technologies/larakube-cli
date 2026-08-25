{
  "Stuns": [],
  "TURNConfig": {
    "Turns": [],
    "CredentialsTTL": "12h",
    "Secret": "",
    "TimeBasedCredentials": false
  },
  "Signal": {
    "Proto": "https",
    "URI": "{{ $host }}:443"
  },
  "Relay": {
    "Addresses": ["rels://{{ $host }}:443/relay"],
    "CredentialsTTL": "24h",
    "Secret": "{{ $relaySecret }}"
  },
  "DataDir": "/var/lib/netbird",
  "DataStoreEncryptionKey": "{{ $dataStoreEncryptionKey }}",
  "EmbeddedIdP": {
    "Enabled": true,
    "DataDir": "/var/lib/netbird/idp",
    {{-- Confirmed live 2026-08-25: without the /oauth2 suffix, Dex still
         initializes without error ("embedded Dex IDP initialized with
         issuer: ...") and /api/setup still works fine, but EVERY
         /oauth2/* route (the CLI's own interactive SSO login) silently
         404s with nothing logged — Dex builds its internal route table
         from the issuer's OWN path portion, while netbird-management's
         outer router unconditionally forwards the /oauth2-prefixed path
         to it regardless. Matches the exact example format in NetBird's
         own EmbeddedIdPConfig.Issuer doc comment
         ("https://management.netbird.io/oauth2"). --}}
    "Issuer": "https://{{ $host }}/oauth2"
  },
  "EncryptionKey": "{{ $dataStoreEncryptionKey }}"
}
