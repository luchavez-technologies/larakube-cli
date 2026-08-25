{{-- rawurlencode() on user/password is load-bearing, not defensive
     styling — confirmed live 2026-08-24: an unescaped special character in
     a Commons-managed (OpenBao-generated) password broke the URI parser
     ("could not parse database connection string ... invalid port
     number" — a `@` or `:` in the raw password shifts where the parser
     thinks the host/port segment starts). The explicit :5432 port matches
     every other postgresql:// URI already built elsewhere in this repo
     (PasswordTool, SheetTool, design/shared.blade.php, etc.) — Commons
     Postgres is always 5432, and omitting it may have been a second
     contributing factor to the same parse failure.

     Built as ONE PHP expression, not interleaved `@{{ }}` tags — Blade
     reads a literal `@` immediately followed by `{{` as ITS OWN escape
     syntax for a literal `{{ }}` (exactly what the claims_imports
     templates below use deliberately), so `...@{{ $database['host'] }}`
     silently rendered as the literal text "{{ $database['host'] }}" with
     no interpolation and no `@` at all — caught writing a direct test for
     this file, not live. --}}
database:
  uri: "{{ 'postgresql://'.rawurlencode($database['user']).':'.rawurlencode($database['password']).'@'.$database['host'].':5432/'.rawurlencode($database['database']) }}"
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
      {{-- REQUIRED for syn2mas to link EXISTING Synapse users who
           authenticated via classic oidc_providers: onto this MAS
           provider during migration — confirmed live 2026-08-24 both ways:
           `syn2mas check` refused to proceed without it, AND a first
           attempt using the bare `idp_id: zitadel` value from
           oidc-providers-block.blade.php ALSO failed the same check.
           Synapse internally prefixes every OIDC-type auth provider with
           "oidc-" when storing it as `auth_provider` in user_external_ids
           (confirmed via `mas-cli config generate --synapse-config
           <path>`, which reads Synapse's own config and produces this
           exact prefixed value) — the bare config-file idp_id is NOT what
           actually needs to match here. --}}
      synapse_idp_id: oidc-zitadel
      claims_imports:
        {{-- Matrix localparts only allow lowercase letters, digits, and
             ./_/=/-// — never '@'. preferred_username is email-shaped in
             this Zitadel setup (every account logs in by email), so using
             it as-is fails MAS's policy check the moment anyone actually
             logs into Element via SSO ("username contains invalid
             characters", confirmed live 2026-08-24 — chat:user-created
             local accounts never hit this path, so it went unnoticed until
             then). Fix matches MAS's own documented example verbatim:
             https://element-hq.github.io/matrix-authentication-service/reference/configuration.html --}}
        localpart:
          action: require
          template: "@{{ user.email.split('@')[0].lower() }}"
        displayname:
          action: suggest
          template: "@{{ user.name }}"
        email:
          action: suggest
          template: "@{{ user.email }}"
{{-- Confirmed live 2026-08-24 via syn2mas check: mas-cli config
     generate's own default password scheme is NOT bcrypt, which syn2mas
     requires to import Synapse's existing local-password account hashes
     (chat:user creates real local-password Matrix accounts, not just
     SSO-linked ones). unicode_normalization: true is documented as
     required specifically for imported passwords to verify correctly. --}}
passwords:
  enabled: true
  schemes:
    - version: 1
      algorithm: bcrypt
      unicode_normalization: true
