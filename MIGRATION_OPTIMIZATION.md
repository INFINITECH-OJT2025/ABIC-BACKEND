# Migration Optimization Guide

## Overview
This document explains the migration consolidation that was performed to optimize duplicate migrations.

## Consolidated Migrations

### 1. Bank Contacts (3 migrations → 1)
**Original migrations:**
- `2026_02_17_000001_create_bank_contacts_table.php` - Created table
- `2026_02_17_000002_update_bank_contacts_table.php` - Added viber, changed nullable fields
- `2026_02_17_000003_restructure_bank_contacts.php` - Created bank_contact_channels, removed phone/email/viber, added notes

**Consolidated to:**
- `2026_02_17_000001_create_bank_contacts_table_consolidated.php` - Single migration with final structure

**Final structure:**
- `bank_contacts` table with: bank_id, branch_name (required), contact_person (nullable), position (nullable), notes (nullable)
- `bank_contact_channels` table for storing contact methods (phone, email, viber, etc.)

### 2. Transaction Attachments (2 migrations → 1)
**Original migrations:**
- `2026_02_20_000003_create_transaction_attachments_table.php` - Created with VARCHAR(500) for file_path
- `2026_02_23_021724_increase_file_path_length_for_firebase_urls.php` - Changed file_path to TEXT

**Consolidated to:**
- `2026_02_20_000003_create_transaction_attachments_table_consolidated.php` - Single migration with TEXT from start

**Final structure:**
- `file_path` column is TEXT (not VARCHAR) to accommodate Firebase Storage URLs

### 3. Saved Receipts (2 migrations → 1)
**Original migrations:**
- `2026_02_21_000001_create_saved_receipts_table.php` - Created with VARCHAR for file_path
- `2026_02_23_021724_increase_file_path_length_for_firebase_urls.php` - Changed file_path to TEXT

**Consolidated to:**
- `2026_02_21_000001_create_saved_receipts_table_consolidated.php` - Single migration with TEXT from start

**Final structure:**
- `file_path` column is TEXT (not VARCHAR) to accommodate Firebase Storage URLs

## Migration Files to Delete

After verifying the consolidated migrations work correctly, you can delete these duplicate files:

### Bank Contacts:
- `database/migrations/2026_02_17_000002_update_bank_contacts_table.php`
- `database/migrations/2026_02_17_000003_restructure_bank_contacts.php`

### Transaction Attachments:
- `database/migrations/2026_02_23_021724_increase_file_path_length_for_firebase_urls.php` (if only used for transaction_attachments)

### Saved Receipts:
- The `increase_file_path_length_for_firebase_urls.php` migration can be deleted if both tables are consolidated

## Important Notes

1. **All migrations have already run** - These consolidated migrations are for future use (fresh installs or rollbacks)
2. **Don't delete old migrations yet** - Keep them until you verify the consolidated versions work
3. **Test on fresh database** - Test the consolidated migrations on a fresh database before deleting old ones
4. **Data migrations are separate** - Migrations like `normalize_owners_status_to_uppercase` are data migrations and should remain separate

## Testing Consolidated Migrations

To test the consolidated migrations:

```bash
# 1. Create a fresh database
php artisan db:wipe

# 2. Run migrations (will use consolidated versions)
php artisan migrate

# 3. Verify tables are created correctly
php artisan tinker
>>> Schema::hasTable('bank_contacts')
>>> Schema::hasTable('bank_contact_channels')
>>> Schema::hasTable('transaction_attachments')
>>> Schema::hasTable('saved_receipts')
```

## Benefits

1. **Cleaner migration history** - Fewer files to maintain
2. **Faster migrations** - Single operation instead of multiple
3. **Easier to understand** - Final structure is clear from the start
4. **Better for new installations** - Fresh installs get the optimized structure immediately

## Migration Status

All original migrations have been run. The consolidated versions are ready for:
- New project installations
- Database rollbacks and re-migrations
- Code review and documentation
