<?php
// admin/ads_settings.php - Manage advertisement code snippets
session_start();

// Check if logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$adsPath = __DIR__ . '/../config/ads.json';

function zp_read_ads($path) {
    $defaults = [
        'home_after_viewall_movies' => '',
        'home_after_viewall_tv'     => '',
        'watch_after_related'       => '',
        'movie_card_ad_1'           => '',
        'movie_card_ad_2'           => '',
        
    ];
    if (!file_exists($path)) {
        @file_put_contents($path, json_encode($defaults, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $defaults;
    }
    $raw = @file_get_contents($path);
    $data = $raw ? json_decode($raw, true) : null;
    if (!is_array($data)) $data = [];
    return array_merge($defaults, $data);
}

$ads = zp_read_ads($adsPath);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ads['home_after_viewall_movies'] = $_POST['home_after_viewall_movies'] ?? '';
    $ads['home_after_viewall_tv']     = $_POST['home_after_viewall_tv'] ?? '';
    $ads['watch_after_related']       = $_POST['watch_after_related'] ?? '';

    $ads['movie_card_ad_1']           = $_POST['movie_card_ad_1'] ?? '';
    $ads['movie_card_ad_2']           = $_POST['movie_card_ad_2'] ?? '';
        
    // Save as JSON (allows any HTML/JS code without breaking PHP parsing)
    file_put_contents(
        $adsPath,
        json_encode($ads, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
    $saved = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Ads Settings</title>
    <style>
        body{font-family:Arial, sans-serif;background:#111;color:#fff;padding:20px}
        .wrap{max-width:980px;margin:0 auto}
        .topbar{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
        a{color:#4ecdc4;text-decoration:none}
        textarea{width:100%;min-height:160px;padding:12px;border-radius:10px;border:1px solid rgba(255,255,255,0.18);background:rgba(255,255,255,0.06);color:#fff;font-size:14px;line-height:1.4}
        .card{background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.12);padding:16px;border-radius:14px;margin-top:16px}
        .label{font-weight:700;margin-bottom:8px}
        .hint{color:#bbb;font-size:13px;margin:6px 0 12px}
        button{padding:10px 16px;border:none;border-radius:10px;background:#ff4b5c;color:#fff;font-weight:700;cursor:pointer}
        button:hover{opacity:0.92}
        .ok{color:#7CFC90;margin-top:12px}
        .warn{color:#ffd166;font-size:13px;margin-top:10px}
    </style>
</head>
<body>
    <div class="wrap">
        <div class="topbar">
            <h2 style="margin:0;">🧩 Advertisement Settings</h2>
            <div style="display:flex;gap:12px;align-items:center;">
                <a href="dashboard.php">← Back to Dashboard</a>
                <a href="dashboard.php?logout=1">Logout</a>
            </div>
        </div>

        <?php if(!empty($saved)): ?>
            <div class="ok">Saved successfully ✅</div>
        <?php endif; ?>

        <div class="warn">
            Note: This will be rendered as-is on the public site. Only paste trusted ad code.
        </div>

        <form method="post">
            <div class="card">
                <div class="label">1) Homepage - After “View All Movies” button</div>
                <div class="hint">Placement: below the “View All Movies” button on the homepage.</div>
                <textarea name="home_after_viewall_movies" placeholder="Paste your ad code here..."><?= htmlspecialchars($ads['home_after_viewall_movies']) ?></textarea>
            </div>

            <div class="card">
                <div class="label">2) Homepage - After “View All TV Shows” button</div>
                <div class="hint">Placement: below the “View All TV Shows” button on the homepage.</div>
                <textarea name="home_after_viewall_tv" placeholder="Paste your ad code here..."><?= htmlspecialchars($ads['home_after_viewall_tv']) ?></textarea>
            </div>

            <div class="card">
                <div class="label">3) Watch Page - After “Related Movies 🔥” heading</div>
                <div class="hint">Placement: on https://zenktx.online/watch, right under the Related section title.</div>
                <textarea name="watch_after_related" placeholder="Paste your ad code here..."><?= htmlspecialchars($ads['watch_after_related']) ?></textarea>
            </div>

            <div class="card">
    <div class="label">4) Movies/TV Grid - Random Ad #1 (inside movie cards)</div>
    <div class="hint">Placement: inserted inside the movie-card grid on /movies and /tv (shows only 2 times).</div>
    <textarea name="movie_card_ad_1" placeholder="Paste your ad code here..."><?= htmlspecialchars($ads['movie_card_ad_1']) ?></textarea>
</div>

<div class="card">
    <div class="label">5) Movies/TV Grid - Random Ad #2 (inside movie cards)</div>
    <div class="hint">Placement: inserted inside the movie-card grid on /movies and /tv (shows only 2 times).</div>
    <textarea name="movie_card_ad_2" placeholder="Paste your ad code here..."><?= htmlspecialchars($ads['movie_card_ad_2']) ?></textarea>
</div>

<div style="margin-top:16px;">
                <button type="submit">Save Ads</button>
            </div>
        </form>
    </div>
</body>
</html>
