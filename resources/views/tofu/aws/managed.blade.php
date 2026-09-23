{{-- AWS Elastic Kubernetes Service (EKS) managed cluster.
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

# Look up default VPC and subnets
data "aws_vpc" "default" {
  default = true
}

data "aws_subnets" "default" {
  filter {
    name   = "vpc-id"
    values = [data.aws_vpc.default.id]
  }
}

data "aws_caller_identity" "current" {}

# EKS Cluster IAM Role
resource "aws_iam_role" "cluster" {
  name = "{{ $clusterName }}-cluster-role"

  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Action = "sts:AssumeRole"
      Effect = "Allow"
      Principal = {
        Service = "eks.amazonaws.com"
      }
    }]
  })
}

resource "aws_iam_role_policy_attachment" "cluster_policy" {
  policy_arn = "arn:aws:iam::aws:policy/AmazonEKSClusterPolicy"
  role       = aws_iam_role.cluster.name
}

# Worker Node Group IAM Role
resource "aws_iam_role" "nodes" {
  name = "{{ $clusterName }}-node-role"

  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Action = "sts:AssumeRole"
      Effect = "Allow"
      Principal = {
        Service = "ec2.amazonaws.com"
      }
    }]
  })
}

resource "aws_iam_role_policy_attachment" "node_worker_policy" {
  policy_arn = "arn:aws:iam::aws:policy/AmazonEKSWorkerNodePolicy"
  role       = aws_iam_role.nodes.name
}

resource "aws_iam_role_policy_attachment" "node_cni_policy" {
  policy_arn = "arn:aws:iam::aws:policy/AmazonEKS_CNI_Policy"
  role       = aws_iam_role.nodes.name
}

resource "aws_iam_role_policy_attachment" "node_registry_policy" {
  policy_arn = "arn:aws:iam::aws:policy/AmazonEC2ContainerRegistryReadOnly"
  role       = aws_iam_role.nodes.name
}

# EKS Cluster
resource "aws_eks_cluster" "larakube" {
  name     = "{{ $clusterName }}"
  role_arn = aws_iam_role.cluster.arn
@if(! empty($versionPrefix))
  version  = "{{ rtrim($versionPrefix, '.') }}"
@endif

  vpc_config {
    subnet_ids = data.aws_subnets.default.ids
  }

  depends_on = [
    aws_iam_role_policy_attachment.cluster_policy,
  ]
}

# EKS Managed Node Group
resource "aws_eks_node_group" "larakube" {
  cluster_name    = aws_eks_cluster.larakube.name
  node_group_name = "{{ $clusterName }}-nodes"
  node_role_arn   = aws_iam_role.nodes.arn
  subnet_ids      = data.aws_subnets.default.ids
  instance_types  = ["{{ $size }}"]

  scaling_config {
    desired_size = {{ (int) ($nodeCount ?? 2) }}
    min_size     = 1
    max_size     = {{ max((int) ($nodeCount ?? 2) * 2, 4) }}
  }

  depends_on = [
    aws_iam_role_policy_attachment.node_worker_policy,
    aws_iam_role_policy_attachment.node_cni_policy,
    aws_iam_role_policy_attachment.node_registry_policy,
  ]
}

output "context" {
  value = "arn:aws:eks:{{ $region }}:${data.aws_caller_identity.current.account_id}:cluster/{{ $clusterName }}"
}

output "cluster_name" {
  value = aws_eks_cluster.larakube.name
}

output "endpoint" {
  value = aws_eks_cluster.larakube.endpoint
}

output "kubeconfig" {
  value = <<-EOT
apiVersion: v1
clusters:
- cluster:
    certificate-authority-data: ${aws_eks_cluster.larakube.certificate_authority[0].data}
    server: ${aws_eks_cluster.larakube.endpoint}
  name: arn:aws:eks:{{ $region }}:${data.aws_caller_identity.current.account_id}:cluster/{{ $clusterName }}
contexts:
- context:
    cluster: arn:aws:eks:{{ $region }}:${data.aws_caller_identity.current.account_id}:cluster/{{ $clusterName }}
    user: arn:aws:eks:{{ $region }}:${data.aws_caller_identity.current.account_id}:cluster/{{ $clusterName }}
  name: arn:aws:eks:{{ $region }}:${data.aws_caller_identity.current.account_id}:cluster/{{ $clusterName }}
current-context: arn:aws:eks:{{ $region }}:${data.aws_caller_identity.current.account_id}:cluster/{{ $clusterName }}
kind: Config
preferences: {}
users:
- name: arn:aws:eks:{{ $region }}:${data.aws_caller_identity.current.account_id}:cluster/{{ $clusterName }}
  user:
    exec:
      apiVersion: client.authentication.k8s.io/v1beta1
      command: aws
      args:
        - eks
        - get-token
        - --cluster-name
        - "{{ $clusterName }}"
        - --region
        - "{{ $region }}"
EOT
  sensitive = true
}
