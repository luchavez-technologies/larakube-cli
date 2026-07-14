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
    "Issuer": "https://{{ $host }}"
  },
  "EncryptionKey": "{{ $dataStoreEncryptionKey }}"
}
