-- Add VAT currency field to invoice_mappings table
-- This field stores the currency used for VAT calculation (Monedha e llogaritjes së TVSH-së)
-- which may differ from the invoice currency (Monedha e faturës)

ALTER TABLE invoice_mappings 
ADD COLUMN vat_currency VARCHAR(10) NULL 
COMMENT 'VAT calculation currency (Monedha e llogaritjes së TVSH-së)' 
AFTER currency;

-- Update existing records to set vat_currency same as currency if not already set
UPDATE invoice_mappings 
SET vat_currency = currency 
WHERE vat_currency IS NULL AND currency IS NOT NULL;
