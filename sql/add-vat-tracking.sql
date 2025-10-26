-- Add VAT tracking configuration to companies table
-- This allows some companies to track VAT separately while others post totals only

ALTER TABLE companies 
ADD COLUMN tracks_vat BOOLEAN DEFAULT FALSE 
COMMENT 'TRUE = Company tracks VAT separately (VAT-registered), FALSE = Post totals only (non-VAT companies)';

-- Update existing companies (you can manually set to TRUE for VAT-registered companies)
-- Example: UPDATE companies SET tracks_vat = TRUE WHERE id IN (1, 5, 10);

-- Show current status
SELECT id, company_code, company_name, tracks_vat 
FROM companies 
ORDER BY id;
