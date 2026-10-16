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
  {{-- The embedded Dex IdP is the ONLY issuer management ever validates, and
       that is the supported topology as of 0.77: the dashboard authenticates
       against Dex, and external providers registered via
       /api/identity-providers appear as extra login buttons that Dex federates
       to. NetBird retired its standalone-Zitadel installer for exactly this.

       Do NOT add an HttpConfig block pointing at an external issuer. It is the
       legacy standalone path, it is mutually exclusive with this one
       ("HttpConfig is ignored when EmbeddedIdP is enabled",
       management/cmd/management.go), and pointing the two halves at different
       issuers is silent: login succeeds, then every dashboard call fails.

       The /oauth2 suffix is required. Without it Dex initializes cleanly and
       /api/setup works, but every /oauth2/* route silently 404s — Dex builds
       its route table from the issuer's own path while management forwards the
       /oauth2-prefixed path regardless. Matches NetBird's own
       EmbeddedIdPConfig.Issuer example. --}}
  "EmbeddedIdP": {
    "Enabled": true,
    "DataDir": "/var/lib/netbird/idp",
    "Issuer": "https://{{ $host }}/oauth2",
    {{-- Dex registers its static `netbird-dashboard` client with ONLY these
         plus a derived /api/reverse-proxy/callback. Omit them and the dashboard's
         own AUTH_REDIRECT_URI is rejected at the authorize step with a bare
         "Unregistered redirect_uri" — before any login form is shown. Must stay
         in step with AUTH_REDIRECT_URI in shared.blade.php. --}}
    "DashboardRedirectURIs": ["https://{{ $host }}/nb-auth", "https://{{ $host }}/nb-silent-auth"],
    "DashboardPostLogoutRedirectURIs": ["https://{{ $host }}/"]
  },
  "EncryptionKey": "{{ $dataStoreEncryptionKey }}"
}
