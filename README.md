# PeacePay - Secure Escrow Payment Platform

<div align="center">

![PeacePay Logo](https://via.placeholder.com/200x80?text=PeacePay)

**منصة الدفع الآمن للتجارة الإلكترونية في مصر**

Secure Escrow Payment Platform for E-commerce in Egypt

[![Laravel](https://img.shields.io/badge/Laravel-11.x-FF2D20?style=flat-square&logo=laravel)](https://laravel.com)
[![Flutter](https://img.shields.io/badge/Flutter-3.x-02569B?style=flat-square&logo=flutter)](https://flutter.dev)
[![License](https://img.shields.io/badge/License-Proprietary-blue?style=flat-square)](LICENSE)

</div>

---

## 📋 Table of Contents

- [Overview](#-overview)
- [Features](#-features)
- [Architecture](#-architecture)
- [Tech Stack](#-tech-stack)
- [Project Structure](#-project-structure)
- [Installation](#-installation)
- [API Documentation](#-api-documentation)
- [Testing](#-testing)
- [Deployment](#-deployment)
- [Security](#-security)
- [License](#-license)

---

## 🎯 Overview

PeacePay is a secure escrow payment platform designed specifically for the Egyptian e-commerce market. It provides a trusted intermediary for online transactions, protecting both buyers and merchants through a structured payment and delivery verification process.

### The Problem

Online commerce in Egypt faces trust issues between buyers and merchants:
- Buyers fear paying for items that never arrive or don't match descriptions
- Merchants fear delivering goods without receiving payment
- Traditional COD creates logistical challenges and fraud risks

### The Solution: PeaceLink

PeacePay introduces **PeaceLink** - a secure escrow transaction that:
1. Holds buyer's payment in escrow
2. Releases funds only after buyer confirms delivery
3. Protects both parties with dispute resolution
4. Integrates with DSPs (Delivery Service Providers)

---

## ✨ Features

### Core Features

| Feature | Description |
|---------|-------------|
| **Digital Wallet** | Add money via Fawry, Vodafone Cash, Cards, InstaPay |
| **P2P Transfers** | Instant money transfers between users |
| **PeaceLink Escrow** | Secure buyer-merchant transactions |
| **Delivery Integration** | Internal and external DSP support |
| **OTP Verification** | Secure delivery confirmation |
| **Dispute Resolution** | Fair conflict resolution system |
| **Cashout** | Bank transfer and mobile wallet withdrawals |
| **KYC System** | Three-tier verification (Basic, Silver, Gold) |

### Security Features

- 🔐 Laravel Sanctum API authentication
- 📱 SMS OTP verification
- 🔒 Hashed OTP storage
- 🛡️ Rate limiting on sensitive endpoints
- 📊 Transaction audit logs
- 🔑 Encrypted sensitive data

### Localization

- 🇪🇬 Full Arabic language support
- 📱 Egyptian phone number validation (01XXXXXXXXX)
- 🏦 Egyptian bank integration
- 💳 Local payment methods (Fawry, Vodafone Cash)
- 🆔 National ID validation (14 digits)

---

## 🏗️ Architecture

### System Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                        Mobile App (Flutter)                      │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌──────────────────┐ │
│  │   Auth   │  │  Wallet  │  │PeaceLink │  │     Profile      │ │
│  └──────────┘  └──────────┘  └──────────┘  └──────────────────┘ │
└─────────────────────────────┬───────────────────────────────────┘
                              │ HTTPS/REST
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                     Laravel Backend API                          │
│  ┌──────────────────────────────────────────────────────────┐   │
│  │                    API Gateway (v1)                       │   │
│  └──────────────────────────────────────────────────────────┘   │
│  ┌────────┐ ┌────────┐ ┌────────┐ ┌────────┐ ┌────────────┐    │
│  │  Auth  │ │ Wallet │ │PeaceLink│ │ Dispute│ │   KYC     │    │
│  └────────┘ └────────┘ └────────┘ └────────┘ └────────────┘    │
│  ┌──────────────────────────────────────────────────────────┐   │
│  │                   Service Layer                           │   │
│  │  WalletService | PeaceLinkService | OtpService | etc.    │   │
│  └──────────────────────────────────────────────────────────┘   │
└─────────────────────────────┬───────────────────────────────────┘
                              │
        ┌─────────────────────┼─────────────────────┐
        ▼                     ▼                     ▼
┌──────────────┐     ┌──────────────┐     ┌──────────────┐
│    MySQL     │     │    Redis     │     │  File Store  │
│   Database   │     │    Cache     │     │   (S3/Local) │
└──────────────┘     └──────────────┘     └──────────────┘
```

### PeaceLink Flow

```
Buyer                    PeacePay                   Merchant
  │                         │                          │
  │  1. Create PeaceLink    │                          │
  │─────────────────────────▶                          │
  │                         │  2. Notify Merchant      │
  │                         │─────────────────────────▶│
  │                         │                          │
  │                         │  3. Accept/Reject        │
  │                         │◀─────────────────────────│
  │  4. Funds Held          │                          │
  │◀─────────────────────────                          │
  │                         │                          │
  │         ┌───────────────┴───────────────┐          │
  │         │     DSP Picks Up Order        │          │
  │         │     DSP Delivers to Buyer     │          │
  │         └───────────────┬───────────────┘          │
  │                         │                          │
  │  5. Delivery OTP        │                          │
  │◀─────────────────────────                          │
  │                         │                          │
  │  6. Confirm Delivery    │                          │
  │─────────────────────────▶                          │
  │                         │  7. Release Funds        │
  │                         │─────────────────────────▶│
  │                         │                          │
```

---

## 🛠️ Tech Stack

### Backend
- **Framework**: Laravel 11.x
- **Language**: PHP 8.2+
- **Database**: MySQL 8.0
- **Cache**: Redis
- **Queue**: Redis + Laravel Horizon
- **Authentication**: Laravel Sanctum

### Frontend (Mobile)
- **Framework**: Flutter 3.x
- **State Management**: Provider/Riverpod
- **HTTP Client**: Dio
- **Local Storage**: SharedPreferences

### Infrastructure
- **Hosting**: AWS / DigitalOcean
- **Storage**: AWS S3
- **SMS**: Victory Link / Twilio
- **Push Notifications**: Firebase Cloud Messaging

---

## 📁 Project Structure

```
peacepay/
├── flutter/                          # Flutter Mobile App
│   └── lib/
│       └── features/
│           ├── screens_part1.dart    # Auth & Home screens
│           ├── screens_part2.dart    # Wallet & Money screens
│           └── screens_part3.dart    # PeaceLink screens
│
├── laravel/                          # Laravel Backend
│   ├── app/
│   │   ├── Http/
│   │   │   ├── Controllers/Api/V1/   # API Controllers
│   │   │   ├── Middleware/           # Custom Middleware
│   │   │   ├── Requests/             # Form Requests
│   │   │   └── Resources/            # API Resources
│   │   ├── Models/                   # Eloquent Models
│   │   └── Services/                 # Business Logic
│   ├── database/
│   │   ├── migrations/               # Database Migrations
│   │   └── seeders/                  # Database Seeders
│   ├── routes/
│   │   └── api.php                   # API Routes
│   └── .env.example                  # Environment Template
│
└── tests/                            # Test Suites
    ├── Feature/                      # Feature Tests
    └── Unit/                         # Unit Tests
```

---

## 🚀 Installation

### Prerequisites

- PHP 8.2+
- Composer 2.x
- MySQL 8.0+
- Redis
- Node.js 18+ (for assets)
- Flutter SDK 3.x

### Backend Setup

```bash
# Clone repository
git clone https://github.com/your-org/peacepay.git
cd peacepay/laravel

# Install dependencies
composer install

# Copy environment file
cp .env.example .env

# Generate application key
php artisan key:generate

# Configure database in .env, then:
php artisan migrate

# Seed test data (development only)
php artisan db:seed

# Start development server
php artisan serve
```

### Mobile App Setup

```bash
cd peacepay/flutter

# Get dependencies
flutter pub get

# Run on device/emulator
flutter run
```

---

## 📚 API Documentation

### Base URL
```
Production: https://api.peacepay.com/api/v1
Staging: https://staging-api.peacepay.com/api/v1
```

### Authentication

All protected endpoints require a Bearer token:

```http
Authorization: Bearer {token}
Accept: application/json
Accept-Language: ar
```

### Endpoints Overview

| Module | Endpoints |
|--------|-----------|
| **Auth** | POST /auth/register, /auth/login, /auth/verify-otp, etc. |
| **Wallet** | GET /wallet, POST /wallet/add-money, /wallet/send |
| **Cashout** | GET /cashout, POST /cashout |
| **PeaceLink** | GET /peacelinks, POST /peacelinks, /peacelinks/{id}/accept |
| **Disputes** | GET /disputes, POST /disputes, /disputes/{id}/respond |
| **KYC** | GET /kyc/status, POST /kyc/upgrade |
| **Notifications** | GET /notifications, POST /notifications/{id}/read |

### Response Format

```json
{
  "success": true,
  "message": "تمت العملية بنجاح",
  "data": { ... },
  "meta": { ... }
}
```

### Error Response

```json
{
  "success": false,
  "message": "رسالة الخطأ",
  "error": "error_code",
  "errors": { ... }
}
```

---

## 🧪 Testing

### Running Tests

```bash
# All tests
php artisan test

# Feature tests only
php artisan test --testsuite=Feature

# Unit tests only
php artisan test --testsuite=Unit

# With coverage report
php artisan test --coverage
```

### Test Coverage

| Category | Tests | Coverage |
|----------|-------|----------|
| Feature Tests | 51 | Auth, Wallet, PeaceLink, Disputes, etc. |
| Unit Tests | 50+ | Services, Models, Validation, Fees |
| **Total** | **100+** | Comprehensive coverage |

---

## 📦 Deployment

### Production Checklist

- [ ] Set `APP_ENV=production` and `APP_DEBUG=false`
- [ ] Configure production database
- [ ] Set up Redis for cache and queues
- [ ] Configure real SMS provider
- [ ] Set up FCM for push notifications
- [ ] Configure payment gateway credentials
- [ ] Enable HTTPS
- [ ] Set up queue workers
- [ ] Configure log rotation
- [ ] Set up monitoring (Sentry, etc.)

### Docker Deployment

```bash
# Build and run with Docker Compose
docker-compose up -d

# Run migrations
docker-compose exec app php artisan migrate --force

# Start queue worker
docker-compose exec app php artisan queue:work
```

---

## 🔒 Security

### Best Practices Implemented

1. **Authentication**: Sanctum tokens with expiration
2. **OTP Security**: Hashed storage, attempt limits, cooldowns
3. **Rate Limiting**: Per-endpoint and per-user limits
4. **Input Validation**: Comprehensive Form Requests
5. **SQL Injection**: Eloquent ORM and prepared statements
6. **XSS Prevention**: Response encoding
7. **CORS**: Configured for allowed origins only

### Reporting Security Issues

Please report security vulnerabilities to: security@peacepay.com

---

## 📊 Business Logic Summary

### Fee Structure

| Fee Type | Calculation |
|----------|-------------|
| Platform Fee | 0.5% + 2 EGP (on item amount) |
| Cashout Fee | 1.5% |
| Add Money (Fawry) | 5 EGP fixed |
| Add Money (Vodafone) | 1.5% |
| Add Money (Card) | 2.5% |
| Add Money (InstaPay) | Free |

### KYC Limits

| Level | Daily Transfer | Monthly Cashout |
|-------|---------------|-----------------|
| Basic | 5,000 EGP | 10,000 EGP |
| Silver | 10,000 EGP | 30,000 EGP |
| Gold | 50,000 EGP | 200,000 EGP |

---

## 📄 License

This project is proprietary software. All rights reserved.

© 2024-2026 HealthFlow Group - PeacePay

---

## 👥 Team

Built with ❤️ by the HealthFlow Engineering Team

---

<div align="center">

**PeacePay - تجارة آمنة، ثقة مضمونة**

*Secure Commerce, Guaranteed Trust*

</div>
