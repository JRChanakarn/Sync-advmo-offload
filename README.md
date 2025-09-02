# ADV Media Offload Meta Fixer (`advmo_fixer.php`)

## Overview

This script is a **WordPress CLI utility** that repairs or enforces the required metadata for media attachments stored in **Cloudflare R2** via the ADV Media Offload plugin.

It ensures that every media attachment has the correct **advmo\_\*** fields, specifically fixing or inserting entries where `advmo_bucket` is missing or not equal to `bbair`.

---

## Features

- Works on **WordPress attachments** that have `_wp_attached_file` metadata.
- Checks if `advmo_bucket` is set to `bbair` (case-insensitive).
- If missing/incorrect, automatically sets the following metadata:
  - `advmo_bucket = BBAir`
  - `advmo_retention_policy = 1`
  - `advmo_path = first 8 characters of _wp_attached_file`
  - `advmo_offloaded = 1`
  - `advmo_provider = Cloudflare R2`
- Supports **dry-run mode** (no database changes).

---

## Requirements

- WordPress installation with WP-CLI available.
- Access to the database where media attachments are stored.
- PHP CLI enabled.

---

## Usage

### Run for All Attachments

```bash
wp eval-file advmo_fixer.php all
```

### Run for a Specific Attachment

```bash
wp eval-file advmo_fixer.php post_id=12467
```

### Dry-Run Mode (Preview Changes)

```bash
wp eval-file advmo_fixer.php all dry-run
```

or

```bash
wp eval-file advmo_fixer.php post_id=12467 dry-run
```

---

## Safety

- Always back up your database before running this script in production.
- Use `dry-run` first to confirm what changes will be applied.
