<?php

/**
 * ADV Media Offload meta fixer for Cloudflare R2
 *
 * Usage:
 *   wp eval-file advmo_fixer.php all [dry-run] [replace] [fix-path] [<limit>]
 *   wp eval-file advmo_fixer.php post_id=12467 [dry-run] [replace] [fix-path]
 *
 * Logic:
 * - ทำงานกับ attachment ที่มี meta `_wp_attached_file`
 * - ถ้า advmo_bucket ไม่มี หรือ != 'bbair' (case-insensitive) → เซ็ตชุด meta ทั้งหมด
 * - ใส่ `replace` เพื่อบังคับเขียนค่าใหม่ทั้งหมดแม้ค่าปัจจุบันจะตรงอยู่แล้ว
 * - ใส่ `fix-path` เมื่ออยาก normalize `_wp_attached_file` และ metadata ให้เหลือ `YYYY/MM/filename`
 */

if (! defined('ABSPATH')) {
    require_once __DIR__ . '/wp-load.php';
}

date_default_timezone_set('UTC');

global $argv;
$args = $argv;

/**
 * ดึงอาร์กิวเมนต์หลังชื่อไฟล์จริง ๆ (กัน path ต่างกัน)
 */
$scriptPos = null;
foreach ($args as $i => $token) {
    if (substr($token, -strlen(basename(__FILE__))) === basename(__FILE__)) {
        $scriptPos = $i;
        break;
    }
}
if ($scriptPos !== null) {
    $args = array_slice($args, $scriptPos + 1);
} else {
    // fallback
    $args = array_slice($args, 1);
}

$dry_run  = in_array('dry-run', $args, true);
$do_all   = in_array('all', $args, true);
$replace  = in_array('replace', $args, true);
$fix_path = in_array('fix-path', $args, true);
$limit   = 0;

$post_id = 0;
foreach ($args as $a) {
    if (strpos($a, 'post_id=') === 0) {
        $post_id = (int) substr($a, 8);
        continue;
    }

    if (ctype_digit($a)) {
        $limit = (int) $a;
    }
}

if ($limit > 0 && !$post_id) {
    $do_all = true;
}

if (!$do_all && !$post_id) {
    echo "Usage:\n";
    echo "  wp eval-file advmo_fixer.php all [dry-run] [replace] [fix-path] [<limit>]\n";
    echo "  wp eval-file advmo_fixer.php post_id=<ID> [dry-run] [replace] [fix-path]\n";
    exit(1);
}

function advmo_path_from_attachment($post_id, $attached)
{
    $paths = [];

    $meta = wp_get_attachment_metadata($post_id);
    if (is_array($meta) && !empty($meta['file'])) {
        $paths[] = (string) $meta['file'];
    }

    $paths[] = (string) $attached;

    foreach ($paths as $path) {
        $path = ltrim($path, '/');
        if ($path === '') {
            continue;
        }

        if (preg_match('#^(\d{4}/\d{2})/#', $path, $m)) {
            return $m[1] . '/';
        }

        $lastSlash = strrpos($path, '/');
        if ($lastSlash !== false) {
            $dir      = substr($path, 0, $lastSlash + 1);
            $basename = basename($path);

            if ($basename !== '' && substr($dir, - (strlen($basename) + 1)) === $basename . '/') {
                $dir = substr($dir, 0, - (strlen($basename) + 1));
                if ($dir !== '' && substr($dir, -1) !== '/') {
                    $dir .= '/';
                }
            }

            return $dir;
        }
    }

    return '';
}

/**
 * ใส่/อัปเดต meta key (เขียนจริงหรือ dry-run)
 */
function maybe_update_meta($post_id, $key, $value, $dry_run)
{
    $old = get_post_meta($post_id, $key, true);
    if ($dry_run) {
        printf("  - %s: '%s' => '%s' (dry-run)\n", $key, (string)$old, (string)$value);
        return;
    }
    update_post_meta($post_id, $key, $value);
    printf("  - %s: '%s' => '%s'\n", $key, (string)$old, (string)$value);
}

/**
 * อัปเดตค่า file ใน attachment metadata (ถ้ามี)
 */
function maybe_update_attachment_metadata_file($post_id, $meta, $new_file, $dry_run)
{
    $old_file = '';
    if (is_array($meta) && isset($meta['file'])) {
        $old_file = (string)$meta['file'];
    }

    if ($old_file === $new_file) {
        return false;
    }

    if ($dry_run) {
        printf("  - _wp_attachment_metadata[file]: '%s' => '%s' (dry-run)\n", $old_file, $new_file);
        return true;
    }

    if (!is_array($meta)) {
        $meta = [];
    }

    $meta['file'] = $new_file;
    wp_update_attachment_metadata($post_id, $meta);
    printf("  - _wp_attachment_metadata[file]: '%s' => '%s'\n", $old_file, $new_file);
    return true;
}

/**
 * ประมวลผล 1 attachment
 */
