@if($smtp)
email:
  enable_notifs: true
  notif_from: "{{ $smtp['from'] }}"
  smtp_host: "{{ $smtp['host'] }}"
  smtp_port: {{ (int) $smtp['port'] }}
  smtp_user: "{{ $smtp['user'] }}"
  smtp_pass: "{{ $smtp['password'] }}"
@endif
