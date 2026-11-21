{{--
    First-boot commands that move sshd to $sshPort, after the template's own #!/bin/bash line. Ubuntu 22.10+ starts sshd by socket activation: the
    listening port is generated from sshd_config, so a change takes effect only after daemon-reload and
    a restart of ssh.socket (Ubuntu's own sshd_config says so; LP #2069041). Restarting ssh.service
    alone, or swapping the socket for the service, can leave sshd listening nowhere.
--}}
sed -i 's/^#\?Port .*/Port {{ $sshPort }}/' /etc/ssh/sshd_config
grep -qxF 'Port {{ $sshPort }}' /etc/ssh/sshd_config || echo 'Port {{ $sshPort }}' >> /etc/ssh/sshd_config
if [ -d /etc/ssh/sshd_config.d ]; then
  sed -i 's/^#\?Port .*/Port {{ $sshPort }}/' /etc/ssh/sshd_config.d/*.conf 2>/dev/null || true
fi
if systemctl is-enabled --quiet ssh.socket 2>/dev/null; then
  systemctl daemon-reload
  systemctl restart ssh.socket
else
  systemctl restart ssh || systemctl restart sshd || true
fi
