@if($oidc)
oidc_providers:
  - idp_id: zitadel
    idp_name: "{{ $oidc['name'] }}"
    discover: true
    issuer: "{{ $oidc['issuer'] }}"
    client_id: "{{ $oidc['client_id'] }}"
    client_secret: "{{ $oidc['client_secret'] }}"
    scopes: ["openid", "profile", "email"]
    allow_existing_users: true
    user_mapping_provider:
      config:
        localpart_template: "@{{ user.preferred_username }}"
        display_name_template: "@{{ user.name }}"
        email_template: "@{{ user.email }}"
@endif
