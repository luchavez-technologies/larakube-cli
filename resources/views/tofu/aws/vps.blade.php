{{-- AWS VPS stack: EC2 Instance + Security Group + Key Pair.
     Rendered by cloud:create into ~/.larakube/tofu/<stack>/main.tf.
     Credentials are passed via environment variables (never written directly into HCL). --}}
terraform {
  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 5.0"
    }
  }
}

provider "aws" {
  region = "{{ $region }}"
}

# Look up default VPC for the target region
data "aws_vpc" "default" {
  default = true
}

# Look up subnets in default VPC
data "aws_subnets" "default" {
  filter {
    name   = "vpc-id"
    values = [data.aws_vpc.default.id]
  }
}

# Find latest official Ubuntu 24.04 LTS Noble AMI
data "aws_ami" "ubuntu" {
  most_recent = true
  owners      = ["099720109477"] # Canonical

  filter {
    name   = "name"
    values = ["ubuntu/images/hvm-ssd-gp3/ubuntu-noble-24.04-amd64-server-*"]
  }

  filter {
    name   = "virtualization-type"
    values = ["hvm"]
  }
}

# Register the operator's public key
resource "aws_key_pair" "larakube" {
  key_name   = "{{ $sshKeyName }}"
  public_key = "{{ $sshPubKey }}"
}

# Firewall / Security Group
resource "aws_security_group" "larakube" {
  name        = "{{ $dropletName }}-sg"
  description = "Security group for LaraKube VPS instance {{ $dropletName }}"
  vpc_id      = data.aws_vpc.default.id

  # SSH (Port 22) — restricted to admin CIDR when provided, else open
  ingress {
    description      = "SSH"
    from_port        = 22
    to_port          = 22
    protocol         = "tcp"
    cidr_blocks      = [{!! ! empty($adminCidr) ? '"' . $adminCidr . '"' : '"0.0.0.0/0"' !!}]
@if(empty($adminCidr))
    ipv6_cidr_blocks = ["::/0"]
@endif
  }

  # k3s API (Port 6443) — restricted to admin CIDR when provided, else open
  ingress {
    description      = "k3s API"
    from_port        = 6443
    to_port          = 6443
    protocol         = "tcp"
    cidr_blocks      = [{!! ! empty($adminCidr) ? '"' . $adminCidr . '"' : '"0.0.0.0/0"' !!}]
@if(empty($adminCidr))
    ipv6_cidr_blocks = ["::/0"]
@endif
  }

  # HTTP (Port 80) — open for Traefik ingress & ACME HTTP-01 challenges
  ingress {
    description      = "HTTP"
    from_port        = 80
    to_port          = 80
    protocol         = "tcp"
    cidr_blocks      = ["0.0.0.0/0"]
    ipv6_cidr_blocks = ["::/0"]
  }

  # HTTPS (Port 443) — open for Traefik ingress
  ingress {
    description      = "HTTPS"
    from_port        = 443
    to_port          = 443
    protocol         = "tcp"
    cidr_blocks      = ["0.0.0.0/0"]
    ipv6_cidr_blocks = ["::/0"]
  }

  # Outbound access
  egress {
    from_port        = 0
    to_port          = 0
    protocol         = "-1"
    cidr_blocks      = ["0.0.0.0/0"]
    ipv6_cidr_blocks = ["::/0"]
  }

  tags = {
    Name      = "{{ $dropletName }}-sg"
    ManagedBy = "LaraKube"
  }
}

# EC2 Instance
resource "aws_instance" "larakube" {
  ami                         = data.aws_ami.ubuntu.id
  instance_type               = "{{ $size }}"
  key_name                    = aws_key_pair.larakube.key_name
  subnet_id                   = data.aws_subnets.default.ids[0]
  vpc_security_group_ids      = [aws_security_group.larakube.id]
  associate_public_ip_address = true

  root_block_device {
    volume_size           = {{ $diskSize ?? 30 }}
    volume_type           = "gp3"
    delete_on_termination = true
  }

  # Cloud-init to ensure root login with the operator's public key
  user_data = <<-EOF
              #!/bin/bash
              mkdir -p /root/.ssh
              chmod 700 /root/.ssh
              echo '{{ $sshPubKey }}' > /root/.ssh/authorized_keys
              chmod 600 /root/.ssh/authorized_keys
              sed -i 's/^#\?PermitRootLogin.*/PermitRootLogin prohibit-password/' /etc/ssh/sshd_config
              if [ -d /etc/ssh/sshd_config.d ]; then
                sed -i 's/^#\?PermitRootLogin.*/PermitRootLogin prohibit-password/' /etc/ssh/sshd_config.d/*.conf 2>/dev/null || true
              fi
              systemctl reload ssh || systemctl restart ssh || systemctl restart sshd || true
              EOF

  tags = {
    Name      = "{{ $dropletName }}"
    ManagedBy = "LaraKube"
  }
}

output "ip" {
  value = aws_instance.larakube.public_ip
}

output "id" {
  value = aws_instance.larakube.id
}
