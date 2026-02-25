# Database Schema Updates

## Changes Made

### 1. Removed `transaction_category` Column
- **Migration**: `2026_02_23_000001_remove_transaction_category_from_transactions.php`
- **Reason**: Redundant - `trans_method` already contains DEPOSIT/WITHDRAWAL/TRANSFER
- **Impact**: Use `trans_method` to determine transaction type instead

### 2. Updated `trans_type` Values
- **Old Values**: `CASH | CHEQUE | DEPOSIT_SLIP | INTERNAL`
- **New Values**: `CHEQUE | DEPOSIT_SLIP | CASH_DEPOSIT | CHEQUE_DEPOSIT | BANK_TRANSFER | OTHER`
- **Note**: Only `CHEQUE` and `DEPOSIT_SLIP` support instrument numbers

### 3. Updated `instrument_type` Values
- **Old Values**: `CASH | CHEQUE | DEPOSIT_SLIP | INTERNAL`
- **New Values**: `CHEQUE | DEPOSIT_SLIP` (only these two types have instrument numbers)

## Updated Table Structure

```sql
Table transactions {
  id bigint [pk, increment]

  voucher_no varchar(100)
  voucher_date date

  trans_method varchar(30) [not null]  // DEPOSIT | WITHDRAWAL | TRANSFER
  trans_type varchar(30) [not null]    // CHEQUE | DEPOSIT_SLIP | CASH_DEPOSIT | CHEQUE_DEPOSIT | BANK_TRANSFER | OTHER

  from_owner_id bigint [ref: > owners.id]
  to_owner_id bigint [ref: > owners.id]

  unit_id bigint [ref: > units.id]

  amount decimal(11,2) [not null, default: 0.00]  // Max: 999,999,999.99

  fund_reference varchar(255)
  particulars text

  transfer_group_id bigint

  person_in_charge varchar(255)

  status varchar(20) [not null, default: 'ACTIVE']
  is_posted boolean [not null, default: false]
  posted_at timestamp

  created_by bigint [ref: > users.id]

  created_at timestamp
  updated_at timestamp

  indexes {
    (trans_method)
    (trans_type)
    (from_owner_id)
    (to_owner_id)
    (status)
    (created_at)
    (is_posted)
  }
}

Table transaction_instruments {
  id bigint [pk, increment]

  transaction_id bigint [not null, ref: > transactions.id]

  instrument_type varchar(30) [not null]  // CHEQUE | DEPOSIT_SLIP (only)
  instrument_no varchar(255)
  notes text

  created_at timestamp
  updated_at timestamp

  indexes {
    (transaction_id)
  }
}

Table transaction_attachments {
  id bigint [pk, increment]

  transaction_id bigint [not null, ref: > transactions.id]

  file_name varchar(255) [not null]
  file_type varchar(100)
  file_path varchar(500) [not null]

  created_at timestamp
  updated_at timestamp

  indexes {
    (transaction_id)
  }
}
```

## Backend Code Changes

### TransactionController.php
- Removed `transaction_category` from Transaction::create()
- Updated `createLedgerEntries()` to use `trans_method` instead of `transaction_category`

### OwnerController.php
- Removed `transaction_category` from opening transaction creation
- Updated `postTransaction()` to detect opening transactions by checking:
  - `trans_method === 'TRANSFER'` AND
  - `fromOwner->owner_type === 'SYSTEM'`
- Changed opening transaction `trans_type` from `INTERNAL` to `OTHER`

### Transaction.php Model
- Removed `transaction_category` from `$fillable` array

## Migration Steps

1. Run the migration to remove `transaction_category`:
   ```bash
   php artisan migrate
   ```

2. Verify the column is removed:
   ```sql
   DESCRIBE transactions;
   ```

3. Update any existing queries that reference `transaction_category` to use `trans_method` instead.

## Notes

- Opening transactions are identified by: `trans_method = 'TRANSFER'` AND `from_owner.owner_type = 'SYSTEM'`
- Regular deposits/withdrawals use: `trans_method = 'DEPOSIT'` or `'WITHDRAWAL'`
- Only CHEQUE and DEPOSIT_SLIP transaction types create instrument records
