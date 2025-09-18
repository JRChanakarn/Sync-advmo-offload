# ADV Media Offload Meta Fixer (`advmo_fixer.php`)

## Overview

This script is a **WordPress CLI utility** that repairs or enforces the required metadata for media attachments stored in **Cloudflare R2** via the ADV Media Offload plugin.

It ensures that every media attachment has the correct **advmo\_\*** fields, fixing or inserting entries where metadata is missing, incorrect, or forcefully rewritten (via the `replace` flag) to **your bucket name** (`BBAIR` in this example).

---

## Features

- Works on **WordPress attachments** that have `_wp_attached_file` metadata.
- Checks if `advmo_bucket` is set to your bucket (`BBAIR`, case-insensitive).
- Normalizes `advmo_path` to the `year/month/` prefix derived from original attachment metadata (falls back to `_wp_attached_file`).
- Optional **fix-path** mode rewrites `_wp_attached_file` (and attachment metadata) so the stored path matches `year/month/filename.ext`.
- If missing/incorrect (or when run with `replace`), automatically sets the following metadata:
  - `advmo_bucket = <YourBucketName>` (example: `BBAIR`)
  - `advmo_retention_policy = 1`
  - `advmo_path = year/month/` (derived from attachment metadata, fallback to `_wp_attached_file`)
  - `advmo_offloaded = 1`
  - `advmo_provider = Cloudflare R2`
- Supports **dry-run mode** (no database changes).
- Supports a **replace mode** that rewrites the metadata even when it already matches.

---

## Requirements

- WordPress installation with WP-CLI available.
- Access to the database where media attachments are stored.
- PHP CLI enabled.

---

## Usage

### Run for All Attachments

```bash
wp eval-file advmo_fixer.php all [dry-run] [replace] [fix-path] [<limit>]
```

### Run for a Specific Attachment

```bash
wp eval-file advmo_fixer.php post_id=12467 [dry-run] [replace] [fix-path]
```

### Dry-Run Mode (Preview Changes)

```bash
wp eval-file advmo_fixer.php all dry-run
```

or

```bash
wp eval-file advmo_fixer.php post_id=12467 dry-run
```

> Tips:
> - ใส่ `fix-path` เพื่อบังคับให้ `_wp_attached_file` และ `_wp_attachment_metadata[file]` กลับมาอยู่ที่ `ปี/เดือน/ไฟล์`
> - ใส่ตัวเลขท้ายคำสั่งเพื่อจำกัดจำนวนที่ประมวลผล เช่น `wp eval-file advmo_fixer.php all dry-run replace fix-path 10`

---

## Safety

- Always back up your database before running this script in production.
- Use `dry-run` first to confirm what changes will be applied.

---
