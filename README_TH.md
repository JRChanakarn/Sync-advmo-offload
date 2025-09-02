# ตัวแก้ไข Meta ของ ADV Media Offload (`advmo_fixer.php`)

## ภาพรวม

สคริปต์นี้เป็น **เครื่องมือ WP-CLI** สำหรับซ่อมแซมหรือกำหนดค่าเมทาดาทาที่จำเป็นของไฟล์สื่อ (Media Attachments) ที่เก็บไว้ใน **Cloudflare R2** ผ่านปลั๊กอิน ADV Media Offload

โดยจะตรวจสอบให้แน่ใจว่าไฟล์แนบทุกไฟล์มีค่าของ **advmo\_\*** ครบถ้วน โดยเฉพาะเมื่อ `advmo_bucket` ไม่มีหรือไม่เท่ากับ `bbair`

---

## คุณสมบัติ

- ทำงานกับ **ไฟล์แนบของ WordPress** ที่มีเมทาดาทา `_wp_attached_file`
- ตรวจสอบว่า `advmo_bucket` ถูกตั้งค่าเป็น `bbair` (ไม่สนตัวพิมพ์เล็ก-ใหญ่)
- ถ้าไม่มี/ไม่ถูกต้อง จะกำหนดค่าใหม่ดังนี้:
  - `advmo_bucket = BBAir`
  - `advmo_retention_policy = 1`
  - `advmo_path = ตัวอักษร 8 ตัวแรกของ _wp_attached_file`
  - `advmo_offloaded = 1`
  - `advmo_provider = Cloudflare R2`
- รองรับโหมด **dry-run** (ไม่แก้ไขข้อมูลจริง)

---

## ความต้องการ

- WordPress ที่สามารถใช้งาน WP-CLI ได้
- การเข้าถึงฐานข้อมูลที่เก็บไฟล์แนบ
- ใช้งานผ่าน PHP CLI

---

## วิธีใช้งาน

### รันสำหรับไฟล์แนบทั้งหมด

```bash
wp eval-file advmo_fixer.php all
```

### รันสำหรับไฟล์แนบเฉพาะ (post_id)

```bash
wp eval-file advmo_fixer.php post_id=12467
```

### โหมด Dry-Run (แสดงผลลัพธ์ แต่ไม่แก้ไขจริง)

```bash
wp eval-file advmo_fixer.php all dry-run
```

หรือ

```bash
wp eval-file advmo_fixer.php post_id=12467 dry-run
```

---

## คำเตือน

- ควรสำรองฐานข้อมูลก่อนรันสคริปต์นี้ทุกครั้ง
- แนะนำให้ใช้ `dry-run` ก่อน เพื่อตรวจสอบผลลัพธ์ที่จะเกิดขึ้น
