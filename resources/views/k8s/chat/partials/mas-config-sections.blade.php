database:
  uri: "postgresql://{{ $database['user'] }}:{{ $database['password'] }}@{{ $database['host'] }}/{{ $database['database'] }}"
matrix:
  homeserver: "{{ $matrixTrust['homeserver'] }}"
  secret: "{{ $matrixTrust['secret'] }}"
  endpoint: "http://chat-synapse:8008"
upstream_oauth2:
  providers:
    - id: "{{ $upstream['id'] }}"
      issuer: "{{ $upstream['issuer'] }}"
      client_id: "{{ $upstream['client_id'] }}"
      client_secret: "{{ $upstream['client_secret'] }}"
      token_endpoint_auth_method: client_secret_basic
      scope: "openid profile email"
      claims_imports:
        localpart:
          action: require
          template: "@{{ user.preferred_username }}"
        displayname:
          action: suggest
          template: "@{{ user.name }}"
        email:
          action: suggest
          template: "@{{ user.email }}"
