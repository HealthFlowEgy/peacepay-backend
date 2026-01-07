# PeacePay DevOps Deployment Guide

**Version:** 2.0.0  
**Last Updated:** January 2026  
**Target Region:** AWS me-south-1 (Bahrain) / me-central-1 (UAE)

---

## Table of Contents

1. [Architecture Overview](#1-architecture-overview)
2. [Prerequisites](#2-prerequisites)
3. [Infrastructure Setup](#3-infrastructure-setup)
4. [Database Setup](#4-database-setup)
5. [Redis Setup](#5-redis-setup)
6. [Kubernetes Cluster](#6-kubernetes-cluster)
7. [Application Deployment](#7-application-deployment)
8. [SSL/TLS Configuration](#8-ssltls-configuration)
9. [Monitoring Setup](#9-monitoring-setup)
10. [CI/CD Configuration](#10-cicd-configuration)
11. [Security Hardening](#11-security-hardening)
12. [Backup & Recovery](#12-backup--recovery)
13. [Scaling Guidelines](#13-scaling-guidelines)
14. [Troubleshooting](#14-troubleshooting)
15. [Runbooks](#15-runbooks)

---

## 1. Architecture Overview

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                              INTERNET                                        │
└─────────────────────────────────┬───────────────────────────────────────────┘
                                  │
                    ┌─────────────▼─────────────┐
                    │      CloudFront CDN       │
                    │    (Mobile Assets/API)    │
                    └─────────────┬─────────────┘
                                  │
                    ┌─────────────▼─────────────┐
                    │     AWS WAF / Shield      │
                    │   (DDoS/SQL Injection)    │
                    └─────────────┬─────────────┘
                                  │
                    ┌─────────────▼─────────────┐
                    │  Application Load Balancer │
                    │      (SSL Termination)     │
                    └─────────────┬─────────────┘
                                  │
         ┌────────────────────────┼────────────────────────┐
         │                        │                        │
┌────────▼────────┐    ┌─────────▼─────────┐    ┌────────▼────────┐
│   EKS Cluster   │    │   EKS Cluster     │    │   EKS Cluster   │
│   (API Pods)    │    │  (Queue Workers)  │    │   (Scheduler)   │
│   min:3 max:10  │    │    min:2 max:5    │    │     replica:1   │
└────────┬────────┘    └─────────┬─────────┘    └────────┬────────┘
         │                       │                       │
         └───────────────────────┼───────────────────────┘
                                 │
         ┌───────────────────────┼───────────────────────┐
         │                       │                       │
┌────────▼────────┐    ┌────────▼────────┐    ┌────────▼────────┐
│   RDS MySQL     │    │  ElastiCache    │    │    S3 Bucket    │
│  (Multi-AZ)     │    │    (Redis)      │    │   (Backups)     │
│  db.r6g.large   │    │  cache.r6g.lg   │    │                 │
└─────────────────┘    └─────────────────┘    └─────────────────┘
```

### Component Summary

| Component | Service | Specs | Purpose |
|-----------|---------|-------|---------|
| API | EKS | 3-10 pods, 1 vCPU, 1GB | REST API |
| Queue | EKS | 2-5 pods, 0.5 vCPU, 512MB | Async jobs |
| Scheduler | EKS | 1 pod | Cron jobs |
| Database | RDS MySQL 8.0 | db.r6g.large, Multi-AZ | Primary data |
| Cache | ElastiCache Redis 7 | cache.r6g.large | Sessions, cache, queues |
| CDN | CloudFront | Global | Static assets, API cache |
| WAF | AWS WAF | Managed rules | Security |
| Secrets | AWS Secrets Manager | - | Credentials |
| Storage | S3 | Standard | Backups, uploads |
| DNS | Route53 | - | DNS management |
| Certificates | ACM | - | SSL/TLS |

---

## 2. Prerequisites

### 2.1 Required Tools

```bash
# Install on deployment machine
brew install awscli kubectl helm terraform jq yq

# Verify versions
aws --version          # >= 2.13
kubectl version        # >= 1.28
helm version           # >= 3.13
terraform --version    # >= 1.6
```

### 2.2 AWS Account Setup

```bash
# Configure AWS CLI
aws configure --profile peacepay-prod
# Region: me-south-1
# Output: json

# Verify access
aws sts get-caller-identity --profile peacepay-prod
```

### 2.3 Required IAM Permissions

Create IAM user/role with these policies:
- `AmazonEKSClusterPolicy`
- `AmazonEKSWorkerNodePolicy`
- `AmazonEC2ContainerRegistryFullAccess`
- `AmazonRDSFullAccess`
- `ElastiCacheFullAccess`
- `AmazonS3FullAccess`
- `SecretsManagerReadWrite`
- `CloudWatchFullAccess`
- `AWSWAFFullAccess`

### 2.4 Domain & SSL

- Domain: `peacepay.eg` (registered)
- Subdomains needed:
  - `api.peacepay.eg` - Backend API
  - `admin.peacepay.eg` - Admin portal
  - `cdn.peacepay.eg` - Static assets

---

## 3. Infrastructure Setup

### 3.1 Initialize Terraform

```bash
cd infrastructure/terraform

# Initialize
terraform init

# Create workspace for environment
terraform workspace new production
terraform workspace select production

# Plan
terraform plan -var-file=environments/production.tfvars -out=plan.out

# Apply
terraform apply plan.out
```

### 3.2 Terraform Variables (production.tfvars)

```hcl
# environments/production.tfvars

environment     = "production"
aws_region      = "me-south-1"
project_name    = "peacepay"

# VPC
vpc_cidr        = "10.0.0.0/16"
azs             = ["me-south-1a", "me-south-1b", "me-south-1c"]
private_subnets = ["10.0.1.0/24", "10.0.2.0/24", "10.0.3.0/24"]
public_subnets  = ["10.0.101.0/24", "10.0.102.0/24", "10.0.103.0/24"]

# EKS
eks_cluster_version = "1.28"
eks_node_groups = {
  general = {
    instance_types = ["t3.large"]
    min_size       = 3
    max_size       = 10
    desired_size   = 3
  }
}

# RDS
rds_instance_class    = "db.r6g.large"
rds_allocated_storage = 100
rds_multi_az          = true
rds_backup_retention  = 30

# ElastiCache
elasticache_node_type       = "cache.r6g.large"
elasticache_num_cache_nodes = 2

# Domain
domain_name = "peacepay.eg"
```

### 3.3 Verify Infrastructure

```bash
# Get EKS cluster info
aws eks describe-cluster --name peacepay-production --region me-south-1

# Update kubeconfig
aws eks update-kubeconfig --name peacepay-production --region me-south-1

# Verify nodes
kubectl get nodes
```

---

## 4. Database Setup

### 4.1 Connect to RDS

```bash
# Get RDS endpoint from Terraform output
RDS_ENDPOINT=$(terraform output -raw rds_endpoint)

# Connect via bastion or kubectl port-forward
kubectl run mysql-client --rm -it --image=mysql:8.0 -- \
  mysql -h $RDS_ENDPOINT -u admin -p
```

### 4.2 Create Database & User

```sql
-- Create database
CREATE DATABASE peacepay CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Create application user
CREATE USER 'peacepay_app'@'%' IDENTIFIED BY '<STRONG_PASSWORD>';
GRANT SELECT, INSERT, UPDATE, DELETE ON peacepay.* TO 'peacepay_app'@'%';

-- Create migrations user (temporary)
CREATE USER 'peacepay_migrate'@'%' IDENTIFIED BY '<STRONG_PASSWORD>';
GRANT ALL PRIVILEGES ON peacepay.* TO 'peacepay_migrate'@'%';

FLUSH PRIVILEGES;
```

### 4.3 Run Migrations

```bash
# From CI/CD or manually
kubectl exec -n peacepay deployment/peacepay-api -- \
  php artisan migrate --force

# Seed initial data
kubectl exec -n peacepay deployment/peacepay-api -- \
  php artisan db:seed --class=ProductionSeeder
```

### 4.4 Initial Data (Seeders)

```php
// Fee configurations
INSERT INTO fee_configurations (fee_type, rate, fixed_amount, effective_from) VALUES
('merchant_percentage', 0.005, NULL, NOW()),
('merchant_fixed', NULL, 2.00, NOW()),
('dsp_percentage', 0.005, NULL, NOW()),
('advance_percentage', 0.005, NULL, NOW()),
('cashout_percentage', 0.015, NULL, NOW());

// KYC Limits
INSERT INTO kyc_limits (kyc_level, daily_limit, monthly_limit, single_transaction_limit) VALUES
(1, 5000, 20000, 2000),
(2, 50000, 200000, 20000),
(3, 500000, 2000000, 100000);
```

---

## 5. Redis Setup

### 5.1 Verify ElastiCache

```bash
# Get Redis endpoint
REDIS_ENDPOINT=$(terraform output -raw elasticache_endpoint)

# Test connection
kubectl run redis-test --rm -it --image=redis:7 -- \
  redis-cli -h $REDIS_ENDPOINT ping
```

### 5.2 Redis Configuration

Laravel will use these prefixes:
- `peacepay_cache:` - Application cache
- `peacepay_session:` - User sessions
- `peacepay_queue:` - Job queues
- `peacepay_horizon:` - Horizon metrics

---

## 6. Kubernetes Cluster

### 6.1 Install Required Components

```bash
# Add Helm repos
helm repo add ingress-nginx https://kubernetes.github.io/ingress-nginx
helm repo add jetstack https://charts.jetstack.io
helm repo add prometheus-community https://prometheus-community.github.io/helm-charts
helm repo update

# Install NGINX Ingress
helm install ingress-nginx ingress-nginx/ingress-nginx \
  --namespace ingress-nginx --create-namespace \
  --set controller.service.annotations."service\.beta\.kubernetes\.io/aws-load-balancer-type"="nlb" \
  --set controller.service.annotations."service\.beta\.kubernetes\.io/aws-load-balancer-scheme"="internet-facing"

# Install cert-manager
helm install cert-manager jetstack/cert-manager \
  --namespace cert-manager --create-namespace \
  --set installCRDs=true

# Install metrics-server
kubectl apply -f https://github.com/kubernetes-sigs/metrics-server/releases/latest/download/components.yaml
```

### 6.2 Create Namespace & Secrets

```bash
# Create namespace
kubectl create namespace peacepay

# Create secrets from AWS Secrets Manager
aws secretsmanager get-secret-value --secret-id peacepay/production | \
  jq -r '.SecretString' | \
  kubectl create secret generic peacepay-secrets \
    --from-file=/dev/stdin \
    --namespace peacepay

# Or manually create secrets
kubectl create secret generic peacepay-secrets \
  --namespace peacepay \
  --from-literal=APP_KEY="base64:xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx" \
  --from-literal=DB_PASSWORD="xxxxxxxxxx" \
  --from-literal=REDIS_PASSWORD="xxxxxxxxxx" \
  --from-literal=JWT_SECRET="xxxxxxxxxx"
```

### 6.3 Create ConfigMap

```bash
kubectl apply -f - <<EOF
apiVersion: v1
kind: ConfigMap
metadata:
  name: peacepay-config
  namespace: peacepay
data:
  APP_ENV: "production"
  APP_DEBUG: "false"
  APP_URL: "https://api.peacepay.eg"
  LOG_CHANNEL: "stack"
  LOG_LEVEL: "warning"
  DB_CONNECTION: "mysql"
  DB_HOST: "${RDS_ENDPOINT}"
  DB_PORT: "3306"
  DB_DATABASE: "peacepay"
  REDIS_HOST: "${REDIS_ENDPOINT}"
  REDIS_PORT: "6379"
  CACHE_DRIVER: "redis"
  SESSION_DRIVER: "redis"
  QUEUE_CONNECTION: "redis"
EOF
```

---

## 7. Application Deployment

### 7.1 Deploy Application

```bash
# Apply all manifests
kubectl apply -f kubernetes/base/

# Or use Kustomize
kubectl apply -k kubernetes/overlays/production/

# Verify deployment
kubectl get pods -n peacepay
kubectl get services -n peacepay
kubectl get ingress -n peacepay
```

### 7.2 Verify Deployment

```bash
# Check pod status
kubectl get pods -n peacepay -w

# Check logs
kubectl logs -n peacepay deployment/peacepay-api -f

# Check API health
kubectl exec -n peacepay deployment/peacepay-api -- curl -s localhost/health

# Port-forward for testing
kubectl port-forward -n peacepay svc/peacepay-api 8080:80
curl http://localhost:8080/health
```

### 7.3 Run Post-Deployment Tasks

```bash
# Clear caches
kubectl exec -n peacepay deployment/peacepay-api -- php artisan config:cache
kubectl exec -n peacepay deployment/peacepay-api -- php artisan route:cache
kubectl exec -n peacepay deployment/peacepay-api -- php artisan view:cache

# Verify queues are processing
kubectl exec -n peacepay deployment/peacepay-queue -- php artisan queue:monitor default,notifications,payments
```

---

## 8. SSL/TLS Configuration

### 8.1 Create ClusterIssuer

```bash
kubectl apply -f - <<EOF
apiVersion: cert-manager.io/v1
kind: ClusterIssuer
metadata:
  name: letsencrypt-prod
spec:
  acme:
    server: https://acme-v02.api.letsencrypt.org/directory
    email: devops@healthflow.eg
    privateKeySecretRef:
      name: letsencrypt-prod
    solvers:
    - http01:
        ingress:
          class: nginx
EOF
```

### 8.2 Verify Certificate

```bash
# Check certificate status
kubectl get certificate -n peacepay

# Describe certificate
kubectl describe certificate peacepay-api-tls -n peacepay

# Check certificate details
kubectl get secret peacepay-api-tls -n peacepay -o jsonpath='{.data.tls\.crt}' | base64 -d | openssl x509 -text -noout
```

---

## 9. Monitoring Setup

### 9.1 Install Prometheus Stack

```bash
helm install prometheus prometheus-community/kube-prometheus-stack \
  --namespace monitoring --create-namespace \
  --values monitoring/prometheus-values.yaml
```

### 9.2 Prometheus Values

```yaml
# monitoring/prometheus-values.yaml
prometheus:
  prometheusSpec:
    retention: 30d
    storageSpec:
      volumeClaimTemplate:
        spec:
          accessModes: ["ReadWriteOnce"]
          resources:
            requests:
              storage: 50Gi

grafana:
  adminPassword: "<CHANGE_ME>"
  persistence:
    enabled: true
    size: 10Gi
  
alertmanager:
  config:
    receivers:
      - name: 'slack-notifications'
        slack_configs:
          - channel: '#peacepay-alerts'
            api_url: '<SLACK_WEBHOOK_URL>'
      - name: 'pagerduty'
        pagerduty_configs:
          - service_key: '<PAGERDUTY_KEY>'
```

### 9.3 Import Grafana Dashboards

```bash
# Laravel dashboard
kubectl apply -f monitoring/dashboards/laravel-dashboard.yaml

# PeacePay custom dashboard
kubectl apply -f monitoring/dashboards/peacepay-dashboard.yaml
```

### 9.4 Critical Alerts

| Alert | Condition | Severity |
|-------|-----------|----------|
| HighErrorRate | error_rate > 1% for 5m | Critical |
| HighLatency | p99_latency > 2s for 5m | Warning |
| PodCrashLooping | restart_count > 3 in 10m | Critical |
| DBConnectionsFull | connections > 90% | Critical |
| QueueBacklog | queue_size > 1000 for 10m | Warning |
| DiskSpaceLow | disk_usage > 85% | Warning |
| MemoryPressure | memory_usage > 90% | Critical |
| OTPFailureSpike | otp_failures > 10/min | Critical |
| PaymentFailures | payment_failures > 5/min | Critical |

---

## 10. CI/CD Configuration

### 10.1 GitHub Secrets Required

| Secret | Description |
|--------|-------------|
| `AWS_ACCESS_KEY_ID` | AWS credentials |
| `AWS_SECRET_ACCESS_KEY` | AWS credentials |
| `KUBE_CONFIG` | Base64 encoded kubeconfig |
| `FIREBASE_SERVICE_ACCOUNT` | Firebase for mobile distribution |
| `SLACK_WEBHOOK_URL` | Deployment notifications |
| `SENTRY_AUTH_TOKEN` | Error tracking |
| `ANDROID_KEYSTORE_BASE64` | Android signing key |
| `IOS_P12_CERTIFICATE_BASE64` | iOS signing certificate |
| `APP_STORE_CONNECT_API_KEY` | iOS deployment |
| `GOOGLE_PLAY_JSON_KEY` | Android deployment |

### 10.2 Deployment Flow

```
┌─────────────┐    ┌─────────────┐    ┌─────────────┐    ┌─────────────┐
│   Develop   │───▶│   Staging   │───▶│     UAT     │───▶│ Production  │
│   Branch    │    │Environment  │    │Environment  │    │Environment  │
└─────────────┘    └─────────────┘    └─────────────┘    └─────────────┘
      │                  │                  │                  │
      │ Auto deploy      │ Auto deploy      │ Manual approve   │ Manual approve
      │ on push          │ on PR merge      │ + smoke tests    │ + blue-green
```

### 10.3 Rollback Procedure

```bash
# Quick rollback to previous version
kubectl rollout undo deployment/peacepay-api -n peacepay

# Rollback to specific revision
kubectl rollout undo deployment/peacepay-api -n peacepay --to-revision=5

# Check rollout history
kubectl rollout history deployment/peacepay-api -n peacepay
```

---

## 11. Security Hardening

### 11.1 Network Policies

```bash
kubectl apply -f - <<EOF
apiVersion: networking.k8s.io/v1
kind: NetworkPolicy
metadata:
  name: peacepay-api-policy
  namespace: peacepay
spec:
  podSelector:
    matchLabels:
      app: peacepay
      component: api
  policyTypes:
    - Ingress
    - Egress
  ingress:
    - from:
        - namespaceSelector:
            matchLabels:
              name: ingress-nginx
      ports:
        - protocol: TCP
          port: 80
  egress:
    - to:
        - ipBlock:
            cidr: 10.0.0.0/16  # VPC CIDR for RDS/Redis
      ports:
        - protocol: TCP
          port: 3306
        - protocol: TCP
          port: 6379
    - to:
        - ipBlock:
            cidr: 0.0.0.0/0
      ports:
        - protocol: TCP
          port: 443  # External APIs (SMS, payment)
EOF
```

### 11.2 WAF Rules

```bash
# Apply AWS WAF rules via Terraform
# Rules included:
# - SQL injection protection
# - XSS protection
# - Rate limiting (1000 req/IP/5min)
# - Geo blocking (allow: EG, AE, SA, KW, BH, QA, OM)
# - Bad bot blocking
```

### 11.3 Security Checklist

- [ ] All secrets in AWS Secrets Manager
- [ ] Database encryption at rest (AES-256)
- [ ] TLS 1.3 enforced
- [ ] WAF enabled with managed rules
- [ ] Network policies applied
- [ ] Pod security standards enforced
- [ ] RBAC properly configured
- [ ] Audit logging enabled
- [ ] Vulnerability scanning in CI/CD
- [ ] Penetration test completed

---

## 12. Backup & Recovery

### 12.1 Database Backups

```bash
# Automated backups (RDS)
# - Retention: 30 days
# - Window: 03:00-04:00 UTC (05:00-06:00 Egypt)
# - Multi-AZ: Automatic failover

# Manual snapshot
aws rds create-db-snapshot \
  --db-instance-identifier peacepay-production \
  --db-snapshot-identifier peacepay-manual-$(date +%Y%m%d)
```

### 12.2 Restore Procedure

```bash
# Restore from snapshot
aws rds restore-db-instance-from-db-snapshot \
  --db-instance-identifier peacepay-restored \
  --db-snapshot-identifier peacepay-manual-20260107 \
  --db-instance-class db.r6g.large

# Point-in-time recovery
aws rds restore-db-instance-to-point-in-time \
  --source-db-instance-identifier peacepay-production \
  --target-db-instance-identifier peacepay-pit-restore \
  --restore-time 2026-01-07T10:00:00Z
```

### 12.3 Redis Backup

```bash
# ElastiCache automatic backups enabled
# Retention: 7 days
# Window: 04:00-05:00 UTC
```

---

## 13. Scaling Guidelines

### 13.1 Horizontal Pod Autoscaler

```yaml
# Already configured in manifests
# API: 3-10 pods based on CPU (70%) / Memory (80%)
# Queue: 2-5 pods based on queue length
```

### 13.2 Database Scaling

| Metric | Current | Scale Up Trigger | Action |
|--------|---------|------------------|--------|
| Connections | 150 | >200 | Add read replica |
| CPU | 40% | >70% | Upgrade instance |
| Storage | 100GB | >80% | Enable autoscaling |
| IOPS | 3000 | >80% | Provision IOPS |

### 13.3 Expected Traffic

| Scenario | Requests/sec | Pods | RDS | Redis |
|----------|--------------|------|-----|-------|
| Normal | 100 | 3 | r6g.large | r6g.large |
| Peak (Ramadan) | 500 | 6 | r6g.xlarge | r6g.xlarge |
| Flash Sale | 1000 | 10 | r6g.2xlarge | r6g.xlarge |

---

## 14. Troubleshooting

### 14.1 Common Issues

#### Pods not starting
```bash
kubectl describe pod <pod-name> -n peacepay
kubectl logs <pod-name> -n peacepay --previous
```

#### Database connection issues
```bash
# Check connectivity
kubectl exec -n peacepay deployment/peacepay-api -- \
  nc -zv $RDS_ENDPOINT 3306

# Check credentials
kubectl get secret peacepay-secrets -n peacepay -o yaml
```

#### High latency
```bash
# Check pod resources
kubectl top pods -n peacepay

# Check database slow queries
kubectl exec -n peacepay deployment/peacepay-api -- \
  php artisan db:show --counts
```

#### Queue backlog
```bash
# Check queue status
kubectl exec -n peacepay deployment/peacepay-api -- \
  php artisan queue:monitor

# Clear failed jobs
kubectl exec -n peacepay deployment/peacepay-api -- \
  php artisan queue:flush
```

### 14.2 Emergency Contacts

| Role | Name | Phone | Email |
|------|------|-------|-------|
| DevOps Lead | TBD | +20-xxx-xxx | devops@healthflow.eg |
| Backend Lead | TBD | +20-xxx-xxx | backend@healthflow.eg |
| On-Call | Rotation | PagerDuty | - |

---

## 15. Runbooks

### 15.1 Deploy New Version

```bash
# 1. Verify CI passed
# 2. Check staging environment
# 3. Create deployment PR
# 4. Approve and merge
# 5. Monitor rollout
kubectl rollout status deployment/peacepay-api -n peacepay

# 6. Verify health
curl https://api.peacepay.eg/health

# 7. Check error rates in Grafana
```

### 15.2 Database Migration

```bash
# 1. Announce maintenance window
# 2. Scale down queue workers
kubectl scale deployment/peacepay-queue -n peacepay --replicas=0

# 3. Run migrations
kubectl exec -n peacepay deployment/peacepay-api -- \
  php artisan migrate --force

# 4. Verify migrations
kubectl exec -n peacepay deployment/peacepay-api -- \
  php artisan migrate:status

# 5. Scale up queue workers
kubectl scale deployment/peacepay-queue -n peacepay --replicas=2

# 6. Clear caches
kubectl exec -n peacepay deployment/peacepay-api -- \
  php artisan cache:clear
```

### 15.3 Incident Response

```
1. DETECT
   - Alert triggered
   - Check Grafana dashboard
   - Identify affected component

2. TRIAGE
   - Severity: Critical/High/Medium/Low
   - Impact: Users affected, revenue impact
   - Notify stakeholders

3. MITIGATE
   - Rollback if deployment-related
   - Scale if capacity-related
   - Failover if infrastructure-related

4. RESOLVE
   - Root cause analysis
   - Permanent fix
   - Post-mortem document

5. REVIEW
   - Update runbooks
   - Add monitoring
   - Prevent recurrence
```

---

## Appendix A: Environment Variables

```bash
# Required environment variables
APP_NAME=PeacePay
APP_ENV=production
APP_KEY=base64:xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
APP_DEBUG=false
APP_URL=https://api.peacepay.eg

DB_CONNECTION=mysql
DB_HOST=peacepay-prod.xxxxxxxxx.me-south-1.rds.amazonaws.com
DB_PORT=3306
DB_DATABASE=peacepay
DB_USERNAME=peacepay_app
DB_PASSWORD=xxxxxxxxxx

REDIS_HOST=peacepay-prod.xxxxxx.0001.mes1.cache.amazonaws.com
REDIS_PASSWORD=xxxxxxxxxx
REDIS_PORT=6379

CACHE_DRIVER=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis

# SMS (Twilio)
TWILIO_SID=ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
TWILIO_TOKEN=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
TWILIO_FROM=+20xxxxxxxxx

# Payment (Hyperswitch)
HYPERSWITCH_API_KEY=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
HYPERSWITCH_MERCHANT_ID=xxxxxxxxxxxxxxxxxxxxxxxxxx

# Monitoring
SENTRY_DSN=https://xxxxxxxxxx@sentry.io/xxxxxxxxxx
LOG_CHANNEL=stack
LOG_LEVEL=warning
```

---

## Appendix B: Useful Commands

```bash
# Get all resources
kubectl get all -n peacepay

# Watch pods
kubectl get pods -n peacepay -w

# Exec into pod
kubectl exec -it -n peacepay deployment/peacepay-api -- bash

# View logs
kubectl logs -f -n peacepay deployment/peacepay-api

# Port forward
kubectl port-forward -n peacepay svc/peacepay-api 8080:80

# Scale deployment
kubectl scale deployment/peacepay-api -n peacepay --replicas=5

# Restart deployment
kubectl rollout restart deployment/peacepay-api -n peacepay

# Check resource usage
kubectl top pods -n peacepay
kubectl top nodes
```

---

**Document maintained by:** DevOps Team  
**Review cycle:** Monthly  
**Last reviewed:** January 2026
