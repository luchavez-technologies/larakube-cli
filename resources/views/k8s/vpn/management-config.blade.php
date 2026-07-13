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
  "HttpConfig": {
    "Address": "0.0.0.0:80"
  },
  "DataDir": "/var/lib/netbird"
}
