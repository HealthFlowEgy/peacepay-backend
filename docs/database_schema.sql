-- ============================================================================
-- PEACEPAY / PEACELINK DATABASE SCHEMA
-- Secure Payment Hold (SPH) Escrow Platform
-- Version 2.0 | January 2026
-- ============================================================================

-- Enable UUID extension
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "pgcrypto";

-- ============================================================================
-- ENUMS
-- ============================================================================

CREATE TYPE user_role AS ENUM ('buyer', 'merchant', 'dsp_company', 'dsp_driver', 'admin', 'super_admin');
CREATE TYPE kyc_level AS ENUM ('none', 'level_1', 'level_2', 'level_3');
CREATE TYPE kyc_status AS ENUM ('pending', 'approved', 'rejected', 'expired');
CREATE TYPE wallet_type AS ENUM ('personal', 'business', 'master', 'sub_wallet', 'platform');
CREATE TYPE transaction_type AS ENUM ('credit', 'debit', 'hold', 'release', 'refund', 'fee', 'cashout', 'topup');
CREATE TYPE peacelink_status AS ENUM (
    'created', 'pending_approval', 'sph_active', 'dsp_assigned', 
    'otp_generated', 'delivered', 'canceled', 'disputed', 'resolved', 'expired'
);
CREATE TYPE cancellation_party AS ENUM ('buyer', 'merchant', 'dsp', 'admin', 'system');
CREATE TYPE dispute_status AS ENUM ('open', 'under_review', 'resolved_buyer', 'resolved_merchant', 'resolved_split');
CREATE TYPE cashout_status AS ENUM ('pending', 'approved', 'rejected', 'processing', 'completed', 'failed');
CREATE TYPE notification_type AS ENUM ('sms', 'push', 'email', 'in_app');
CREATE TYPE fee_type AS ENUM ('merchant_percentage', 'merchant_fixed', 'dsp_percentage', 'cashout_percentage', 'advance_percentage');

-- ============================================================================
-- USERS & AUTHENTICATION
-- ============================================================================

