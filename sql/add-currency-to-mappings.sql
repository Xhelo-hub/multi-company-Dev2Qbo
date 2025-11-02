-- Migration: Add currency field to invoice_mappings table
-- This allows tracking currency changes for update detection

ALTER TABLE invoice_mappings 
ADD COLUMN currency VARCHAR(3) NULL COMMENT 'ISO 4217 currency code (USD, EUR, ALL, etc.)' AFTER amount;

-- Update existing records to default currency (ALL)
UPDATE invoice_mappings SET currency = 'ALL' WHERE currency IS NULL;
