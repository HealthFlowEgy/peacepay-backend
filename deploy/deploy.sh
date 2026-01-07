#!/bin/bash
# =============================================================================
# PeacePay Backend Deployment Script
# =============================================================================
# This script deploys the PeacePay backend to a DigitalOcean droplet
# Usage: ./deploy.sh [environment]
# =============================================================================

set -e

# Configuration
APP_NAME="peacepay"
APP_DIR="/opt/peacepay"
REPO_URL="https://github.com/HealthFlowEgy/peacepay-backend.git"
BRANCH="${1:-main}"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

log_info() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

log_warn() {
    echo -e "${YELLOW}[WARN]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Check if running as root
if [ "$EUID" -ne 0 ]; then
    log_error "Please run as root"
    exit 1
fi

log_info "Starting PeacePay deployment..."

# =============================================================================
# Step 1: Install Docker if not present
# =============================================================================
if ! command -v docker &> /dev/null; then
    log_info "Installing Docker..."
    curl -fsSL https://get.docker.com | sh
    systemctl enable docker
    systemctl start docker
fi

# Install Docker Compose if not present
if ! command -v docker-compose &> /dev/null; then
    log_info "Installing Docker Compose..."
    curl -L "https://github.com/docker/compose/releases/latest/download/docker-compose-$(uname -s)-$(uname -m)" -o /usr/local/bin/docker-compose
    chmod +x /usr/local/bin/docker-compose
fi

# =============================================================================
# Step 2: Clone or update repository
# =============================================================================
if [ -d "$APP_DIR" ]; then
    log_info "Updating existing repository..."
    cd "$APP_DIR"
    git fetch origin
    git reset --hard origin/$BRANCH
else
    log_info "Cloning repository..."
    git clone -b $BRANCH $REPO_URL $APP_DIR
    cd "$APP_DIR"
fi

# =============================================================================
# Step 3: Create .env file if not exists
# =============================================================================
if [ ! -f "$APP_DIR/.env" ]; then
    log_info "Creating .env file..."
    cp "$APP_DIR/.env.example" "$APP_DIR/.env"
    
    # Generate APP_KEY
    APP_KEY=$(openssl rand -base64 32)
    sed -i "s|APP_KEY=|APP_KEY=base64:$APP_KEY|" "$APP_DIR/.env"
    
    # Generate secure database password
    DB_PASSWORD=$(openssl rand -base64 24 | tr -dc 'a-zA-Z0-9' | head -c 24)
    sed -i "s|DB_PASSWORD=|DB_PASSWORD=$DB_PASSWORD|" "$APP_DIR/.env"
    
    log_warn "Please update .env file with your configuration!"
    log_warn "File location: $APP_DIR/.env"
fi

# =============================================================================
# Step 4: Build and start containers
# =============================================================================
log_info "Building Docker images..."
docker-compose -f docker-compose.production.yml build --no-cache

log_info "Starting containers..."
docker-compose -f docker-compose.production.yml up -d

# Wait for MySQL to be ready
log_info "Waiting for MySQL to be ready..."
sleep 30

# =============================================================================
# Step 5: Run migrations and optimizations
# =============================================================================
log_info "Running database migrations..."
docker-compose -f docker-compose.production.yml exec -T app php artisan migrate --force

log_info "Running optimizations..."
docker-compose -f docker-compose.production.yml exec -T app php artisan config:cache
docker-compose -f docker-compose.production.yml exec -T app php artisan route:cache
docker-compose -f docker-compose.production.yml exec -T app php artisan view:cache

# =============================================================================
# Step 6: Set up firewall
# =============================================================================
log_info "Configuring firewall..."
ufw allow 22/tcp
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable

# =============================================================================
# Step 7: Display status
# =============================================================================
log_info "Deployment completed!"
echo ""
echo "=============================================="
echo "PeacePay Backend Deployment Summary"
echo "=============================================="
echo "Application URL: http://$(curl -s ifconfig.me)"
echo "Application Dir: $APP_DIR"
echo ""
echo "Container Status:"
docker-compose -f docker-compose.production.yml ps
echo ""
echo "=============================================="