CREATE TABLE users (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    phone VARCHAR(20) UNIQUE NOT NULL,
    phone_verified BOOLEAN DEFAULT FALSE,
    email VARCHAR(255),
    email_verified BOOLEAN DEFAULT FALSE,
    pin_hash VARCHAR(255),
    role user_role NOT NULL,
    kyc_level kyc_level DEFAULT 'none',
    is_active BOOLEAN DEFAULT TRUE,
    is_locked BOOLEAN DEFAULT FALSE,
    lock_reason TEXT,
    locked_at TIMESTAMPTZ,
    locked_by UUID REFERENCES users(id),
    failed_login_attempts INT DEFAULT 0,
    last_failed_login TIMESTAMPTZ,
    last_login TIMESTAMPTZ,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE TABLE user_profiles (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id UUID UNIQUE NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    first_name_ar VARCHAR(100),
    last_name_ar VARCHAR(100),
    national_id VARCHAR(14), -- Egyptian 14-digit NID
    national_id_verified BOOLEAN DEFAULT FALSE,
    date_of_birth DATE,
    gender VARCHAR(10),
    address_line_1 VARCHAR(255),
    address_line_2 VARCHAR(255),
    city VARCHAR(100),
    governorate VARCHAR(100),
    postal_code VARCHAR(10),
    profile_image_url TEXT,
    preferred_language VARCHAR(5) DEFAULT 'ar',
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE TABLE merchant_profiles (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id UUID UNIQUE NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    business_name VARCHAR(255) NOT NULL,
    business_name_ar VARCHAR(255),
    business_type VARCHAR(100),
    tax_registration_number VARCHAR(50),
    commercial_registration VARCHAR(50),
    business_address TEXT,
    business_phone VARCHAR(20),
    business_email VARCHAR(255),
    logo_url TEXT,
    website_url TEXT,
    default_advance_percentage DECIMAL(5,2) DEFAULT 0, -- 0-100%
    default_delivery_policy_id UUID,
    is_enterprise BOOLEAN DEFAULT FALSE,
    api_key_hash VARCHAR(255),
    webhook_url TEXT,
    webhook_secret_hash VARCHAR(255),
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE TABLE dsp_profiles (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id UUID UNIQUE NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    company_name VARCHAR(255),
    company_name_ar VARCHAR(255),
    is_company BOOLEAN DEFAULT FALSE, -- true = DSP company, false = individual driver
    parent_dsp_id UUID REFERENCES dsp_profiles(id), -- For drivers under a company
    commercial_registration VARCHAR(50),
    fleet_size INT,
    service_areas TEXT[], -- Array of governorates/areas
    is_enterprise BOOLEAN DEFAULT FALSE,
    api_enabled BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

-- ============================================================================
-- KYC & VERIFICATION
-- ============================================================================

CREATE TABLE kyc_documents (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    document_type VARCHAR(50) NOT NULL, -- 'national_id_front', 'national_id_back', 'selfie', 'commercial_reg'
    document_url TEXT NOT NULL,
    extracted_data JSONB, -- OCR extracted data
    status kyc_status DEFAULT 'pending',
    reviewed_by UUID REFERENCES users(id),
    reviewed_at TIMESTAMPTZ,
    rejection_reason TEXT,
    expires_at DATE,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE TABLE kyc_verifications (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    level kyc_level NOT NULL,
    status kyc_status DEFAULT 'pending',
    verification_data JSONB, -- All verification details
    verified_by UUID REFERENCES users(id),
    verified_at TIMESTAMPTZ,
    expires_at DATE,
    notes TEXT,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- ============================================================================
-- DEVICES & SESSIONS
-- ============================================================================

CREATE TABLE user_devices (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    device_id VARCHAR(255) NOT NULL, -- Device fingerprint
    device_name VARCHAR(255),
    device_type VARCHAR(50), -- 'ios', 'android', 'web'
    os_version VARCHAR(50),
    app_version VARCHAR(50),
    push_token TEXT,
    biometric_enabled BOOLEAN DEFAULT FALSE,
    is_trusted BOOLEAN DEFAULT FALSE,
    last_used_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    UNIQUE(user_id, device_id)
);

CREATE TABLE user_sessions (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    device_id UUID REFERENCES user_devices(id),
    access_token_hash VARCHAR(255) NOT NULL,
    refresh_token_hash VARCHAR(255) NOT NULL,
    ip_address INET,
    user_agent TEXT,
    expires_at TIMESTAMPTZ NOT NULL,
    is_revoked BOOLEAN DEFAULT FALSE,
    revoked_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE TABLE otp_codes (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id UUID REFERENCES users(id),
    phone VARCHAR(20) NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    purpose VARCHAR(50) NOT NULL, -- 'login', 'pin_reset', 'transaction'
    attempts INT DEFAULT 0,
    max_attempts INT DEFAULT 3,
    expires_at TIMESTAMPTZ NOT NULL,
    verified_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- ============================================================================
-- WALLETS & TRANSACTIONS
-- ============================================================================

CREATE TABLE wallets (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    wallet_type wallet_type NOT NULL,
    currency VARCHAR(3) DEFAULT 'EGP',
    balance DECIMAL(15,2) DEFAULT 0 CHECK (balance >= 0),
    held_balance DECIMAL(15,2) DEFAULT 0 CHECK (held_balance >= 0), -- SPH holds
    is_active BOOLEAN DEFAULT TRUE,
    daily_limit DECIMAL(15,2),
    monthly_limit DECIMAL(15,2),
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW(),
    version INT DEFAULT 1, -- Optimistic locking
    UNIQUE(user_id, wallet_type)
);

-- Platform wallet for PeacePay profits
CREATE TABLE platform_wallets (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    name VARCHAR(100) NOT NULL UNIQUE,
    balance DECIMAL(15,2) DEFAULT 0,
    currency VARCHAR(3) DEFAULT 'EGP',
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW(),
    version INT DEFAULT 1
);

-- Insert default platform wallet
INSERT INTO platform_wallets (name) VALUES ('peacepay_profit');

CREATE TABLE wallet_transactions (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    wallet_id UUID NOT NULL REFERENCES wallets(id),
    transaction_type transaction_type NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    balance_before DECIMAL(15,2) NOT NULL,
    balance_after DECIMAL(15,2) NOT NULL,
    held_balance_before DECIMAL(15,2),
    held_balance_after DECIMAL(15,2),
    reference_type VARCHAR(50), -- 'peacelink', 'cashout', 'topup', 'fee'
    reference_id UUID,
    description TEXT,
    metadata JSONB,
    idempotency_key VARCHAR(255) UNIQUE, -- Prevent duplicates
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- Append-only ledger for audit (immutable)
CREATE TABLE ledger_entries (
    id BIGSERIAL PRIMARY KEY,
    entry_id UUID UNIQUE DEFAULT uuid_generate_v4(),
    peacelink_id UUID,
    debit_wallet_id UUID REFERENCES wallets(id),
    credit_wallet_id UUID REFERENCES wallets(id),
    platform_wallet_id UUID REFERENCES platform_wallets(id),
    amount DECIMAL(15,2) NOT NULL,
    entry_type VARCHAR(50) NOT NULL, -- 'sph_hold', 'merchant_payout', 'dsp_payout', 'platform_fee', 'refund'
    description TEXT,
    metadata JSONB,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- ============================================================================
-- FEE CONFIGURATION
-- ============================================================================

CREATE TABLE fee_configurations (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    fee_type fee_type NOT NULL,
    rate DECIMAL(8,4) NOT NULL, -- Percentage as decimal (0.005 = 0.5%)
    fixed_amount DECIMAL(10,2) DEFAULT 0,
    min_amount DECIMAL(10,2),
    max_amount DECIMAL(10,2),
    currency VARCHAR(3) DEFAULT 'EGP',
    is_active BOOLEAN DEFAULT TRUE,
    effective_from TIMESTAMPTZ NOT NULL,
    effective_to TIMESTAMPTZ,
    created_by UUID REFERENCES users(id),
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- Insert default fees
INSERT INTO fee_configurations (fee_type, rate, fixed_amount, effective_from) VALUES
    ('merchant_percentage', 0.005, 0, NOW()), -- 0.5%
    ('merchant_fixed', 0, 2, NOW()), -- 2 EGP fixed (final release only)
    ('dsp_percentage', 0.005, 0, NOW()), -- 0.5%
    ('cashout_percentage', 0.015, 0, NOW()), -- 1.5%
    ('advance_percentage', 0.005, 0, NOW()); -- 0.5% (no fixed fee)

-- ============================================================================
-- DELIVERY POLICIES
-- ============================================================================

CREATE TABLE delivery_policies (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    merchant_id UUID NOT NULL REFERENCES users(id),
    name VARCHAR(100) NOT NULL,
    name_ar VARCHAR(100),
    description TEXT,
    description_ar TEXT,
    max_delivery_days INT DEFAULT 7,
    delivery_fee_paid_by VARCHAR(20) DEFAULT 'buyer', -- 'buyer' or 'merchant'
    advance_payment_percentage DECIMAL(5,2) DEFAULT 0,
    allow_partial_delivery BOOLEAN DEFAULT FALSE,
    auto_cancel_after_days INT, -- Auto-cancel if not delivered
    is_default BOOLEAN DEFAULT FALSE,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

-- ============================================================================
-- PEACELINK (SPH) TRANSACTIONS
-- ============================================================================

CREATE TABLE peacelinks (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    reference_number VARCHAR(20) UNIQUE NOT NULL, -- Human-readable reference
    
    -- Parties
    merchant_id UUID NOT NULL REFERENCES users(id),
    buyer_id UUID REFERENCES users(id), -- NULL until buyer identified
    buyer_phone VARCHAR(20) NOT NULL, -- Required for link access
    dsp_id UUID REFERENCES users(id),
    dsp_wallet_number VARCHAR(50), -- DSP wallet for payout
    assigned_driver_id UUID REFERENCES users(id), -- If DSP company assigns specific driver
    
    -- Policy & Configuration (frozen at creation)
    policy_id UUID REFERENCES delivery_policies(id),
    policy_snapshot JSONB NOT NULL, -- Frozen policy at creation time
    fee_snapshot JSONB NOT NULL, -- Frozen fees at creation time
    
    -- Amounts
    item_amount DECIMAL(15,2) NOT NULL,
    delivery_fee DECIMAL(15,2) NOT NULL,
    total_amount DECIMAL(15,2) NOT NULL, -- item + delivery
    delivery_fee_paid_by VARCHAR(20) NOT NULL, -- 'buyer' or 'merchant'
    advance_percentage DECIMAL(5,2) DEFAULT 0,
    advance_amount DECIMAL(15,2) DEFAULT 0,
    
    -- Status
    status peacelink_status DEFAULT 'created',
    
    -- Item Details
    item_description TEXT NOT NULL,
    item_description_ar TEXT,
    item_quantity INT DEFAULT 1,
    item_metadata JSONB, -- Additional item details
    
    -- OTP
    otp_hash VARCHAR(255),
    otp_generated_at TIMESTAMPTZ,
    otp_expires_at TIMESTAMPTZ,
    otp_attempts INT DEFAULT 0,
    otp_verified_at TIMESTAMPTZ,
    otp_verified_by UUID REFERENCES users(id), -- Who entered OTP
    
    -- Timestamps
    expires_at TIMESTAMPTZ NOT NULL, -- Link expiry (before approval)
    max_delivery_at TIMESTAMPTZ, -- Deadline for delivery (after approval)
    approved_at TIMESTAMPTZ,
    dsp_assigned_at TIMESTAMPTZ,
    delivered_at TIMESTAMPTZ,
    canceled_at TIMESTAMPTZ,
    canceled_by cancellation_party,
    cancellation_reason TEXT,
    
    -- Tracking
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW(),
    version INT DEFAULT 1 -- Optimistic locking
);

-- SPH Hold record
CREATE TABLE sph_holds (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    peacelink_id UUID NOT NULL REFERENCES peacelinks(id),
    buyer_wallet_id UUID NOT NULL REFERENCES wallets(id),
    amount DECIMAL(15,2) NOT NULL,
    status VARCHAR(20) DEFAULT 'active', -- 'active', 'released', 'refunded'
    released_at TIMESTAMPTZ,
    refunded_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- Payout records (who received what)
CREATE TABLE peacelink_payouts (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    peacelink_id UUID NOT NULL REFERENCES peacelinks(id),
    recipient_type VARCHAR(20) NOT NULL, -- 'merchant', 'dsp', 'buyer', 'platform'
    recipient_id UUID REFERENCES users(id),
    wallet_id UUID REFERENCES wallets(id),
    platform_wallet_id UUID REFERENCES platform_wallets(id),
    gross_amount DECIMAL(15,2) NOT NULL,
    fee_amount DECIMAL(15,2) DEFAULT 0,
    net_amount DECIMAL(15,2) NOT NULL,
    payout_type VARCHAR(50) NOT NULL, -- 'advance', 'final', 'delivery_fee', 'refund', 'platform_fee'
    is_advance BOOLEAN DEFAULT FALSE,
    transaction_id UUID REFERENCES wallet_transactions(id),
    notes TEXT,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- ============================================================================
-- DISPUTES
-- ============================================================================

CREATE TABLE disputes (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    peacelink_id UUID NOT NULL REFERENCES peacelinks(id),
    opened_by UUID NOT NULL REFERENCES users(id),
    opened_by_role user_role NOT NULL,
    status dispute_status DEFAULT 'open',
    reason TEXT NOT NULL,
    reason_ar TEXT,
    evidence_urls TEXT[],
    
    -- Resolution
    resolved_by UUID REFERENCES users(id),
    resolved_at TIMESTAMPTZ,
    resolution_type VARCHAR(50), -- 'refund_buyer', 'release_merchant', 'split', 'other'
    resolution_notes TEXT,
    buyer_amount DECIMAL(15,2),
    merchant_amount DECIMAL(15,2),
    dsp_amount DECIMAL(15,2),
    
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE TABLE dispute_messages (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    dispute_id UUID NOT NULL REFERENCES disputes(id) ON DELETE CASCADE,
    sender_id UUID NOT NULL REFERENCES users(id),
    message TEXT NOT NULL,
    attachments TEXT[],
    is_admin_only BOOLEAN DEFAULT FALSE, -- Internal admin notes
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- ============================================================================
-- CASH-OUT
-- ============================================================================

CREATE TABLE cashout_methods (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    method_type VARCHAR(50) NOT NULL, -- 'bank_transfer', 'instapay', 'vodafone_cash', 'fawry'
    account_name VARCHAR(255),
    account_number VARCHAR(50),
    bank_name VARCHAR(100),
    bank_code VARCHAR(20),
    is_default BOOLEAN DEFAULT FALSE,
    is_verified BOOLEAN DEFAULT FALSE,
    verified_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE TABLE cashout_requests (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id UUID NOT NULL REFERENCES users(id),
    wallet_id UUID NOT NULL REFERENCES wallets(id),
    cashout_method_id UUID NOT NULL REFERENCES cashout_methods(id),
    requested_amount DECIMAL(15,2) NOT NULL,
    fee_amount DECIMAL(15,2) NOT NULL, -- Calculated and deducted at request
    net_amount DECIMAL(15,2) NOT NULL, -- Amount user receives
    status cashout_status DEFAULT 'pending',
    
    -- Processing
    processed_by UUID REFERENCES users(id),
    processed_at TIMESTAMPTZ,
    rejection_reason TEXT,
    
    -- External reference
    external_reference VARCHAR(100),
    external_status VARCHAR(50),
    
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

-- ============================================================================
-- NOTIFICATIONS
-- ============================================================================

CREATE TABLE notification_templates (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    code VARCHAR(100) UNIQUE NOT NULL, -- 'peacelink_created', 'otp_sent', etc.
    name VARCHAR(255) NOT NULL,
    channel notification_type NOT NULL,
    title_en TEXT,
    title_ar TEXT,
    body_en TEXT NOT NULL,
    body_ar TEXT NOT NULL,
    variables TEXT[], -- Expected variables like {buyer_name}, {amount}
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE TABLE notifications (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id UUID NOT NULL REFERENCES users(id),
    template_id UUID REFERENCES notification_templates(id),
    channel notification_type NOT NULL,
    title TEXT,
    body TEXT NOT NULL,
    data JSONB, -- Additional data for deep linking
    is_read BOOLEAN DEFAULT FALSE,
    read_at TIMESTAMPTZ,
    sent_at TIMESTAMPTZ,
    delivery_status VARCHAR(20), -- 'pending', 'sent', 'delivered', 'failed'
    delivery_error TEXT,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- ============================================================================
-- AUDIT & LOGS
-- ============================================================================

CREATE TABLE audit_logs (
    id BIGSERIAL PRIMARY KEY,
    event_id UUID DEFAULT uuid_generate_v4(),
    user_id UUID REFERENCES users(id),
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) NOT NULL, -- 'peacelink', 'wallet', 'user', etc.
    entity_id UUID,
    old_values JSONB,
    new_values JSONB,
    ip_address INET,
    user_agent TEXT,
    metadata JSONB,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- API request logs for enterprise integrations
CREATE TABLE api_logs (
    id BIGSERIAL PRIMARY KEY,
    merchant_id UUID REFERENCES users(id),
    endpoint VARCHAR(255) NOT NULL,
    method VARCHAR(10) NOT NULL,
    request_body JSONB,
    response_body JSONB,
    response_status INT,
    latency_ms INT,
    ip_address INET,
    idempotency_key VARCHAR(255),
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- ============================================================================
-- INDEXES
-- ============================================================================

-- Users
CREATE INDEX idx_users_phone ON users(phone);
CREATE INDEX idx_users_role ON users(role);
CREATE INDEX idx_users_kyc_level ON users(kyc_level);

-- Wallets
CREATE INDEX idx_wallets_user_id ON wallets(user_id);
CREATE INDEX idx_wallet_transactions_wallet_id ON wallet_transactions(wallet_id);
CREATE INDEX idx_wallet_transactions_reference ON wallet_transactions(reference_type, reference_id);
CREATE INDEX idx_wallet_transactions_created_at ON wallet_transactions(created_at);

-- PeaceLinks
CREATE INDEX idx_peacelinks_merchant_id ON peacelinks(merchant_id);
CREATE INDEX idx_peacelinks_buyer_id ON peacelinks(buyer_id);
CREATE INDEX idx_peacelinks_buyer_phone ON peacelinks(buyer_phone);
CREATE INDEX idx_peacelinks_dsp_id ON peacelinks(dsp_id);
CREATE INDEX idx_peacelinks_status ON peacelinks(status);
CREATE INDEX idx_peacelinks_reference_number ON peacelinks(reference_number);
CREATE INDEX idx_peacelinks_created_at ON peacelinks(created_at);

-- Disputes
CREATE INDEX idx_disputes_peacelink_id ON disputes(peacelink_id);
CREATE INDEX idx_disputes_status ON disputes(status);

-- Cash-out
CREATE INDEX idx_cashout_requests_user_id ON cashout_requests(user_id);
CREATE INDEX idx_cashout_requests_status ON cashout_requests(status);

-- Audit
CREATE INDEX idx_audit_logs_user_id ON audit_logs(user_id);
CREATE INDEX idx_audit_logs_entity ON audit_logs(entity_type, entity_id);
CREATE INDEX idx_audit_logs_created_at ON audit_logs(created_at);

-- ============================================================================
-- TRIGGERS
-- ============================================================================

-- Auto-update updated_at timestamp
CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ language 'plpgsql';

CREATE TRIGGER update_users_updated_at BEFORE UPDATE ON users
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER update_wallets_updated_at BEFORE UPDATE ON wallets
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER update_peacelinks_updated_at BEFORE UPDATE ON peacelinks
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

-- Prevent ledger modifications (append-only)
CREATE OR REPLACE FUNCTION prevent_ledger_modification()
RETURNS TRIGGER AS $$
BEGIN
    RAISE EXCEPTION 'Ledger entries cannot be modified or deleted';
END;
$$ language 'plpgsql';

CREATE TRIGGER prevent_ledger_update BEFORE UPDATE ON ledger_entries
    FOR EACH ROW EXECUTE FUNCTION prevent_ledger_modification();

CREATE TRIGGER prevent_ledger_delete BEFORE DELETE ON ledger_entries
    FOR EACH ROW EXECUTE FUNCTION prevent_ledger_modification();

-- ============================================================================
-- VIEWS
-- ============================================================================

-- PeaceLink summary view
CREATE VIEW v_peacelink_summary AS
SELECT 
    p.id,
    p.reference_number,
    p.status,
    p.item_amount,
    p.delivery_fee,
    p.total_amount,
    p.advance_amount,
    p.created_at,
    p.approved_at,
    p.delivered_at,
    m.id as merchant_user_id,
    mp.business_name as merchant_name,
    b.id as buyer_user_id,
    bp.first_name || ' ' || bp.last_name as buyer_name,
    p.buyer_phone,
    d.id as dsp_user_id,
    dp.company_name as dsp_name
FROM peacelinks p
LEFT JOIN users m ON p.merchant_id = m.id
LEFT JOIN merchant_profiles mp ON m.id = mp.user_id
LEFT JOIN users b ON p.buyer_id = b.id
LEFT JOIN user_profiles bp ON b.id = bp.user_id
LEFT JOIN users d ON p.dsp_id = d.id
LEFT JOIN dsp_profiles dp ON d.id = dp.user_id;

-- Daily platform profit summary
CREATE VIEW v_daily_profit AS
SELECT 
    DATE(created_at) as date,
    SUM(CASE WHEN entry_type = 'platform_fee' THEN amount ELSE 0 END) as total_profit,
    COUNT(DISTINCT peacelink_id) as transaction_count
FROM ledger_entries
WHERE platform_wallet_id IS NOT NULL
GROUP BY DATE(created_at)
ORDER BY date DESC;

-- ============================================================================
-- END OF SCHEMA
-- ============================================================================
