-- Field Mapping Management System
-- Allows dynamic field mapping between DevPos and QuickBooks

-- ============================================================================
-- Table: field_mapping_templates
-- Stores reusable mapping templates for different entity types
-- ============================================================================
CREATE TABLE IF NOT EXISTS field_mapping_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entity_type ENUM('invoice', 'sales_receipt', 'bill', 'vendor', 'customer') NOT NULL,
    template_name VARCHAR(100) NOT NULL,
    description TEXT,
    is_default BOOLEAN DEFAULT FALSE,
    is_active BOOLEAN DEFAULT TRUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,
    UNIQUE KEY unique_template (entity_type, template_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- Table: field_mappings
-- Individual field mappings within templates
-- ============================================================================
CREATE TABLE IF NOT EXISTS field_mappings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_id INT NOT NULL,
    
    -- DevPos source field
    devpos_field VARCHAR(255) NOT NULL COMMENT 'DevPos field path (e.g., "totalAmount", "items[].quantity")',
    devpos_field_type ENUM('string', 'number', 'decimal', 'date', 'datetime', 'boolean', 'array', 'object') DEFAULT 'string',
    devpos_sample_value TEXT COMMENT 'Example value for reference',
    
    -- QuickBooks destination field
    qbo_field VARCHAR(255) NOT NULL COMMENT 'QBO field path (e.g., "Line[0].Amount", "CustomerRef.value")',
    qbo_field_type ENUM('string', 'number', 'decimal', 'date', 'datetime', 'boolean', 'object', 'reference') DEFAULT 'string',
    qbo_entity VARCHAR(50) COMMENT 'QBO entity if reference (e.g., "Customer", "Vendor", "Item")',
    
    -- Transformation rules
    transformation_type ENUM('direct', 'lookup', 'calculation', 'concatenation', 'conditional', 'custom') DEFAULT 'direct',
    transformation_rule TEXT COMMENT 'JSON configuration for transformation logic',
    default_value TEXT COMMENT 'Default value if source is null',
    
    -- Mapping metadata
    is_required BOOLEAN DEFAULT FALSE,
    is_active BOOLEAN DEFAULT TRUE,
    priority INT DEFAULT 100 COMMENT 'Processing order (lower = first)',
    notes TEXT,
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (template_id) REFERENCES field_mapping_templates(id) ON DELETE CASCADE,
    INDEX idx_template_active (template_id, is_active),
    INDEX idx_priority (template_id, priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- Table: company_field_mappings
-- Company-specific mapping overrides (optional)
-- ============================================================================
CREATE TABLE IF NOT EXISTS company_field_mappings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    entity_type ENUM('invoice', 'sales_receipt', 'bill', 'vendor', 'customer') NOT NULL,
    template_id INT COMMENT 'Use specific template, or NULL for custom',
    
    -- Override specific field mapping
    field_mapping_id INT COMMENT 'Override this specific field mapping',
    custom_qbo_field VARCHAR(255) COMMENT 'Custom QBO field for this company',
    custom_transformation TEXT COMMENT 'Custom transformation rule JSON',
    
    is_active BOOLEAN DEFAULT TRUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (template_id) REFERENCES field_mapping_templates(id) ON DELETE SET NULL,
    FOREIGN KEY (field_mapping_id) REFERENCES field_mappings(id) ON DELETE CASCADE,
    UNIQUE KEY unique_company_field (company_id, entity_type, field_mapping_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- Table: field_mapping_audit
-- Track changes to mappings for compliance
-- ============================================================================
CREATE TABLE IF NOT EXISTS field_mapping_audit (
    id INT AUTO_INCREMENT PRIMARY KEY,
    mapping_id INT,
    template_id INT,
    action ENUM('create', 'update', 'delete', 'activate', 'deactivate') NOT NULL,
    old_value TEXT COMMENT 'JSON of old mapping configuration',
    new_value TEXT COMMENT 'JSON of new mapping configuration',
    changed_by INT,
    changed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45),
    user_agent TEXT,
    
    INDEX idx_mapping (mapping_id),
    INDEX idx_template (template_id),
    INDEX idx_changed_at (changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- Insert Default Mapping Templates
-- ============================================================================

-- Template: Invoice (Sales E-Invoice)
INSERT INTO field_mapping_templates (entity_type, template_name, description, is_default, is_active) VALUES
('invoice', 'Standard Invoice Mapping', 'Default mapping for DevPos sales invoices to QBO invoices', TRUE, TRUE),
('sales_receipt', 'Standard Sales Receipt Mapping', 'Default mapping for DevPos cash sales to QBO sales receipts', TRUE, TRUE),
('bill', 'Standard Bill Mapping', 'Default mapping for DevPos purchase invoices to QBO bills', TRUE, TRUE),
('vendor', 'Standard Vendor Mapping', 'Default mapping for creating QBO vendors from DevPos suppliers', TRUE, TRUE),
('customer', 'Standard Customer Mapping', 'Default mapping for creating QBO customers from DevPos buyers', TRUE, TRUE);

-- ============================================================================
-- Default Invoice Mappings
-- ============================================================================
SET @invoice_template_id = (SELECT id FROM field_mapping_templates WHERE entity_type = 'invoice' AND is_default = TRUE LIMIT 1);

INSERT INTO field_mappings (template_id, devpos_field, devpos_field_type, qbo_field, qbo_field_type, transformation_type, is_required, priority, notes) VALUES
-- Document identifiers
(@invoice_template_id, 'eic', 'string', 'CustomField[0].StringValue', 'string', 'direct', TRUE, 10, 'Electronic Invoice Code - stored in custom field'),
(@invoice_template_id, 'documentNumber', 'string', 'DocNumber', 'string', 'direct', TRUE, 20, 'Invoice number'),
(@invoice_template_id, 'issueDate', 'date', 'TxnDate', 'date', 'direct', TRUE, 30, 'Invoice date'),

-- Customer reference
(@invoice_template_id, 'buyerNuis', 'string', 'CustomerRef.value', 'reference', 'lookup', TRUE, 40, 'Customer lookup by NUIS'),
(@invoice_template_id, 'buyerName', 'string', 'CustomerRef.name', 'string', 'lookup', TRUE, 41, 'Customer name for lookup'),

-- Financial fields
(@invoice_template_id, 'totalAmount', 'decimal', 'Line[0].Amount', 'decimal', 'direct', TRUE, 50, 'Total invoice amount'),
(@invoice_template_id, 'currency', 'string', 'CurrencyRef.value', 'reference', 'lookup', FALSE, 60, 'Transaction currency'),
(@invoice_template_id, 'exchangeRate', 'decimal', 'ExchangeRate', 'decimal', 'direct', FALSE, 61, 'Currency exchange rate'),

-- Line items (array handling)
(@invoice_template_id, 'items[].description', 'string', 'Line[].Description', 'string', 'direct', FALSE, 70, 'Line item description'),
(@invoice_template_id, 'items[].quantity', 'decimal', 'Line[].SalesItemLineDetail.Qty', 'decimal', 'direct', FALSE, 71, 'Line item quantity'),
(@invoice_template_id, 'items[].unitPrice', 'decimal', 'Line[].SalesItemLineDetail.UnitPrice', 'decimal', 'direct', FALSE, 72, 'Line item unit price'),
(@invoice_template_id, 'items[].amount', 'decimal', 'Line[].Amount', 'decimal', 'direct', FALSE, 73, 'Line item total'),

-- Tax fields
(@invoice_template_id, 'vatRate', 'decimal', 'TxnTaxDetail.TaxRate', 'decimal', 'direct', FALSE, 80, 'VAT/Tax rate'),
(@invoice_template_id, 'totalAmountVat', 'decimal', 'TxnTaxDetail.TotalTax', 'decimal', 'direct', FALSE, 81, 'Total tax amount'),

-- Payment terms
(@invoice_template_id, 'dueDate', 'date', 'DueDate', 'date', 'direct', FALSE, 90, 'Payment due date'),
(@invoice_template_id, 'paymentTerms', 'string', 'SalesTermRef.value', 'reference', 'lookup', FALSE, 91, 'Payment terms lookup'),

-- Notes and memos
(@invoice_template_id, 'notes', 'string', 'CustomerMemo.value', 'string', 'direct', FALSE, 100, 'Customer-facing memo'),
(@invoice_template_id, 'internalNote', 'string', 'PrivateNote', 'string', 'direct', FALSE, 101, 'Internal private note');

-- ============================================================================
-- Default Bill Mappings
-- ============================================================================
SET @bill_template_id = (SELECT id FROM field_mapping_templates WHERE entity_type = 'bill' AND is_default = TRUE LIMIT 1);

INSERT INTO field_mappings (template_id, devpos_field, devpos_field_type, qbo_field, qbo_field_type, transformation_type, is_required, priority, notes) VALUES
-- Document identifiers
(@bill_template_id, 'documentNumber', 'string', 'DocNumber', 'string', 'direct', TRUE, 10, 'Bill number'),
(@bill_template_id, 'issueDate', 'date', 'TxnDate', 'date', 'direct', TRUE, 20, 'Bill date'),

-- Vendor reference
(@bill_template_id, 'sellerNuis', 'string', 'VendorRef.value', 'reference', 'lookup', TRUE, 30, 'Vendor lookup by NUIS'),
(@bill_template_id, 'sellerName', 'string', 'VendorRef.name', 'string', 'lookup', TRUE, 31, 'Vendor name for lookup'),

-- Financial fields
(@bill_template_id, 'totalAmount', 'decimal', 'Line[0].Amount', 'decimal', 'direct', TRUE, 40, 'Total bill amount'),
(@bill_template_id, 'currency', 'string', 'CurrencyRef.value', 'reference', 'lookup', FALSE, 50, 'Transaction currency'),

-- Payment terms
(@bill_template_id, 'dueDate', 'date', 'DueDate', 'date', 'direct', FALSE, 60, 'Payment due date'),

-- Line items
(@bill_template_id, 'items[].description', 'string', 'Line[].Description', 'string', 'direct', FALSE, 70, 'Expense description'),
(@bill_template_id, 'items[].amount', 'decimal', 'Line[].Amount', 'decimal', 'direct', FALSE, 71, 'Expense amount'),
(@bill_template_id, 'items[].category', 'string', 'Line[].AccountBasedExpenseLineDetail.AccountRef.value', 'reference', 'lookup', FALSE, 72, 'Expense account mapping');

-- ============================================================================
-- Default Sales Receipt Mappings
-- ============================================================================
SET @receipt_template_id = (SELECT id FROM field_mapping_templates WHERE entity_type = 'sales_receipt' AND is_default = TRUE LIMIT 1);

INSERT INTO field_mappings (template_id, devpos_field, devpos_field_type, qbo_field, qbo_field_type, transformation_type, is_required, priority, notes) VALUES
-- Document identifiers
(@receipt_template_id, 'eic', 'string', 'DocNumber', 'string', 'direct', TRUE, 10, 'Receipt number from EIC'),
(@receipt_template_id, 'issueDate', 'date', 'TxnDate', 'date', 'direct', TRUE, 20, 'Receipt date'),

-- Customer reference
(@receipt_template_id, 'buyerNuis', 'string', 'CustomerRef.value', 'reference', 'lookup', FALSE, 30, 'Customer lookup (optional for cash)'),
(@receipt_template_id, 'buyerName', 'string', 'CustomerRef.name', 'string', 'lookup', FALSE, 31, 'Customer name'),

-- Financial fields
(@receipt_template_id, 'totalAmount', 'decimal', 'Line[0].Amount', 'decimal', 'direct', TRUE, 40, 'Receipt total'),
(@receipt_template_id, 'currency', 'string', 'CurrencyRef.value', 'reference', 'lookup', FALSE, 50, 'Currency'),

-- Payment method
(@receipt_template_id, 'invoicePayments[0].paymentMethodType', 'number', 'PaymentMethodRef.value', 'reference', 'lookup', FALSE, 60, 'Payment method (0=Cash, 1=Card)');

-- ============================================================================
-- Default Vendor Mappings
-- ============================================================================
SET @vendor_template_id = (SELECT id FROM field_mapping_templates WHERE entity_type = 'vendor' AND is_default = TRUE LIMIT 1);

INSERT INTO field_mappings (template_id, devpos_field, devpos_field_type, qbo_field, qbo_field_type, transformation_type, is_required, priority, notes) VALUES
(@vendor_template_id, 'sellerName', 'string', 'DisplayName', 'string', 'direct', TRUE, 10, 'Vendor display name'),
(@vendor_template_id, 'sellerNuis', 'string', 'CompanyName', 'string', 'concatenation', TRUE, 20, 'Company name with NUIS'),
(@vendor_template_id, 'sellerAddress', 'string', 'BillAddr.Line1', 'string', 'direct', FALSE, 30, 'Vendor address'),
(@vendor_template_id, 'sellerTown', 'string', 'BillAddr.City', 'string', 'direct', FALSE, 31, 'Vendor city'),
(@vendor_template_id, 'sellerCountry', 'string', 'BillAddr.Country', 'string', 'direct', FALSE, 32, 'Vendor country'),
(@vendor_template_id, 'sellerEmail', 'string', 'PrimaryEmailAddr.Address', 'string', 'direct', FALSE, 40, 'Vendor email'),
(@vendor_template_id, 'sellerPhone', 'string', 'PrimaryPhone.FreeFormNumber', 'string', 'direct', FALSE, 41, 'Vendor phone');

-- ============================================================================
-- Default Customer Mappings
-- ============================================================================
SET @customer_template_id = (SELECT id FROM field_mapping_templates WHERE entity_type = 'customer' AND is_default = TRUE LIMIT 1);

INSERT INTO field_mappings (template_id, devpos_field, devpos_field_type, qbo_field, qbo_field_type, transformation_type, is_required, priority, notes) VALUES
(@customer_template_id, 'buyerName', 'string', 'DisplayName', 'string', 'direct', TRUE, 10, 'Customer display name'),
(@customer_template_id, 'buyerNuis', 'string', 'CompanyName', 'string', 'concatenation', TRUE, 20, 'Company name with NUIS'),
(@customer_template_id, 'buyerAddress', 'string', 'BillAddr.Line1', 'string', 'direct', FALSE, 30, 'Customer address'),
(@customer_template_id, 'buyerTown', 'string', 'BillAddr.City', 'string', 'direct', FALSE, 31, 'Customer city'),
(@customer_template_id, 'buyerCountry', 'string', 'BillAddr.Country', 'string', 'direct', FALSE, 32, 'Customer country'),
(@customer_template_id, 'buyerEmail', 'string', 'PrimaryEmailAddr.Address', 'string', 'direct', FALSE, 40, 'Customer email'),
(@customer_template_id, 'buyerPhone', 'string', 'PrimaryPhone.FreeFormNumber', 'string', 'direct', FALSE, 41, 'Customer phone');

-- ============================================================================
-- Sample Transformation Rules (JSON format)
-- ============================================================================

-- Update transformation_rule column with sample JSON configurations
UPDATE field_mappings SET transformation_rule = '{"type":"direct","validation":"required|string"}' WHERE devpos_field = 'eic';
UPDATE field_mappings SET transformation_rule = '{"type":"lookup","table":"vendor_mappings","key":"devpos_nuis","return":"qbo_vendor_id"}' WHERE devpos_field = 'sellerNuis';
UPDATE field_mappings SET transformation_rule = '{"type":"concatenation","format":"{sellerName} ({sellerNuis})"}' WHERE qbo_field = 'CompanyName';
UPDATE field_mappings SET transformation_rule = '{"type":"conditional","conditions":[{"if":"paymentMethodType == 0","then":"1"},{"if":"paymentMethodType == 1","then":"2"}],"default":"1"}' WHERE devpos_field = 'invoicePayments[0].paymentMethodType';

-- ============================================================================
-- Indexes for Performance
-- ============================================================================
CREATE INDEX idx_entity_default ON field_mapping_templates(entity_type, is_default);
CREATE INDEX idx_devpos_field ON field_mappings(devpos_field);
CREATE INDEX idx_qbo_field ON field_mappings(qbo_field);

-- ============================================================================
-- Views for Easy Querying
-- ============================================================================

CREATE OR REPLACE VIEW v_active_mappings AS
SELECT 
    t.id AS template_id,
    t.entity_type,
    t.template_name,
    t.is_default,
    m.id AS mapping_id,
    m.devpos_field,
    m.devpos_field_type,
    m.qbo_field,
    m.qbo_field_type,
    m.transformation_type,
    m.transformation_rule,
    m.default_value,
    m.is_required,
    m.priority,
    m.notes
FROM field_mapping_templates t
JOIN field_mappings m ON t.id = m.template_id
WHERE t.is_active = TRUE AND m.is_active = TRUE
ORDER BY t.entity_type, m.priority;

-- ============================================================================
-- Sample Queries
-- ============================================================================

-- Get all active mappings for invoices
-- SELECT * FROM v_active_mappings WHERE entity_type = 'invoice' ORDER BY priority;

-- Get company-specific mappings
-- SELECT * FROM company_field_mappings WHERE company_id = 1 AND is_active = TRUE;

-- Get mapping audit trail
-- SELECT * FROM field_mapping_audit WHERE mapping_id = 1 ORDER BY changed_at DESC;

-- ============================================================================
-- Migration Complete
-- ============================================================================
SELECT 'Field Mapping System Schema Created Successfully!' AS status;