function process_one_attachment($post_id, $dry_run, $replace, $fix_path)
{
    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'attachment') {
        printf("Skip post_id=%d (not an attachment)\n", $post_id);
        return 'skip_not_attachment';
    }

    $attached = get_post_meta($post_id, '_wp_attached_file', true);
    if (!$attached) {
        printf("Skip post_id=%d (no _wp_attached_file)\n", $post_id);
        return 'skip_no_meta';
    }

    $bucket_old = get_post_meta($post_id, 'advmo_bucket', true);
    $now        = time();
    $adv_path   = advmo_path_from_attachment($post_id, $attached);
    $meta       = wp_get_attachment_metadata($post_id);
    $meta_file  = (is_array($meta) && isset($meta['file'])) ? (string)$meta['file'] : '';

    $targets = [
        'advmo_bucket'           => 'BBAir',         // ตามสเป็ก bucket
        'advmo_retention_policy' => '1',
        'advmo_path'             => $adv_path,
        'advmo_offloaded'        => '1',
        'advmo_provider'         => 'Cloudflare R2', // สะกดถูก
    ];

    $changes     = [];
    $has_updates = false;

    foreach ($targets as $key => $desired) {
        $current = get_post_meta($post_id, $key, true);
        $match   = ($key === 'advmo_bucket')
            ? (strcasecmp((string)$current, $desired) === 0)
            : ((string)$current === (string)$desired);

        if ($replace || !$match) {
            $changes[$key] = $desired;
        }
    }

    $current_offloaded_at = get_post_meta($post_id, 'advmo_offloaded_at', true);
    if ($replace || !ctype_digit((string)$current_offloaded_at) || (int)$current_offloaded_at <= 0) {
        $changes['advmo_offloaded_at'] = (string)$now;
    }

    $desired_attached     = $adv_path . basename($attached);
    $desired_meta_file    = $adv_path . basename($meta_file !== '' ? $meta_file : $attached);
    $will_change_attached = $fix_path && ($desired_attached !== $attached);
    $will_change_meta     = $fix_path && ($desired_meta_file !== $meta_file);

    printf(
        "Post #%d | attached='%s' | advmo_bucket(old)='%s' | %s\n",
        $post_id,
        $attached,
        (string)$bucket_old,
        (empty($changes) && !$will_change_attached && !$will_change_meta)
            ? 'OK (no change)'
            : sprintf(
                'WILL WRITE (%d fields)%s%s',
                count($changes) + ($will_change_attached ? 1 : 0) + ($will_change_meta ? 1 : 0),
                $replace ? ' [replace]' : '',
                $fix_path ? ' [fix-path]' : ''
            )
    );

    if (!empty($changes)) {
        foreach ($changes as $k => $v) {
            maybe_update_meta($post_id, $k, $v, $dry_run);
        }
        $has_updates = true;
    }

    if ($fix_path) {
        if ($will_change_attached) {
            maybe_update_meta($post_id, '_wp_attached_file', $desired_attached, $dry_run);
            $attached     = $desired_attached;
            $has_updates  = true;
        }

        if ($will_change_meta) {
            if (maybe_update_attachment_metadata_file($post_id, $meta, $desired_meta_file, $dry_run)) {
                $has_updates = true;
            }
        } elseif ($meta_file === '' && $attached !== '') {
            // ไม่มี metadata เดิม → สร้างใหม่ให้ตรงกับไฟล์ที่ normalize แล้ว
            if (maybe_update_attachment_metadata_file($post_id, $meta, $desired_attached, $dry_run)) {
                $has_updates = true;
            }
        }

        // รีเฟรชค่าที่ใช้ภายนอก
        $meta_file = $desired_meta_file;
        if ($will_change_attached) {
            $attached = $desired_attached;
        }

    }

    if ($has_updates) {
        return 'updated';
    }

    return 'no_change';
}

/**
 * โหมดทั้งระบบ: ไล่เป็นหน้า ๆ กัน memory ล้น
 */
function process_all($dry_run, $replace, $fix_path, $limit = 0)
{
    $paged = 1;
    $total = 0;
    $stats = [
        'updated'             => 0,
        'no_change'           => 0,
        'skip_no_meta'        => 0,
        'skip_not_attachment' => 0,
    ];
    $should_break = false;

    do {
        $q = new WP_Query([
            'post_type'      => 'attachment',
            'post_status'    => 'any',
            'posts_per_page' => 500,
            'paged'          => $paged,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'     => '_wp_attached_file',
                    'compare' => 'EXISTS',
                ],
            ],
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ]);

        if (!$q->have_posts()) {
            break;
        }

        foreach ($q->posts as $aid) {
            $result = process_one_attachment($aid, $dry_run, $replace, $fix_path);
            if (isset($stats[$result])) {
                $stats[$result]++;
            }
            $total++;

            if ($limit > 0 && $total >= $limit) {
                $should_break = true;
                break;
            }
        }

        $paged++;
        wp_reset_postdata();
    } while (!$should_break);

    printf(
        "Finished. Scanned %d attachments. Updated=%d, no-change=%d, skipped(no _wp_attached_file)=%d, skipped(not attachment)=%d.\n",
        $total,
        $stats['updated'],
        $stats['no_change'],
        $stats['skip_no_meta'],
        $stats['skip_not_attachment']
    );
}

/** Dispatcher */
if ($post_id) {
    process_one_attachment($post_id, $dry_run, $replace, $fix_path);
} else {
    process_all($dry_run, $replace, $fix_path, $limit);
}
