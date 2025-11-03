-- Diagnostic queries to find duplicate bills in invoice_mappings

-- 1. Check for bills with same document number but different composite keys
SELECT 
    company_id,
    devpos_document_number,
    COUNT(*) as mapping_count,
    GROUP_CONCAT(devpos_eic SEPARATOR ' | ') as composite_keys,
    GROUP_CONCAT(qbo_invoice_id SEPARATOR ' | ') as qbo_ids,
    GROUP_CONCAT(CONCAT(amount, ' ', currency) SEPARATOR ' | ') as amounts
FROM invoice_mappings
WHERE transaction_type = 'bill'
GROUP BY company_id, devpos_document_number
HAVING COUNT(*) > 1
ORDER BY mapping_count DESC;

-- 2. Show all bill mappings with their composite keys
SELECT 
    id,
    company_id,
    devpos_eic as composite_key,
    devpos_document_number,
    qbo_invoice_id,
    amount,
    currency,
    customer_name as vendor_name,
    synced_at,
    last_synced_at
FROM invoice_mappings
WHERE transaction_type = 'bill'
ORDER BY company_id, devpos_document_number, synced_at DESC;

-- 3. Check for bills where vendor NUIS might be empty (composite key issues)
SELECT 
    id,
    company_id,
    devpos_eic as composite_key,
    devpos_document_number,
    CASE 
        WHEN devpos_eic LIKE '%||%' THEN 'DOUBLE PIPE (EMPTY NUIS)'
        WHEN devpos_eic LIKE '%|' THEN 'ENDS WITH PIPE (EMPTY NUIS)'
        WHEN devpos_eic NOT LIKE '%|%' THEN 'NO PIPE (INVALID FORMAT)'
        ELSE 'VALID'
    END as key_status,
    qbo_invoice_id,
    amount,
    currency
FROM invoice_mappings
WHERE transaction_type = 'bill'
ORDER BY 
    CASE 
        WHEN devpos_eic LIKE '%||%' THEN 1
        WHEN devpos_eic LIKE '%|' THEN 2
        WHEN devpos_eic NOT LIKE '%|%' THEN 3
        ELSE 4
    END,
    devpos_document_number;

-- 4. Summary by company
SELECT 
    company_id,
    COUNT(*) as total_bill_mappings,
    COUNT(DISTINCT devpos_document_number) as unique_doc_numbers,
    COUNT(*) - COUNT(DISTINCT devpos_document_number) as potential_duplicates
FROM invoice_mappings
WHERE transaction_type = 'bill'
GROUP BY company_id;
