# Bulk Import Testing Guide

## Overview
The bulk import feature allows administrators to import multiple companies from CSV or Excel files with built-in validation and preview functionality.

## Features Implemented

### ✅ File Format Support
- **CSV Files** (.csv) - Text-based comma-separated values
- **Excel Files** (.xlsx, .xls) - Microsoft Excel workbooks using SheetJS library

### ✅ Validation Features
1. **Duplicate Detection in File**: Identifies if the same NIPT appears multiple times in the import file
2. **Duplicate Detection in Database**: Checks if any NIPT already exists in the database
3. **Required Columns**: Validates that NIPT and Company_Name columns are present
4. **Empty Row Handling**: Skips empty rows automatically

### ✅ Preview & Review
- Color-coded preview table showing:
  - ✅ Green rows = New companies (will be imported)
  - ❌ Red rows = Duplicates (will be skipped)
- Summary statistics showing:
  - Number of duplicates in file
  - Number of companies already in database
  - Number ready to import
- Scrollable preview table for large imports

### ✅ Import Behavior
- All imported companies are created as **INACTIVE** (is_active = 0)
- Companies are imported **sequentially** (one at a time)
- Real-time progress and results display
- Success/failure tracking per company
- Automatic company list refresh after import

## Test Files

### Test CSV File
Location: `test-import.csv`

```csv
NIPT,Company_Name,Notes
K43128625A,Test Company Alpha,Main headquarters - Tirana
L71234567B,Test Company Beta,Branch office - Durres
M98765432C,Test Company Gamma,Regional office - Vlore
K43128625A,Duplicate Company,This is a duplicate NIPT
N55667788D,Test Company Delta,New client - Shkoder
P11223344E,Test Company Epsilon,Enterprise customer
```

**Expected Results:**
- K43128625A (row 2) = ❌ Already exists in database
- L71234567B = ✅ New company (will import)
- M98765432C = ✅ New company (will import)
- K43128625A (row 5) = ⚠️ Duplicate in file (will skip, only first occurrence counts)
- N55667788D = ✅ New company (will import)
- P11223344E = ✅ New company (will import)

**Summary:**
- Duplicates in file: 1 (K43128625A on rows 2 and 5)
- Already in database: 1 (K43128625A)
- Ready to import: 4 (L71234567B, M98765432C, N55667788D, P11223344E)

## Testing Steps

### 1. Access the Admin Panel
1. Navigate to: `http://localhost:8000/admin-companies.html`
2. Log in with admin credentials
3. Scroll to "📥 Bulk Import Companies" section

### 2. Download Template (Optional)
1. Click "📄 Download CSV Template"
2. Open the downloaded file to see the format
3. Template includes:
   - Header row: NIPT, Company_Name, Notes
   - Two example companies

### 3. Test CSV Import
1. Click "Select CSV or Excel File"
2. Choose `test-import.csv`
3. Click "📋 Preview & Validate"
4. **Verify Preview Display:**
   - Yellow warning box showing duplicate in file (K43128625A on rows 2, 5)
   - Red error box showing already exists in DB (1 company)
   - Blue info box showing ready to import (4 companies)
   - Preview table with color-coded rows:
     - Red row for K43128625A (duplicate)
     - Green rows for 4 new companies
5. Click "✅ Confirm Import"
6. **Verify Import Progress:**
   - "⏳ Importing 4 companies..." message appears
   - Results show success count
   - Results list each imported company
7. **Verify Results:**
   - 4 companies successfully imported
   - 0 failures (unless network issues)
   - Company list automatically refreshes
   - New companies appear with "Inactive" status

### 4. Test Excel Import (Create Excel File)
1. Open Excel or Google Sheets
2. Create a spreadsheet with columns: NIPT, Company_Name, Notes
3. Add test data:
   ```
   NIPT          | Company_Name           | Notes
   Q22334455F    | Excel Import Test 1    | Testing Excel format
   R33445566G    | Excel Import Test 2    | Another test
   ```
4. Save as .xlsx file
5. Import using same process as CSV
6. Verify Excel parsing works correctly

### 5. Test Edge Cases

#### Empty File
1. Create CSV with only headers: `NIPT,Company_Name,Notes`
2. Try to import
3. **Expected:** "❌ No valid companies found in file" alert

#### Missing Required Column
1. Create CSV with wrong headers: `Code,Name,Info`
2. Try to import
3. **Expected:** "❌ File must have NIPT and Company_Name columns" alert

#### All Duplicates
1. Create CSV with only existing NIPTs (K43128625A, M01419018I)
2. Preview should show:
   - All rows in red
   - "❌ No companies to import" message
   - Confirm button disabled

#### Large File (100+ companies)
1. Create CSV with 100+ unique companies
2. Verify:
   - Preview table is scrollable
   - Import completes without timeout
   - Results are properly displayed

### 6. Verify Database
After import, check database:
```bash
mysql -u root -D qbo_multicompany -e "SELECT company_code, company_name, is_active FROM companies ORDER BY id DESC LIMIT 10"
```

