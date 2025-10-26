-- Customer Mapping Table
-- Maps DevPos buyers to QuickBooks customers

CREATE TABLE IF NOT EXISTS customer_mappings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    
    -- DevPos buyer information
    buyer_nuis VARCHAR(20),
    buyer_name VARCHAR(255) NOT NULL,
    
    -- QuickBooks customer reference
    qbo_customer_id VARCHAR(50) NOT NULL,
    qbo_customer_display_name VARCHAR(255),
    
    -- Metadata
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_synced_at TIMESTAMP NULL,
    
    -- Indexes for fast lookup
    UNIQUE KEY unique_buyer_per_company (company_id, buyer_nuis),
    KEY idx_company_name (company_id, buyer_name),
    KEY idx_qbo_customer (qbo_customer_id),
    
    -- Foreign key
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add index for faster customer lookups
CREATE INDEX idx_buyer_nuis ON customer_mappings(buyer_nuis);
CREATE INDEX idx_buyer_name ON customer_mappings(buyer_name(50));
