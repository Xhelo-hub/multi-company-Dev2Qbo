# Multi-Company Dev2QBO - Copilot Instructions

## Project Overview
Multi-company DevPos to QuickBooks Online synchronization API that manages multiple QBO companies with different DevPos tenant credentials through a unified dashboard.

## Technology Stack
- PHP 8.x with Slim Framework 4.x (REST API)
- MySQL/MariaDB with PDO
- Composer for dependency management
- Guzzle HTTP client for API calls
- HTML/CSS/JavaScript dashboard

## Project Structure
```
src/
  ├── Services/          # Company and sync management
  ├── Sync/              # Sync logic (sales, purchases, bills)
  ├── Storage/           # Database repositories
  └── API/               # API clients (DevPos, QuickBooks)
routes/                  # API route definitions
public/                  # Web assets and dashboard
sql/                     # Database schema and migrations
bin/                     # CLI tools
bootstrap/               # Application bootstrap
```

## Development Guidelines
- Each company has isolated credentials and sync state
- All database operations are company-scoped
- Use dependency injection via Slim container
- API authentication via X-API-Key header
- Store sync results in JSON format for history
- Support async job execution for long-running syncs

## Key Features
- Multi-company credential management
- Company-scoped sync jobs with status tracking
- Unified dashboard for all companies
- Job history and statistics per company
- API endpoints for company and sync management