**Expected:**
- New companies have is_active = 0
- company_code matches NIPT from file
- company_name matches Company_Name from file

### 7. Test Cancel Function
1. Upload a file
2. Click "📋 Preview & Validate"
3. Click "❌ Cancel" button
4. **Verify:**
   - Preview section hides
   - File input resets
   - No companies imported

## Validation Rules

### File-Level Validation
- ✅ File must not be empty
- ✅ File must have at least 2 rows (header + 1 data row)
- ✅ File must have NIPT column (case-insensitive)
- ✅ File must have a column containing "name" (case-insensitive)
- ✅ Notes column is optional

### Row-Level Validation
- ✅ NIPT field is required (empty NIFTs are skipped)
- ✅ Company_Name field is required (empty names are skipped)
- ✅ NIPT is automatically converted to UPPERCASE
- ✅ Leading/trailing whitespace is trimmed

### Duplicate Handling
- ✅ Within file: First occurrence is kept, subsequent duplicates skipped
- ✅ Against database: Existing NIPTs are flagged and skipped
- ✅ Both types of duplicates are shown in preview with clear messages

## Error Handling

### Network Errors
- If API is unreachable: "❌ Network error" for each company
- Import continues for remaining companies

### Server Errors
- If server returns error: Shows error message from API
- Example: "❌ K43128625A - Company code already exists"

### File Reading Errors
- CSV parse errors: "❌ Error reading file: [error message]"
- Excel parse errors: Same as above
- Invalid file format: Caught by file extension check

## User Interface Elements

### Validation Messages
```
⚠️ Duplicates in file (1):
NIPT K43128625A appears on rows 2, 5
Only the first occurrence will be imported.

❌ Already exist in database (1):
K43128625A - Test Company Alpha
These will be skipped.

✅ Ready to import (4):
All companies will be created as INACTIVE and assigned to admin only.
```

### Preview Table
```
| NIPT          | Company Name    | Notes          | Status       |
|---------------|-----------------|----------------|--------------|
| K43128625A    | Company Alpha   | Main office    | ❌ Duplicate |
| L71234567B    | Company Beta    | Branch office  | ✅ New       |
```

### Import Results
```
✅ Successfully imported: 4

✅ L71234567B - Test Company Beta
✅ M98765432C - Test Company Gamma
✅ N55667788D - Test Company Delta
✅ P11223344E - Test Company Epsilon
```

## Performance Considerations

### Sequential Import
- Companies are imported **one at a time**
- Ensures each company is validated individually
- Prevents partial failures from corrupting batch
- Trade-off: Slower for very large imports (100+ companies)

### Recommendation for Large Imports
For files with 500+ companies:
1. Split into multiple files of ~100 companies each
2. Import in batches
3. Or: Implement batch endpoint in future (POST /api/companies/bulk)

## Troubleshooting

### Issue: "File must have NIPT and Company_Name columns"
- **Cause:** Column headers don't match expected format
- **Solution:** Ensure first row has "NIPT" and a column with "name" in it
- **Tip:** Column names are case-insensitive

### Issue: "No valid companies found in file"
- **Cause:** All rows are empty, missing NIPT, or missing company name
- **Solution:** Check that each row has both NIPT and Company_Name values

### Issue: All companies show as duplicates
- **Cause:** All NIPTs in file already exist in database
- **Solution:** Check existing companies list, use unique NIPTs

### Issue: Import button stays disabled
- **Cause:** No valid companies to import (all duplicates or errors)
- **Solution:** Fix duplicates or add new companies to file

### Issue: Excel file not parsing
- **Cause:** SheetJS library not loaded
- **Solution:** Check browser console for errors, verify CDN is accessible
- **Workaround:** Convert Excel to CSV and import as CSV

## Next Steps / Future Enhancements

### Potential Improvements
1. **Batch Import Endpoint**: Single API call for all companies (faster)
2. **CSV Parser Library**: Use PapaParse for better CSV handling (quoted commas, etc.)
3. **NIPT Format Validation**: Validate Albanian tax ID format (letter + digits)
4. **Edit Before Import**: Allow editing companies in preview before importing
5. **Partial Import**: Option to import valid rows even if some fail validation
6. **Import History**: Log of all imports with timestamp and user
7. **Excel Export**: Export existing companies to Excel for backup

### Known Limitations
1. CSV parser doesn't handle quoted commas (e.g., `"Company, Inc"`)
2. No NIPT format validation (accepts any non-empty string)
3. Sequential import is slow for large files (100+ companies)
4. No undo functionality after import
5. No option to set companies as active during import

## Conclusion

The bulk import feature is **fully functional** with:
- ✅ CSV and Excel support
- ✅ Duplicate detection (in-file and database)
- ✅ Color-coded preview
- ✅ Detailed validation messages
- ✅ Real-time import progress
- ✅ Comprehensive error handling

Ready for production use with the test file provided!
