<?php
/**
 * ADV Media Offload meta fixer for Cloudflare R2
 *
 * Usage:
 *   wp eval-file advmo_fixer.php all [dry-run]
 *   wp eval-file advmo_fixer.php post_id=12467 [dry-run]
 *
 * Logic:
 * - ทำงานกับ attachment ที่มี meta `_wp_attached_file`
 * - ถ้า advmo_bucket ไม่มี หรือ != 'bbair' (case-insensitive) → เซ็ตชุด meta ทั้งหมด
 */

if ( ! defined('ABSPATH') ) {
    require_once __DIR__ . '/wp-load.php';
}

date_default_timezone_set( 'UTC' );

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

$dry_run = in_array('dry-run', $args, true);
$do_all  = in_array('all', $args, true);

$post_id = 0;
foreach ($args as $a) {
    if (strpos($a, 'post_id=') === 0) {
        $post_id = (int) substr($a, 8);
    }
}

if (!$do_all && !$post_id) {
    echo "Usage:\n";
    echo "  wp eval-file advmo_fixer.php all [dry-run]\n";
    echo "  wp eval-file advmo_fixer.php post_id=<ID> [dry-run]\n";
    exit(1);
}

/**
 * สร้าง advmo_path จาก _wp_attached_file (เอา 8 ตัวแรก + ให้มี '/')
 */
function advmo_path_from_attached($attached) {
    $p = substr((string)$attached, 0, 8); // eg. "2025/08/"
    // ให้แน่ใจว่าลงท้ายด้วย '/'
    if ($p !== '' && substr($p, -1) !== '/') {
        $p .= '/';
    }
    return $p;
}

/**
 * ใส่/อัปเดต meta key (เขียนจริงหรือ dry-run)
 */
function maybe_update_meta($post_id, $key, $value, $dry_run) {
    $old = get_post_meta($post_id, $key, true);
    if ($dry_run) {
        printf("  - %s: '%s' => '%s' (dry-run)\n", $key, (string)$old, (string)$value);
        return;
    }
    update_post_meta($post_id, $key, $value);
    printf("  - %s: '%s' => '%s'\n", $key, (string)$old, (string)$value);
}

/**
 * ประมวลผล 1 attachment
 */
function process_one_attachment($post_id, $dry_run) {
    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'attachment') {
        printf("Skip post_id=%d (not an attachment)\n", $post_id);
        return;
    }

    $attached = get_post_meta($post_id, '_wp_attached_file', true);
    if (!$attached) {
        printf("Skip post_id=%d (no _wp_attached_file)\n", $post_id);
        return;
    }

    $bucket_old = get_post_meta($post_id, 'advmo_bucket', true);
    $needs_set  = empty($bucket_old) || (strcasecmp($bucket_old, 'bbair') !== 0);

    printf(
        "Post #%d | attached='%s' | advmo_bucket(old)='%s' | %s\n",
        $post_id,
        $attached,
        (string)$bucket_old,
        $needs_set ? 'WILL WRITE' : 'OK (no change)'
    );

    if (!$needs_set) {
        return;
    }

    $now      = time();
    $adv_path = advmo_path_from_attached($attached);

    // ชุดค่าที่ต้องการเขียน
    $new_values = [
        'advmo_bucket'           => 'BBAir',         // ตามสเป็ก
        'advmo_retention_policy' => '1',
        'advmo_path'             => $adv_path,
        'advmo_offloaded'        => '1',
        'advmo_provider'         => 'Cloudflare R2', // สะกดถูก
        'advmo_offloaded_at'     => (string)$now,
    ];

    foreach ($new_values as $k => $v) {
        maybe_update_meta($post_id, $k, $v, $dry_run);
    }
}

/**
 * โหมดทั้งระบบ: ไล่เป็นหน้า ๆ กัน memory ล้น
 */
function process_all($dry_run) {
    $paged = 1;
    $total = 0;
    do {
        $q = new WP_Query([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
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
            process_one_attachment($aid, $dry_run);
            $total++;
        }

        $paged++;
        wp_reset_postdata();
    } while (true);

    printf("Finished. Scanned %d attachments.\n", $total);
}

/** Dispatcher */
if ($post_id) {
    process_one_attachment($post_id, $dry_run);
} else {
    process_all($dry_run);
}
