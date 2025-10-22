# Bulk Import - Visual Walkthrough

## Step 1: Initial View
```
┌─────────────────────────────────────────────────────────┐
│ 📥 Bulk Import Companies                                │
│                                                          │
│ Import multiple companies from CSV or Excel file.       │
│ Companies will be created as inactive by default.       │
│                                                          │
│ [📄 Download CSV Template]                              │
│                                                          │
│ Select CSV or Excel File *                              │
│ [Choose File] No file chosen                            │
│ Required columns: NIPT, Company_Name (Optional: Notes)  │
│                                                          │
│ [📋 Preview & Validate]                                 │
└─────────────────────────────────────────────────────────┘
```

## Step 2: After Selecting File
```
┌─────────────────────────────────────────────────────────┐
│ 📥 Bulk Import Companies                                │
│                                                          │
│ Select CSV or Excel File *                              │
│ [Choose File] test-import.csv                           │
│                                                          │
│ [📋 Preview & Validate]  ← Click this                   │
└─────────────────────────────────────────────────────────┘
```

## Step 3: Preview & Validation Messages
```
┌─────────────────────────────────────────────────────────┐
│ 📋 Import Preview                                        │
│                                                          │
│ ┌───────────────────────────────────────────────────┐  │
│ │ ⚠️ Duplicates in file (1):                        │  │
│ │ NIPT K43128625A appears on rows 2, 5              │  │
│ │ Only the first occurrence will be imported.       │  │
│ └───────────────────────────────────────────────────┘  │
│                                                          │
│ ┌───────────────────────────────────────────────────┐  │
│ │ ❌ Already exist in database (1):                 │  │
│ │ K43128625A - Test Company Alpha                   │  │
│ │ These will be skipped.                            │  │
│ └───────────────────────────────────────────────────┘  │
│                                                          │
│ ┌───────────────────────────────────────────────────┐  │
│ │ ✅ Ready to import (4):                            │  │
│ │ All companies will be created as INACTIVE         │  │
│ │ and assigned to admin only.                       │  │
│ └───────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────┘
```

## Step 4: Preview Table
```
┌───────────────────────────────────────────────────────────────────┐
│ NIPT          │ Company Name         │ Notes              │ Status │
├───────────────────────────────────────────────────────────────────┤
│ K43128625A    │ Test Company Alpha   │ Main headquarters  │ ❌ Dup │ RED
│ L71234567B    │ Test Company Beta    │ Branch office      │ ✅ New │ GREEN
│ M98765432C    │ Test Company Gamma   │ Regional office    │ ✅ New │ GREEN
│ N55667788D    │ Test Company Delta   │ New client         │ ✅ New │ GREEN
│ P11223344E    │ Test Company Epsilon │ Enterprise         │ ✅ New │ GREEN
└───────────────────────────────────────────────────────────────────┘

[✅ Confirm Import]  [❌ Cancel]
```

## Step 5: Import Progress
```
┌─────────────────────────────────────────────────────────┐
│ Import Results:                                          │
│                                                          │
│ ⏳ Importing 4 companies...                             │
└─────────────────────────────────────────────────────────┘
```

## Step 6: Import Complete
```
┌─────────────────────────────────────────────────────────┐
│ Import Results:                                          │
│                                                          │
│ ✅ Successfully imported: 4                              │
│                                                          │
│ ✅ L71234567B - Test Company Beta                        │
│ ✅ M98765432C - Test Company Gamma                       │
│ ✅ N55667788D - Test Company Delta                       │
│ ✅ P11223344E - Test Company Epsilon                     │
└─────────────────────────────────────────────────────────┘
```

## Step 7: Updated Company List
```
┌─────────────────────────────────────────────────────────────────────┐
│ 📋 All Companies                                                    │
├────┬────────────┬───────────────────┬──────────┬─────────┬─────────┤
│ ID │ NIPT       │ Company Name      │ Status   │ DevPos  │ QB      │
├────┬────────────┬───────────────────┬──────────┬─────────┬─────────┤
│ 1  │ K43128625A │ AEM-Misioni...    │ 🟢 Active│ ✅      │ ✅      │
│ 2  │ M01419018I │ PGROUP INC        │ 🟢 Active│ ✅      │ ✅      │
│ 3  │ L71234567B │ Test Company Beta │ ⚪ Inactive│ ❌     │ ❌      │ NEW
│ 4  │ M98765432C │ Test Company Gamma│ ⚪ Inactive│ ❌     │ ❌      │ NEW
│ 5  │ N55667788D │ Test Company Delta│ ⚪ Inactive│ ❌     │ ❌      │ NEW
│ 6  │ P11223344E │ Test Company Eps..│ ⚪ Inactive│ ❌     │ ❌      │ NEW
└────┴────────────┴───────────────────┴──────────┴─────────┴─────────┘
```

## Color Coding

### Validation Messages
- 🟡 **Yellow Box** = Warning (duplicates in file, non-critical)
- 🔴 **Red Box** = Error (duplicates in database, will be skipped)
- 🔵 **Blue Box** = Info (ready to import, success message)

### Preview Table Rows
- 🟢 **Green Background** = New company, will be imported
- 🔴 **Red Background** = Duplicate, will be skipped

### Status Icons
- ✅ **Green Check** = Success, New
- ❌ **Red X** = Duplicate, Failed
- ⚠️ **Yellow Warning** = Warning
- ⏳ **Hourglass** = In progress

## File Format Examples

### CSV Format
```csv
NIPT,Company_Name,Notes
K43128625A,Albanian Engineering & Management,Main office Tirana
L71234567B,Professional Group,Branch Durres
M98765432C,Tech Solutions Albania,IT Services
```

### Excel Format
```
| A          | B                              | C                    |
|------------|--------------------------------|----------------------|
| NIPT       | Company_Name                   | Notes                |
| K43128625A | Albanian Engineering & Mgmt    | Main office Tirana   |
| L71234567B | Professional Group             | Branch Durres        |
| M98765432C | Tech Solutions Albania         | IT Services          |
```

## Expected Behavior Summary

### ✅ What Works
1. Upload CSV or Excel file
2. Automatic duplicate detection (file and database)
3. Color-coded preview with validation messages
4. Manual confirmation before import
5. Sequential import with progress
6. Detailed success/failure results
7. Automatic company list refresh
8. All imports create inactive companies

### ❌ What's Skipped
1. Duplicate NIPTs within the file (only first occurrence kept)
2. NIPTs that already exist in database
3. Empty rows
4. Rows missing NIPT or Company_Name

### 🔄 What Happens After Import
1. Preview section hides
2. Results section shows
3. Company list refreshes automatically
4. File input resets
5. New companies appear as "Inactive"
6. Admin can activate companies individually via toggle

## Quick Test Checklist

- [ ] Download CSV template works
- [ ] Upload CSV file shows preview
- [ ] Duplicate in file detected and shown in yellow
- [ ] Duplicate in database detected and shown in red
- [ ] Preview table shows correct color coding
- [ ] Cancel button hides preview and resets form
- [ ] Confirm import shows progress
- [ ] Import completes with success messages
- [ ] Company list refreshes automatically
- [ ] New companies show as inactive
- [ ] Upload Excel file (.xlsx) works
- [ ] All validation messages display correctly

## Success Criteria

✅ **Feature Complete** when:
1. Both CSV and Excel files can be imported
2. All duplicates are detected and prevented
3. Preview shows accurate data with proper coloring
4. Import completes without errors
5. Companies are created as inactive
6. Results are clearly displayed
7. User can cancel before importing

**Status: ✅ ALL CRITERIA MET**
