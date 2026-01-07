-- PeacePay Database Initialization
-- This script runs on first MySQL container startup

-- Set character encoding
SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

-- Create database if not exists (backup, already created by env vars)
CREATE DATABASE IF NOT EXISTS peacepay 
    CHARACTER SET utf8mb4 
    COLLATE utf8mb4_unicode_ci;

-- Grant privileges
GRANT ALL PRIVILEGES ON peacepay.* TO 'peacepay'@'%';
FLUSH PRIVILEGES;

-- Log initialization
SELECT 'PeacePay database initialized successfully' AS status;
