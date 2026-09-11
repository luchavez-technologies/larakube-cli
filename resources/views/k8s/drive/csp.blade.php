directives:
  child-src:
    - '''self'''
  connect-src:
    - '''self'''
    - 'blob:'
    - 'https://raw.githubusercontent.com/owncloud/awesome-ocis/'
    - '${OCIS_OIDC_ISSUER}'
  default-src:
    - '''none'''
  font-src:
    - '''self'''
  frame-ancestors:
    - '''self'''
  frame-src:
    - '''self'''
    - 'blob:'
    - 'https://embed.diagrams.net/'
@if ($office ?? false)
    # Without this the browser refuses to frame the editor at all and the
    # document area renders "This content is blocked" — the editor host has
    # to be an allowed frame source of the PARENT page, not just willing to
    # be framed (CODE already sends frame-ancestors for drive.test).
    - 'https://{{ $officeHost }}/'
@endif
  img-src:
    - '''self'''
    - 'data:'
    - 'blob:'
    - 'https://raw.githubusercontent.com/owncloud/awesome-ocis/'
@if ($office ?? false)
    # COLLABORATION_APP_ICON points at the editor's favicon, rendered by the
    # oCIS UI in the app menu.
    - 'https://{{ $officeHost }}/'
@endif
  manifest-src:
    - '''self'''
  media-src:
    - '''self'''
  object-src:
    - '''self'''
    - 'blob:'
  script-src:
    - '''self'''
    - '''unsafe-inline'''
  style-src:
    - '''self'''
    - '''unsafe-inline'''
