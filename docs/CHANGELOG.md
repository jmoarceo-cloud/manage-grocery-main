# Changelog

Current work is recorded in [root CHANGELOG.md](../CHANGELOG.md). This file
preserves historical release notes. The [2026-10-26 audit](AUDIT.md) qualifies
older web-root/CSRF/readiness claims; they were not freshly verified here.

All notable changes to the Manage-Grocery (GroceryOne) project are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

---

## [1.2.0] - 2026-10-25

### Added
- **Modernized Directory Architecture**: Transitioned application structure to an enterprise MVC layout with isolated `public/` web root, `app/` core/controllers/models/views, `config/`, and `storage/`.
- **System Documentation Suite**: Created comprehensive 10-document `/docs` pack covering architecture, privacy governance (RA 10173), database dictionary, install guide, and QA test matrices.
- **FIFO Batch Consumption**: Hardened `sale_batch_consumption` table to track lot depletion per cashier checkout.
- **Geofenced Attendance Verification**: Integrated Haversine formula calculation in employee time-in.

### Security
- Isolated `.env` credentials and database `.sql` schemas from direct HTTP webroot access.
- Moved runtime uploads (`storage/uploads/`) out of public directories with script execution deny rules.
- Enforced anti-CSRF token verification across all operational POST actions.

### Removed
- Removed legacy duplicate files (`project/HRMS/login1.php`).
- Cleaned up stray repository artifacts (`project/Attached Element Context from Integrated.txt`).

---

## [1.1.0] - 2026-10-03

### Added
- Procurement digital signature verification for store manager sign-offs.
- Multi-tier purchase order receipt matching against delivered supplier items.

---

## [1.0.0] - 2026-09-20

### Added
- Initial integrated baseline release of Grocery POS and HRMS modules on local XAMPP.
- 42-table `grocery_pos` schema and 35-table `hrms` schema.

