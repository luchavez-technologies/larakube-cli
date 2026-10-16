@if($mas)
matrix_authentication_service:
  enabled: true
  endpoint: "{{ $mas['endpoint'] }}"
  secret: "{{ $mas['secret'] }}"
@endif
