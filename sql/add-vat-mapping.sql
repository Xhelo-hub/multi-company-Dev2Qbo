-- VAT Rate Mapping Table
-- Maps DevPos VAT rates to QuickBooks tax codes per company

CREATE TABLE IF NOT EXISTS vat_rate_mappings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    devpos_vat_rate DECIMAL(5,2) NOT NULL COMMENT 'VAT rate from DevPos (e.g., 20.00, 10.00, 0.00)',
    qbo_tax_code VARCHAR(50) NOT NULL COMMENT 'QuickBooks TaxCodeRef value (e.g., TAX, EXEMPT, 20% VAT)',
    qbo_tax_code_name VARCHAR(100) COMMENT 'QuickBooks tax code display name',
    is_excluded BOOLEAN DEFAULT FALSE COMMENT 'TRUE if this represents VAT-excluded transactions',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_company_vat_rate (company_id, devpos_vat_rate),
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    INDEX idx_company_id (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Example mappings for a VAT-registered company
-- These would be configured via the dashboard for each company

-- INSERT INTO vat_rate_mappings (company_id, devpos_vat_rate, qbo_tax_code, qbo_tax_code_name, is_excluded) VALUES
-- (1, 0.00, 'EXEMPT', 'Tax Exempt', TRUE),     -- VAT Excluded transactions
-- (1, 20.00, 'TAX', 'Standard VAT 20%', FALSE), -- Standard UK VAT
-- (1, 10.00, '10VAT', 'Reduced VAT 10%', FALSE); -- Reduced rate

-- Query to see current mappings
SELECT 
    c.company_name,
    v.devpos_vat_rate,
    v.qbo_tax_code,
    v.qbo_tax_code_name,
    v.is_excluded
FROM vat_rate_mappings v
JOIN companies c ON v.company_id = c.id
ORDER BY c.company_name, v.devpos_vat_rate;
