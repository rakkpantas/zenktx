<?php
// admin/dashboard.php - Admin dashboard
session_start();

// Check if logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: login.php');
    exit;
}

// Load movies from JSON
function loadMovies() {
    if (file_exists('../movies.json')) {
        $json = file_get_contents('../movies.json');
        return json_decode($json, true) ?: [];
    }
    return [];
}

// Save movies to JSON
function saveMovies($movies) {
    file_put_contents('../movies.json', json_encode($movies, JSON_PRETTY_PRINT));
}

require_once __DIR__ . '/../lib/requests.php';
require_once __DIR__ . "/../lib/imdb_platform.php";


// Player server base URL settings
$playerServerConfigPath = __DIR__ . '/../config/player_servers.php';
$playerServerConfig = @include $playerServerConfigPath;
if (!is_array($playerServerConfig)) { $playerServerConfig = []; }
$playerServer1Base = rtrim(($playerServerConfig['server1_base'] ?? 'https://vidsrc.me'), '/');
$playerServer2Base = rtrim(($playerServerConfig['server2_base'] ?? 'https://www.vidking.net'), '/');
$playerServer3Base = rtrim(($playerServerConfig['server3_base'] ?? 'https://player.videasy.net'), '/');
$playerServer4Base = rtrim(($playerServerConfig['server4_base'] ?? ''), '/');
$playerServerCustom = $playerServerConfig['custom_servers'] ?? [];
if (!is_array($playerServerCustom)) { $playerServerCustom = []; }

// Handle form submissions
if ($_POST) {
    $movies = loadMovies();
$requests = loadRequests();
cleanupRequests($requests);
// Persist cleanup (auto-delete after 24h)
saveRequests($requests);
$pendingRequests = $requests;

    
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                $imdbId = $_POST['imdb_id'] ?? '';
                $title = $_POST['title'] ?? '';
                $year = $_POST['year'] ?? '';
                $type = $_POST['type'] ?? 'movie';
                $poster = $_POST['poster'] ?? '';
                $rating = $_POST['rating'] ?? '0.0';
                $platform = strtolower(trim($_POST['platform'] ?? 'all'));
                if ($platform === '') $platform = 'all';
                // Auto-detect platform from configured IMDb lists when platform is not set
                if ($platform === 'all') {
                    $cfgPlat = zp_load_platform_lists();
                    $detPlat = zp_detect_platform_for_imdb_id($imdbId, $cfgPlat);
                    if (!empty($detPlat)) $platform = $detPlat;
                }

                if ($imdbId && $title) {

                    // Prevent duplicate entries by IMDb ID
                    $alreadyExists = false;
                    foreach ($movies as $m) {
                        if (isset($m['imdb_id']) && strtolower(trim($m['imdb_id'])) === strtolower(trim($imdbId))) {
                            $alreadyExists = true;
                            break;
                        }
                    }

                    if ($alreadyExists) {
                        $error = 'This IMDb ID already exists. Duplicate entry prevented.';
                        break;
                    }

                    // Always use a unique numeric key (prevents overwrite when there are deleted gaps)
                    $existingKeys = array_map('intval', array_keys($movies));
                    $newKey = (string)(empty($existingKeys) ? 1 : (max($existingKeys) + 1));

                    $movies[$newKey] = [
                        'imdb_id' => $imdbId,
                        'title' => $title,
                        'year' => $year,
                        'type' => $type,
                        'poster' => $poster,
                        'rating' => $rating,
                        'show_in_latest' => false,
                        'latest_order' => 0,
                        'platform' => $platform
                    ];
                    saveMovies($movies);
                    $success = 'Movie/TV series added successfully!';
                }
                break;
                
            
            case 'update_platform':
                $imdbId = $_POST['imdb_id'] ?? '';
                $platform = strtolower($_POST['platform'] ?? 'all');
                if ($imdbId) {
                    foreach ($movies as $k => $m) {
                        if (($m['imdb_id'] ?? '') === $imdbId) {
                            $movies[$k]['platform'] = $platform;
                            break;
                        }
                    }
                    saveMovies($movies);
                }
                break;

            case 'save_player_servers':
                $s1 = trim($_POST['server1_base'] ?? '');
                $s2 = trim($_POST['server2_base'] ?? '');
                $s3 = trim($_POST['server3_base'] ?? '');
                $s4 = trim($_POST['server4_base'] ?? '');
                $customRaw = trim($_POST['custom_servers'] ?? '');

                // Basic sanitize: allow http(s) URLs
                $sanitize = function($u){
                    $u = trim($u);
                    if ($u === '') return '';
                    // prepend https if missing scheme
                    if (!preg_match('#^https?://#i', $u)) $u = 'https://' . $u;
                    // remove trailing slash
                    $u = rtrim($u, '/');
                    // validate
                    if (!filter_var($u, FILTER_VALIDATE_URL)) return '';
                    return $u;
                };

                $s1 = $sanitize($s1) ?: 'https://vidsrc.me';
                $s2 = $sanitize($s2) ?: 'https://www.vidking.net';
                $s3 = $sanitize($s3) ?: 'https://player.videasy.net';
                // Server 4 is optional legacy; keep empty to hide it on the Watch page
                $s4 = $sanitize($s4); // may be ''

                // Custom servers: one URL per line
                $customList = [];
                if ($customRaw !== ''){
                    $lines = preg_split('/\r\n|\r|\n/', $customRaw);
                    foreach ($lines as $ln){
                        $u = $sanitize($ln);
                        if ($u !== '') $customList[] = $u;
                    }
                    // unique + reindex
                    $customList = array_values(array_unique($customList));
                }

                $exportCustom = var_export($customList, true);

                file_put_contents($playerServerConfigPath, "<?php\nreturn [\n  'server1_base' => '".addslashes($s1)."',\n  'server2_base' => '".addslashes($s2)."',\n  'server3_base' => '".addslashes($s3)."',\n  'server4_base' => '".addslashes($s4)."',\n  'custom_servers' => " . $exportCustom . ",\n];\n");

                $playerServer1Base = $s1; 
                $playerServer2Base = $s2; 
                $playerServer3Base = $s3;
                $playerServer4Base = $s4;
                $playerServerCustom = $customList;

                $success = 'Player server addresses updated!';
                break;

            case 'save_platform_lists':
                $platformKeys = ['hulu','netflix','primevideo','disneyplus','vivamax','warnerbros'];
                $cfg = zp_load_platform_lists();
                foreach ($platformKeys as $pk) {
                    $val = trim($_POST['list_' . $pk] ?? '');
                    $cfg[$pk] = ['list_url' => $val];
                }
                zp_save_platform_lists($cfg);
                $success = 'IMDb platform lists saved!';
                break;

            case 'sync_platforms':
                $cfgPlat = zp_load_platform_lists();
                $updatedCount = 0;
                foreach ($movies as $k => $mv) {
                    $cur = strtolower(trim($mv['platform'] ?? 'all'));
                    if ($cur === '' || $cur === 'all') {
                        $det = zp_detect_platform_for_imdb_id($mv['imdb_id'] ?? '', $cfgPlat);
                        if (!empty($det)) {
                            $movies[$k]['platform'] = $det;
                            $updatedCount++;
                        }
                    }
                }
                saveMovies($movies);
                $success = 'Auto platform sync done! Updated: ' . $updatedCount;
                break;

            case 'update_latest':
                $id = $_POST['imdb_id'] ?? '';
                $showInLatest = isset($_POST['show_in_latest']) ? true : false;
                $latestOrder = (int)($_POST['latest_order'] ?? 0);

                // Validation
                if ($showInLatest) {
                    if ($latestOrder < 1 || $latestOrder > 10) {
                        $error = 'Latest Order must be between 1 and 10.';
                        break;
                    }
                } else {
                    $latestOrder = 0;
                }

                // Update the item
                foreach ($movies as &$movie) {
                    if (($movie['imdb_id'] ?? '') === $id) {
                        $movie['show_in_latest'] = $showInLatest;
                        $movie['latest_order'] = $latestOrder;
                        break;
                    }
                }
                unset($movie);

                saveMovies($movies);
                $success = 'Latest settings updated!';
                break;

            case 'update_downloads':
                $imdbId = $_POST['imdb_id'] ?? '';
                if (!$imdbId) break;

                $servers = $_POST['dl_server'] ?? [];
                $passwords    = $_POST['dl_password'] ?? [];
                $quals   = $_POST['dl_quality'] ?? [];
                $urls    = $_POST['dl_url'] ?? [];

                $rows = [];
                for ($i=0; $i<3; $i++) {
                    $s = trim($servers[$i] ?? '');
                    $u = trim($urls[$i] ?? '');
                    $pw = trim($passwords[$i] ?? '');
                    $q = trim($quals[$i] ?? '');

                    // Only save rows that have at least server or url
                    if ($s === '' && $u === '') continue;

                    $rows[] = [
                        'server' => $s !== '' ? $s : ('Server ' . ($i+1)),
                        'password' => $pw !== '' ? $pw : '',
                        'quality' => $q !== '' ? $q : 'HD',
                        'url' => $u
                    ];
                }

                // Update by matching imdb_id (movies.json uses numeric keys)
                foreach ($movies as &$movie) {
                    if (($movie['imdb_id'] ?? '') === $imdbId) {
                        $movie['downloads'] = $rows;
                        break;
                    }
                }
                unset($movie);

                saveMovies($movies);
                $success = 'Download links updated!';
                break;

            case 'remove_latest':
                $id = $_POST['imdb_id'] ?? '';
                if ($id) {
                    foreach ($movies as &$movie) {
                        if (($movie['imdb_id'] ?? '') === $id) {
                            $movie['show_in_latest'] = false;
                            $movie['latest_order'] = 0;
                            break;
                        }
                    }
                    unset($movie);
                    saveMovies($movies);
                    $success = 'Removed from Latest!';
                }
                break;

case 'delete':
                $deleteId = $_POST['delete_id'] ?? ($_POST['imdb_id'] ?? '');
                if ($deleteId) {
                    $movies = array_filter($movies, function($movie) use ($deleteId) {
                        return $movie['imdb_id'] !== $deleteId;
                    });
                    saveMovies($movies);
                    $success = 'Movie/TV series deleted successfully!';
                }
                break;
        }
    }
}

$movies = loadMovies();

$platformLists = zp_load_platform_lists();
$requests = loadRequests();
cleanupRequests($requests);
// Persist cleanup (auto-delete after 24h)
saveRequests($requests);
$pendingRequests = $requests;

?>
<?php
// Logo Upload Function (shared with public site via assets/img/logo_meta.json)
$logoMetaFile = __DIR__ . '/../assets/img/logo_meta.json';
$logoDir = __DIR__ . '/../assets/img/';

// Resolve current logo filename (default logo.png)
$currentLogoFile = 'logo.png';
if (file_exists($logoMetaFile)) {
    $meta = json_decode(file_get_contents($logoMetaFile), true);
    if (is_array($meta) && !empty($meta['file'])) {
        $currentLogoFile = basename($meta['file']);
    }
}
$currentLogoPath = '../assets/img/' . $currentLogoFile;

if (isset($_POST['upload_logo'])) {
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] === 0) {

        if (!file_exists($logoDir)) {
            mkdir($logoDir, 0777, true);
        }

        $fileType = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));

        // Allow only common image formats
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        if (in_array($fileType, $allowed, true)) {

            // Save as logo.<ext> (so file contents match extension)
            $newFileName = 'logo.' . ($fileType === 'jpeg' ? 'jpg' : $fileType);
            $targetFile = $logoDir . $newFileName;

            // Optional cleanup: remove old logo.* files
            foreach (glob($logoDir . 'logo.*') as $old) {
                if (basename($old) !== $newFileName) {
                    @unlink($old);
                }
            }

            if (move_uploaded_file($_FILES['logo']['tmp_name'], $targetFile)) {
                // Update meta so index.php can display it
                file_put_contents($logoMetaFile, json_encode(['file' => $newFileName]));

                // Update current vars for preview without refresh issues
                $currentLogoFile = $newFileName;
                $currentLogoPath = '../assets/img/' . $currentLogoFile;

                echo "<script>alert('Logo updated successfully!');</script>";
            } else {
                echo "<script>alert('Upload failed. Please try again.');</script>";
            }
        } else {
            echo "<script>alert('Invalid file type. Only JPG, PNG, WEBP allowed.');</script>";
        }
    } else {
        echo "<script>alert('Please choose a file.');</script>";
    }
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FlixMo Admin Dashboard</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Arial', sans-serif;
            background: linear-gradient(135deg, #0f0f23, #1a1a2e);
            color: #fff;
            min-height: 100vh;
        }

        .header {
            background: rgba(0, 0, 0, 0.9);
            padding: 1rem 0;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo {
            font-size: 1.5rem;
            color: #ff6b6b;
        }

        .logout-btn {
            background: #ff4444;
            color: #fff;
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 5px;
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .logout-btn:hover {
            background: #ff3333;
        }

        .dashboard-content {
            padding: 2rem 0;
        }

        .section {
            background: rgba(255, 255, 255, 0.1);
            padding: 2rem;
            border-radius: 15px;
            margin-bottom: 2rem;
            backdrop-filter: blur(10px);
        }

        .section h2 {
            margin-bottom: 1rem;
            color: #ff6b6b;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            margin-bottom: 0.5rem;
            color: #ccc;
        }

        .form-group input, .form-group select {
            padding: 0.75rem;
            border: none;
            border-radius: 5px;
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
            font-size: 1rem;
        }

        .form-group input::placeholder {
            color: #999;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .btn-primary {
            background: #ff6b6b;
            color: #fff;
        }

        .btn-primary:hover {
            background: #ff5252;
        }

        .btn-danger {
            background: #ff4444;
            color: #fff;
        }

        .btn-danger:hover {
            background: #ff3333;
        }

        .movies-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1rem;
        }

        .movie-item {
            background: rgba(255, 255, 255, 0.1);
            padding: 1rem;
            border-radius: 10px;
            text-align: center;
        }

        .movie-poster {
            width: 100%;
            height: 250px;
            object-fit: cover;
            border-radius: 10px;
            margin-bottom: 1rem;
        }

        .movie-info {
            margin-bottom: 1rem;
        }

        .movie-title {
            font-size: 1rem;
            margin-bottom: 0.5rem;
        }

        .movie-details {
            font-size: 0.9rem;
            color: #ccc;
        }

        .success {
            background: #4ecdc4;
            color: #fff;
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
            text-align: center;
        }

        .error {
            background: #ff6b6b;
            color: #fff;
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
            text-align: center;
        }

        .imdb-fetch {
            background: #4ecdc4;
            color: #fff;
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            margin-left: 0.5rem;
        }

        .imdb-fetch:hover {
            background: #45b7b8;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.1);
            padding: 1.5rem;
            border-radius: 10px;
            text-align: center;
        }

        .stat-number {
            font-size: 2rem;
            color: #ff6b6b;
            font-weight: bold;
        }

        .stat-label {
            color: #ccc;
            margin-top: 0.5rem;
        }

        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
        }
    
        .alert{padding:12px 14px;border-radius:10px;margin:12px 0;font-weight:600;}
        .alert.success{background:#e8fff1;color:#0b6b2b;border:1px solid #bff3d2;}
        .alert.error{background:#fff0f0;color:#8a1f1f;border:1px solid #f6c2c2;}
        .latest-controls{display:flex;gap:10px;align-items:center;margin-top:10px;flex-wrap:wrap;}
        .latest-controls label{display:flex;gap:8px;align-items:center;font-size:13px;color:#666;}
        .latest-controls input[type="number"]{width:70px;padding:6px 8px;border:1px solid #ddd;border-radius:8px;}
        .btn-small{padding:8px 12px;border:none;border-radius:10px;background:#667eea;color:#fff;cursor:pointer;font-weight:700;font-size:13px;}
        .btn-small:hover{background:#5a67d8;}
    
        .latest-preview{margin:20px 0;padding:18px;border-radius:16px;background:#f8fafc;border:1px solid #e5e7eb;}
        .latest-preview h2{margin:0 0 6px 0;}
        .latest-preview .hint{margin:0 0 14px 0;color:#6b7280;font-size:13px;}
        .preview-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
        @media (max-width: 900px){.preview-grid{grid-template-columns:1fr;}}
        .preview-box{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:14px;}
        .preview-box h3{margin:0 0 10px 0;font-size:16px;}
        .preview-list{margin:0;padding-left:0;list-style:none;display:flex;flex-direction:column;gap:8px;}
        .preview-list li{display:flex;gap:10px;align-items:baseline;}
        .preview-list .num{font-weight:800;color:#111827;min-width:48px;}
        .preview-list .title{font-weight:700;color:#111827;}
        .preview-list .meta{color:#6b7280;font-size:13px;}
        .empty{color:#6b7280;font-size:13px;}
    
        .btn-outline{background:#fff;color:#374151;border:1px solid #d1d5db;}
        .btn-outline:hover{background:#f3f4f6;}
        .inline-remove{margin-left:auto;}
        .mini-btn{border:1px solid #d1d5db;background:#fff;border-radius:10px;padding:4px 10px;cursor:pointer;font-weight:800;}
        .mini-btn:hover{background:#f3f4f6;}
        .preview-list li{align-items:center;}
    
        /* UI polish for Latest controls */
        .latest-controls{
            display:flex;
            gap:8px;
            align-items:center;
            margin-top:10px;
            flex-wrap:wrap;
            padding:10px;
            background:#f8fafc;
            border:1px solid #e5e7eb;
            border-radius:14px;
        }
        .latest-controls label{
            display:flex;
            gap:8px;
            align-items:center;
            font-size:12px;
            color:#374151;
            font-weight:700;
        }
        .latest-controls input[type="checkbox"]{transform:scale(1.05);}
        .latest-controls input[type="number"]{
            width:64px;
            padding:6px 8px;
            border:1px solid #d1d5db;
            border-radius:10px;
            font-weight:800;
        }
        .latest-controls input[type="number"]:disabled{opacity:.5;background:#f3f4f6;}
        .btn-small{
            padding:7px 12px;
            border:none;
            border-radius:12px;
            background:#4f46e5;
            color:#fff;
            cursor:pointer;
            font-weight:800;
            font-size:12px;
            line-height:1;
        }
        .btn-small:hover{background:#4338ca;}
        .btn-outline{
            background:#fff;
            color:#374151;
            border:1px solid #d1d5db;
        }
        .btn-outline:hover{background:#f3f4f6;}
        /* Preview list compact */
        .preview-list{gap:6px;}
        .preview-list li{
            display:flex;
            gap:10px;
            align-items:center;
            padding:8px 10px;
            border:1px solid #eef2f7;
            border-radius:12px;
        }
        .preview-list .num{min-width:44px;}
        .inline-remove{margin-left:auto;}
        .mini-btn{
            border:1px solid #d1d5db;
            background:#fff;
            border-radius:12px;
            padding:6px 10px;
            cursor:pointer;
            font-weight:900;
            font-size:12px;
            line-height:1;
        }
        .mini-btn:hover{background:#f3f4f6;}

    
        /* Make Latest Preview more compact */
        .latest-preview{margin:14px 0;padding:12px;border-radius:14px;}
        .latest-preview h2{font-size:18px;}
        .latest-preview .hint{font-size:12px;margin-bottom:10px;}
        .preview-grid{gap:10px;}
        .preview-box{padding:10px;border-radius:12px;}
        .preview-box h3{font-size:14px;margin-bottom:8px;}
        .preview-list li{padding:6px 8px;border-radius:10px;}
        .preview-list .num{min-width:38px;font-size:12px;}
        .preview-list .title{font-size:13px;}
        .preview-list .meta{font-size:12px;}
        .mini-btn{padding:5px 9px;border-radius:10px;}

    
        /* Match Latest Preview styling to Dashboard sections */
        .latest-preview{
            margin:18px 0;
            padding:0;
            background:transparent;
            border:none;
        }
        .latest-preview h2{
            font-size:20px;
            margin:0 0 6px 0;
        }
        .latest-preview .hint{
            margin:0 0 12px 0;
            color:#6b7280;
            font-size:13px;
        }
        .preview-grid{gap:14px;}
        .preview-box{
            background:#fff;
            border:1px solid #e5e7eb;
            border-radius:16px;
            padding:14px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.04);
        }
        .preview-box h3{
            font-size:16px;
            margin:0 0 10px 0;
            font-weight:800;
        }
        .preview-list li{
            padding:10px 12px;
            border-radius:14px;
            border:1px solid #eef2f7;
            background:#fafafa;
        }
        .mini-btn{
            background:#fff;
        }

    
        /* Balance Latest Preview card sizes */
        .preview-grid{align-items:stretch;}
        .preview-box{min-height:120px;}
        .preview-list li{min-height:44px;}
        .latest-actions{display:flex;gap:8px;align-items:center;}
        /* Remove extra vertical space inside latest-controls */
        .latest-controls{padding:10px;}

    
.latest-section{
    max-width:1100px;
    margin:24px auto 32px;
    background:#111;
    border-radius:18px;
    padding:20px 22px;
}
.latest-preview-header h2{
    font-size:18px;
    font-weight:600;
}
.latest-preview{
    margin-top:14px;
}
@media(max-width:768px){
    .latest-section{margin:16px;}
}

        .btn-danger {
            background: linear-gradient(45deg, #ff4d4d, #ff6b6b);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
        }
        .btn-danger:hover { opacity: 0.9; }


            .downloads-controls{
                margin-top: 12px;
                padding: 12px;
                border-radius: 14px;
                background: rgba(255,255,255,0.05);
                border: 1px solid rgba(255,255,255,0.08);
            }
            .downloads-controls .dl-title{
                font-weight: 900;
                margin-bottom: 10px;
                color: rgba(255,255,255,0.92);
            }
            .downloads-controls .dl-row{
                display: grid;
                grid-template-columns: 1fr;
                gap: 10px;
                margin-bottom: 8px;
            }
            .downloads-controls input{
                width: 100%;
                padding: 12px 12px;
                border-radius: 12px;
                border: 1px solid rgba(255,255,255,0.12);
                background: rgba(0,0,0,0.25);
                color: rgba(255,255,255,0.92);
                font-weight: 800;
                font-size: 0.95rem;
                outline: none;
            }
            .downloads-controls input::placeholder{
                color: rgba(255,255,255,0.35);
                font-weight: 700;
            }
            @media (max-width: 900px){
                .downloads-controls .dl-row{
                    grid-template-columns: 1fr;
                }
            }

            .downloads-controls .dl-row.is-hidden{
                display: none;
            }
            .btn-secondary{
                background: rgba(255,255,255,0.10) !important;
                border: 1px solid rgba(255,255,255,0.12) !important;
            }

            .downloads-controls .add-dl-btn{
                margin-top: 0;
            }
            .downloads-controls .dl-actions{
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 10px;
                margin-top: 10px;
            }
            .downloads-controls .dl-actions .btn{
                width: 100%;
                justify-content: center;
            }

            @media (max-width: 900px){
                .downloads-controls .dl-actions{
                    grid-template-columns: 1fr;
                }
            }


        

        /* Reports Table (matches admin UI) */
        .requests-table{
            margin-top: 14px;
            border: 1px solid rgba(255,255,255,.10);
            border-radius: 18px;
            overflow: hidden;
            background: rgba(255,255,255,.03);
        }

        .rrow{
            display: grid;
            grid-template-columns: 2fr 1fr 2fr 1.2fr .9fr 1fr 1.2fr;
            gap: 12px;
            padding: 12px 14px;
            align-items: center;
            border-bottom: 1px solid rgba(255,255,255,.06);
        }

        .rrow:last-child{ border-bottom: none; }

        .rhead{
            font-weight: 900;
            background: rgba(255,255,255,.04);
            position: sticky;
            top: 0;
            z-index: 2;
        }

        .btn.small{
            padding: 8px 12px;
            border-radius: 12px;
            font-weight: 800;
            text-decoration: none;
            display: inline-block;
            background: rgba(255,255,255,.06);
            border: 1px solid rgba(255,255,255,.10);
            color: #fff;
        }

        .btn.small:hover{
            background: rgba(255,255,255,.10);
        }

        .btn.small.danger{
            background: rgba(239,68,68,.12);
            border: 1px solid rgba(239,68,68,.30);
        }

        .btn.small.danger:hover{
            background: rgba(239,68,68,.18);
        }

        .badge{
            display:inline-block;
            padding:4px 10px;
            border-radius:999px;
            font-size:12px;
            font-weight:900;
        }
        .badge-danger{ background: rgba(239,68,68,.18); border:1px solid rgba(239,68,68,.35); }
        .badge-success{ background: rgba(34,197,94,.18); border:1px solid rgba(34,197,94,.35); }

        @media (max-width: 900px){
            .rrow{
                grid-template-columns: 1.6fr 1fr 1.8fr 1fr 1fr;
                grid-auto-rows: auto;
            }
            .rrow > div:nth-child(6),
            .rrow > div:nth-child(7){
                grid-column: span 2;
            }
        }

        .platform-checks{
            display:flex;
            flex-wrap:wrap;
            gap:10px;
            margin-top:8px;
        }
        .platform-check{
            display:flex;
            gap:8px;
            align-items:center;
            padding:8px 10px;
            border:1px solid rgba(255,255,255,0.08);
            border-radius:12px;
            background: rgba(255,255,255,0.03);
            cursor:pointer;
            user-select:none;
            font-weight:600;
        }
        .platform-check input{
            accent-color:#4CAF50;
        }
    

        .platform-edit{
            margin-top:10px;
        }
        .platform-label{
            display:block;
            font-size:12px;
            opacity:.8;
            margin-bottom:6px;
            font-weight:700;
        }
        .platform-form select{
            width:100%;
            padding:10px 12px;
            border-radius:12px;
            border:1px solid rgba(255,255,255,0.10);
            background: rgba(255,255,255,0.04);
            color:#fff;
            outline:none;
            cursor:pointer;
        }
        .platform-form select:focus{
            border-color: rgba(255,255,255,0.22);
        }

.section:first-of-type{margin-top:1.5rem;}
</style>

</head>
<body>
<div style="margin:20px 0; padding:20px; background:#111; border-radius:10px;">
    <h3 style="color:#fff;">Upload Website Logo</h3>
    <form method="POST" enctype="multipart/form-data">
        <input type="file" name="logo" required style="margin-bottom:10px;">
        <br>
        <button type="submit" name="upload_logo" style="padding:8px 15px; background:#ff4b5c; border:none; color:#fff; border-radius:5px;">
            Upload Logo
        </button>
    </form>
    <div style="margin-top:15px;">
        <img src="<?php echo htmlspecialchars($currentLogoPath); ?>" style="height:60px; object-fit:contain;">
    </div>
</div>

    <header class="header">
        <div class="container">
            <div class="header-content">
                <div class="logo">🎬 FlixMo Admin Dashboard</div>
	                <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
	                    <a href="ads_settings.php" class="logout-btn" style="background:#4ecdc4;">🧩 Ads</a>
	                    <a href="?logout=1" class="logout-btn">🚪 Logout</a>
	                </div>
            </div>
        </div>
    
            </header>

    <main class="container">
        <div class="dashboard-content">
            
            <?php if (isset($success)): ?>
                <div class="success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

            <?php if (isset($error)): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <!-- Statistics -->
            <div class="stats">
                <div class="stat-card">
                    <div class="stat-number"><?= count($movies) ?></div>
                    <div class="stat-label">Total Content</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?= count(array_filter($movies, function($m) { return $m['type'] === 'movie'; })) ?></div>
                    <div class="stat-label">Movies</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?= count(array_filter($movies, function($m) { return $m['type'] === 'tv'; })) ?></div>
                    <div class="stat-label">TV Series</div>
                </div>
            
                <div class="stat-card">
                    <div class="stat-number"><?= count($pendingRequests) ?></div>
                    <div class="stat-label">Pending Requests</div>
                </div>
</div>

            <!-- Latest Preview -->
            <?php
            // Latest Preview (Movies & TV)
            $latestMoviesPreview = array_filter($movies, function($item) {
                $show = isset($item['show_in_latest']) ? (bool)$item['show_in_latest'] : false;
                $order = isset($item['latest_order']) ? (int)$item['latest_order'] : 0;
                return ($item['type'] === 'movie') && $show === true && $order >= 1 && $order <= 10;
            });
            usort($latestMoviesPreview, function($a, $b) {
                return ((int)($a['latest_order'] ?? 999)) <=> ((int)($b['latest_order'] ?? 999));
            });
            $latestMoviesPreview = array_slice($latestMoviesPreview, 0, 10);

            $latestTVPreview = array_filter($movies, function($item) {
                $show = isset($item['show_in_latest']) ? (bool)$item['show_in_latest'] : false;
                $order = isset($item['latest_order']) ? (int)$item['latest_order'] : 0;
                return ($item['type'] === 'tv') && $show === true && $order >= 1 && $order <= 10;
            });
            usort($latestTVPreview, function($a, $b) {
                return ((int)($a['latest_order'] ?? 999)) <=> ((int)($b['latest_order'] ?? 999));
            });
            $latestTVPreview = array_slice($latestTVPreview, 0, 10);
            ?>

            <section class="latest-section"><div class="latest-preview-wrapper">
    <div class="latest-preview-header">
        <h2>Latest Preview</h2>
        <button class="toggle-preview" onclick="toggleLatest()">Hide</button>
    </div>
    <div class="latest-preview" id="latestPreview">

                <h2>📊 Latest Preview</h2>
                <p class="hint">This is exactly how your homepage Latest sections will appear (based on Show in Latest + Latest Order).</p>

                <div class="preview-grid">
                    <div class="preview-box">
                        <h3>🎬 Latest Movies (<?= count($latestMoviesPreview) ?>/10)</h3>
                        <?php if (count($latestMoviesPreview) === 0): ?>
                            <div class="empty">No movies selected for Latest yet.</div>
                        <?php else: ?>
                            <ol class="preview-list">
                                <?php foreach ($latestMoviesPreview as $item): ?>
                                    <li>
                                        <span class="num">#<?= (int)($item['latest_order'] ?? 0) ?></span>
                                        <span class="title"><?= htmlspecialchars($item['title'] ?? '') ?></span>
                                        <span class="meta">(<?= htmlspecialchars($item['year'] ?? '') ?>)</span>

                                        <form method="POST" class="inline-remove">
                                            <input type="hidden" name="action" value="remove_latest">
                                            <input type="hidden" name="imdb_id" value="<?= htmlspecialchars($item['imdb_id'] ?? '') ?>">
                                            <button type="submit" class="mini-btn" title="Remove from Latest" onclick="return confirm('Remove this item from Latest?')">✖</button>
                                        </form>
                                    </li>
                                <?php endforeach; ?>
                            </ol>
                        <?php endif; ?>
                    </div>

                    <div class="preview-box">
                        <h3>📺 Latest TV Shows (<?= count($latestTVPreview) ?>/10)</h3>
                        <?php if (count($latestTVPreview) === 0): ?>
                            <div class="empty">No TV shows selected for Latest yet.</div>
                        <?php else: ?>
                            <ol class="preview-list">
                                <?php foreach ($latestTVPreview as $item): ?>
                                    <li>
                                        <span class="num">#<?= (int)($item['latest_order'] ?? 0) ?></span>
                                        <span class="title"><?= htmlspecialchars($item['title'] ?? '') ?></span>
                                        <span class="meta">(<?= htmlspecialchars($item['year'] ?? '') ?>)</span>

                                        <form method="POST" class="inline-remove">
                                            <input type="hidden" name="action" value="remove_latest">
                                            <input type="hidden" name="imdb_id" value="<?= htmlspecialchars($item['imdb_id'] ?? '') ?>">
                                            <button type="submit" class="mini-btn" title="Remove from Latest" onclick="return confirm('Remove this item from Latest?')">✖</button>
                                        </form>
                                    </li>
                                <?php endforeach; ?>
                            </ol>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
</div>
</section>



            <!-- Pending Requests -->
            <div class="section" id="requests">
                <h2>📝 Pending Requests (Auto-delete after 24 hours)</h2>
                <?php if (isset($_GET['deleted'])): ?>
                    <div class="success">Request deleted.</div>
                <?php endif; ?>
                <?php if (count($pendingRequests) === 0): ?>
                    <p style="opacity:0.85; margin-top:10px;">No pending requests.</p>
                <?php else: ?>
                    <div style="overflow:auto; margin-top: 12px;">
                        <table style="width:100%; border-collapse: collapse; min-width: 520px;">
                            <thead>
                                <tr>
                                    <th style="text-align:left; padding:10px; border-bottom:1px solid rgba(255,255,255,0.15);">Title</th>
                                    <th style="text-align:left; padding:10px; border-bottom:1px solid rgba(255,255,255,0.15);">Year</th>
                                    <th style="text-align:left; padding:10px; border-bottom:1px solid rgba(255,255,255,0.15);">Requested</th>
                                    <th style="text-align:left; padding:10px; border-bottom:1px solid rgba(255,255,255,0.15);">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pendingRequests as $r): ?>
                                    <tr>
                                        <td style="padding:10px; border-bottom:1px solid rgba(255,255,255,0.08);"><?= htmlspecialchars($r['title'] ?? '') ?></td>
                                        <td style="padding:10px; border-bottom:1px solid rgba(255,255,255,0.08);"><?= htmlspecialchars($r['year'] ?? '') ?></td>
                                        <td style="padding:10px; border-bottom:1px solid rgba(255,255,255,0.08);">
                                            <?php
                                                $ts = isset($r['created_at']) ? (int)$r['created_at'] : 0;
                                                echo $ts ? date('Y-m-d H:i', $ts) : '-';
                                            ?>
                                        </td>
                                        <td style="padding:10px; border-bottom:1px solid rgba(255,255,255,0.08);">
                                            <form method="POST" action="delete_request.php" onsubmit="return confirm('Delete this request?');" style="display:inline;">
                                                <input type="hidden" name="request_id" value="<?= htmlspecialchars($r['id'] ?? '') ?>">
                                                <button type="submit" class="btn btn-danger" style="padding:8px 12px;">🗑️ Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <?php
        // Load reports
        $reportsFile = __DIR__ . '/../reports.json';
        if (!file_exists($reportsFile)) { file_put_contents($reportsFile, "[]"); }
        $reportsRaw = file_get_contents($reportsFile);
        $reports = json_decode($reportsRaw, true);
        if (!is_array($reports)) $reports = [];

        $newReports = array_filter($reports, function($r){
            return ($r['status'] ?? 'NEW') === 'NEW';
        });
        ?>

        <section class="admin-section" style="margin-top:24px; margin-bottom:24px;">
            <div class="section-title">
                <h2>🚨 Broken Stream Reports</h2>
                <p style="margin-top:6px;color:#9ca3af;">
                    Reports from users when a movie/series is not playing or link is broken.
                </p>
            </div>

            <?php if (count($reports) === 0): ?>
                <div class="empty-note">No reports yet.</div>
            <?php else: ?>
                <div class="requests-table" style="margin-top:14px;">
                    <div class="rrow rhead">
                        <div>Title</div>
                        <div>IMDb</div>
                        <div>Reason</div>
                        <div>Reporter</div>
                        <div>Status</div>
                        <div>Date</div>
                        <div>Action</div>
                    </div>

                    <?php foreach ($reports as $rp): ?>
                        <div class="rrow" style="<?= (($rp['status'] ?? 'NEW') === 'NEW') ? 'border-left:4px solid #ef4444;' : '' ?>">
                            <div><strong><?= htmlspecialchars($rp['title'] ?? 'Unknown') ?></strong> <span style="opacity:.7;">(<?= strtoupper(htmlspecialchars($rp['type'] ?? 'movie')) ?>)</span></div>
                            <div><?= htmlspecialchars($rp['imdb_id'] ?? '') ?></div>
                            <div><?= htmlspecialchars($rp['reason'] ?? '') ?></div>
                            <div><?= htmlspecialchars($rp['reporter'] ?? 'Guest') ?></div>
                            <div>
                                <?php if (($rp['status'] ?? 'NEW') === 'NEW'): ?>
                                    <span class="badge badge-danger">NEW</span>
                                <?php else: ?>
                                    <span class="badge badge-success">RESOLVED</span>
                                <?php endif; ?>
                            </div>
                            <div><?= htmlspecialchars(date('M d, Y', strtotime($rp['created_at'] ?? 'now'))) ?></div>
                            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                                <?php if (($rp['status'] ?? 'NEW') === 'NEW'): ?>
                                    <a class="btn small" href="report_action.php?action=resolve&id=<?= urlencode($rp['id']) ?>">Resolve</a>
                                <?php endif; ?>
                                <a class="btn small danger" href="report_action.php?action=delete&id=<?= urlencode($rp['id']) ?>" onclick="return confirm('Delete this report?')">Delete</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>



            <!-- Add New Movie/TV Series -->
            <div class="section">
                <h2>➕ Add New Movie/TV Series</h2>
                <form method="POST">
                    <input type="hidden" name="action" value="add">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="imdb_id">🎬 IMDb ID</label>
                            <div style="display: flex;">
                                <input type="text" id="imdb_id" name="imdb_id" placeholder="tt1234567" required>
                                <button type="button" class="imdb-fetch" onclick="fetchIMDbData()">📥 Fetch</button>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="title">📝 Title</label>
                            <input type="text" id="title" name="title" placeholder="Movie/TV series title" required>
                        </div>
                        <div class="form-group">
                            <label for="year">📅 Year</label>
                            <input type="text" id="year" name="year" placeholder="2024">
                        </div>
                        <div class="form-group">
                            <label for="type">🎭 Type</label>
                            <select id="type" name="type" required>
                                <option value="movie">Movie</option>
                                <option value="tv">TV Series</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="poster">🖼️ Poster URL</label>
                            <input type="url" id="poster" name="poster" placeholder="https://...">
                        </div>
                        <div class="form-group">
                            <label for="rating">⭐ Rating</label>
                            <input type="text" id="rating" name="rating" placeholder="8.5">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">➕ Add Movie/TV Series</button>
                </form>

<!-- Auto Platform (IMDb Lists) -->
<div class="section">
    <h2>🧩 Auto Platform (IMDb Lists)</h2>
    <div class="notice" style="margin-bottom: 14px;">
        Paste the <strong>IMDb List URL</strong> for each platform (example: <code>https://www.imdb.com/list/lsXXXXXXXX/</code>).<br>
        When you add Movies/TV (or use Auto Add by Year), the system will auto-detect the platform if the IMDb ID exists in that list.
    </div>

    <form method="POST">
        <input type="hidden" name="action" value="save_platform_lists">
        <div class="form-grid">
            <div class="form-group">
                <label>Hulu list URL</label>
                <input type="url" name="list_hulu" placeholder="https://www.imdb.com/list/ls.../" value="<?= htmlspecialchars($platformLists['hulu']['list_url'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Netflix list URL</label>
                <input type="url" name="list_netflix" placeholder="https://www.imdb.com/list/ls.../" value="<?= htmlspecialchars($platformLists['netflix']['list_url'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Prime Video list URL</label>
                <input type="url" name="list_primevideo" placeholder="https://www.imdb.com/list/ls.../" value="<?= htmlspecialchars($platformLists['primevideo']['list_url'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Disney+ list URL</label>
                <input type="url" name="list_disneyplus" placeholder="https://www.imdb.com/list/ls.../" value="<?= htmlspecialchars($platformLists['disneyplus']['list_url'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>VivaMax list URL</label>
                <input type="url" name="list_vivamax" placeholder="https://www.imdb.com/list/ls.../" value="<?= htmlspecialchars($platformLists['vivamax']['list_url'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Warner Bros list URL</label>
                <input type="url" name="list_warnerbros" placeholder="https://www.imdb.com/list/ls.../" value="<?= htmlspecialchars($platformLists['warnerbros']['list_url'] ?? '') ?>">
            </div>
        </div>
        <button type="submit" class="btn btn-primary">💾 Save Lists</button>
    </form>

    <form method="POST" style="margin-top: 12px;">
        <input type="hidden" name="action" value="sync_platforms">
        <button type="submit" class="btn">🔄 Sync Existing Movies/TV (only items with platform = all)</button>
    </form>
</div>

<!-- Auto Add by Year -->

<div class="section">
    <h2>⚡ Auto Add by Year (Movies + TV Series)</h2>

    <div class="notice" style="margin-bottom: 14px;">
        <strong>Note:</strong> This uses <strong>TMDb</strong> to get the list for the year, then <strong>OMDb</strong> to fetch IMDb details.
        <br>
        To make this work, set your <code>TMDB_API_KEY</code> in your hosting environment.
    </div>

    <div class="form-grid">
        <div class="form-group">
            <label for="bulk_year">📅 Year</label>
            <input type="number" id="bulk_year" placeholder="2025" min="1900" max="2100" value="<?= date('Y') ?>">
        </div>

        <div class="form-group">
            <label for="bulk_mode">🧩 Type</label>
            <select id="bulk_mode">
                <option value="all">All (Movies + TV)</option>
                <option value="movie">Movies Only</option>
                <option value="tv">TV Series Only</option>
            </select>
        </div>

        <div class="form-group">
            <label for="bulk_limit">🔢 Limit</label>
            <input type="number" id="bulk_limit" placeholder="60" min="1" max="300" value="60">
        </div>

        <div class="form-group" style="display:flex; align-items:flex-end;">
            <button type="button" class="btn" id="bulk_run_btn">📥 Fetch & Auto Add</button>
        </div>
    </div>

    <div id="bulk_result" class="notice" style="display:none; margin-top: 14px; white-space: pre-wrap;"></div>
</div>


            </div>

            
<!-- Watch Player Server Addresses -->
<div class="section">
    <h2>🎞️ Watch Player Servers (Site Address)</h2>
    <div class="notice" style="margin-bottom: 14px;">
        Change the base address for Server 1 / 2 / 3. You can also add unlimited Custom Servers below. This will automatically update the Watch player links on the website.
        <br><strong>Tip:</strong> You can paste domain only (example: <code>vidsrc.me</code>) or full URL (example: <code>https://vidsrc.me</code>).
    </div>
    <form method="POST">
        <input type="hidden" name="action" value="save_player_servers">
        <div class="form-grid">
            <div class="form-group">
                <label>Server 1 base URL</label>
                <input type="text" name="server1_base" placeholder="https://vidsrc.me" value="<?= htmlspecialchars($playerServer1Base) ?>">
            </div>
            <div class="form-group">
                <label>Server 2 base URL</label>
                <input type="text" name="server2_base" placeholder="https://www.vidking.net" value="<?= htmlspecialchars($playerServer2Base) ?>">
            </div>
            <div class="form-group">
                <label>Server 3 base URL</label>
                <input type="text" name="server3_base" placeholder="https://player.videasy.net" value="<?= htmlspecialchars($playerServer3Base) ?>">
            </div>
            <div class="form-group">
                <label>Server 4 base URL (optional)</label>
                <input type="text" name="server4_base" placeholder="https://your-new-server.com" value="<?= htmlspecialchars($playerServer4Base) ?>">
            </div>
        </div>
        
        <div class="form-grid" style="margin-top: 10px;">
            <div class="form-group" style="grid-column: 1 / -1;">
                <label>Custom Servers (unlimited) — one Site Address per line</label>
                <textarea name="custom_servers" rows="5" placeholder="https://example1.com&#10;example2.com" style="width:100%;"><?= htmlspecialchars(implode("\n", $playerServerCustom)) ?></textarea>
                <small style="opacity:0.85; display:block; margin-top:6px;">Custom servers patterns: <code>{SITE_ADDRESS}/embed/movie/{tmdb}</code>, <code>{SITE_ADDRESS}/embed/tv/{tmdb}/{season}/{episode}</code>, <code>{SITE_ADDRESS}/embed/movie?imdb=ttxxxx</code>, <code>{SITE_ADDRESS}/embed/tv?tmdb=ID&season=X&episode=Y</code></small>
            </div>
        </div>
<button type="submit" class="btn btn-primary">💾 Save Server Addresses</button>
    </form>
</div>

<!-- Current Movies/TV Series -->
            <div class="section">
                <h2>📚 Current Content</h2>

                <?php
                    $movieItems = array_filter($movies, function($m){ return strtolower($m['type'] ?? 'movie') === 'movie'; });
                    $tvItems    = array_filter($movies, function($m){ return in_array(strtolower($m['type'] ?? 'movie'), ['tv','tv series','series']); });
                ?>

                <div class="content-controls">
                    <input type="text" id="adminContentSearch" placeholder="Search title / year / IMDb ID..." autocomplete="off">
                    <div class="filter-tabs">
                        <button type="button" class="tab active" data-filter="all">All</button>
                        <button type="button" class="tab" data-filter="movie">Movies</button>
                        <button type="button" class="tab" data-filter="tv">TV Series</button>
                    </div>
                </div>

                <div class="subsection" data-section="movie">
                    <h3>🎬 Movies (<?= count($movieItems) ?>)</h3>

                    <div class="movies-grid" id="adminMovieGrid"></div>

                    <div style="margin-top:12px; display:flex; gap:10px; align-items:center;">
                        <button type="button" class="btn btn-small" id="adminLoadMoreMovie">Load more</button>
                        <span class="muted" id="adminMovieStatus" style="opacity:.8;"></span>
                    </div>

                    <div class="empty-note" data-empty="movie" style="display:none;">No matching movies found.</div>
                </div>

                <div class="subsection" data-section="tv">
                    <h3>📺 TV Series (<?= count($tvItems) ?>)</h3>

                    <div class="movies-grid" id="adminTvGrid"></div>

                    <div style="margin-top:12px; display:flex; gap:10px; align-items:center;">
                        <button type="button" class="btn btn-small" id="adminLoadMoreTv">Load more</button>
                        <span class="muted" id="adminTvStatus" style="opacity:.8;"></span>
                    </div>

                    <div class="empty-note" data-empty="tv" style="display:none;">No matching TV series found.</div>
                </div>
<style>
.latest-preview-wrapper{
    max-width:1000px;
    margin:0 auto 24px;
}
.latest-preview-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:10px;
}
.toggle-preview{
    background:#ff4d4f;
    border:none;
    padding:6px 12px;
    border-radius:8px;
    color:#fff;
    cursor:pointer;
}

.latest-section{
    max-width:1100px;
    margin:24px auto 32px;
    background:#111;
    border-radius:18px;
    padding:20px 22px;
}
.latest-preview-header h2{
    font-size:18px;
    font-weight:600;
}
.latest-preview{
    margin-top:14px;
}
@media(max-width:768px){
    .latest-section{margin:16px;}
}


/* Admin Current Content controls */
.content-controls{
    display:flex;
    gap:12px;
    align-items:center;
    justify-content:space-between;
    flex-wrap:wrap;
    margin:12px 0 18px;
}
#adminContentSearch{
    flex:1;
    min-width:240px;
    padding:10px 12px;
    border-radius:12px;
    border:1px solid rgba(255,255,255,0.12);
    background:rgba(0,0,0,0.25);
    color:#fff;
    outline:none;
}
#adminContentSearch::placeholder{color:rgba(255,255,255,0.55);}
.filter-tabs{
    display:flex;
    gap:8px;
}
.filter-tabs .tab{
    background:rgba(255,255,255,0.08);
    border:1px solid rgba(255,255,255,0.12);
    padding:9px 12px;
    border-radius:12px;
    color:#fff;
    cursor:pointer;
}
.filter-tabs .tab.active{
    background:#ff4d4f;
    border-color:#ff4d4f;
}
.subsection h3{
    margin:10px 0 12px;
    font-size:16px;
    font-weight:600;
    color:#fff;
}
.empty-note{
    margin-top:10px;
    opacity:.75;
}
</style>



<script>
(function(){
    const LIMIT = 10;

    const state = {
        q: '',
        filter: 'all', // all | movie | tv
        movie: { offset: 0, hasMore: true, loading: false },
        tv:    { offset: 0, hasMore: true, loading: false },
    };

    function el(id){ return document.getElementById(id); }

    function setStatus(section, text){
        const s = el(section === 'movie' ? 'adminMovieStatus' : 'adminTvStatus');
        if (s) s.textContent = text || '';
    }

    function setLoadMoreEnabled(section, enabled){
        const btn = el(section === 'movie' ? 'adminLoadMoreMovie' : 'adminLoadMoreTv');
        if (!btn) return;
        btn.disabled = !enabled;
        btn.style.opacity = enabled ? '1' : '.6';
    }

    function clearSection(section){
        const grid = el(section === 'movie' ? 'adminMovieGrid' : 'adminTvGrid');
        if (grid) grid.innerHTML = '';
        state[section].offset = 0;
        state[section].hasMore = true;
        setStatus(section, '');
        setLoadMoreEnabled(section, true);
        const empty = document.querySelector('.empty-note[data-empty="'+section+'"]');
        if (empty) empty.style.display = 'none';
    }

    async function fetchSection(section, append=true){
        if (state[section].loading) return;
        if (!state[section].hasMore && append) return;

        // If tab filter hides this section, don't fetch (saves work)
        if (state.filter !== 'all' && state.filter !== section) return;

        state[section].loading = true;
        setLoadMoreEnabled(section, false);
        setStatus(section, 'Loading...');

        const params = new URLSearchParams();
        params.set('section', section);
        params.set('q', state.q || '');
        params.set('offset', String(state[section].offset || 0));
        params.set('limit', String(LIMIT));

        try{
            const res = await fetch('content_api.php?'+params.toString(), { credentials: 'same-origin' });
            if (!res.ok) throw new Error('HTTP '+res.status);
            const data = await res.json();
            if (!data || !data.ok) throw new Error(data && data.error ? data.error : 'Unknown error');

            const grid = el(section === 'movie' ? 'adminMovieGrid' : 'adminTvGrid');
            if (grid){
                if (!append) grid.innerHTML = '';
                grid.insertAdjacentHTML('beforeend', data.html || '');
            }

            state[section].offset = data.nextOffset || (state[section].offset + (data.returned || 0));
            state[section].hasMore = !!data.hasMore;

            // Empty note
            const empty = document.querySelector('.empty-note[data-empty="'+section+'"]');
            if (empty){
                const nothing = (state[section].offset === 0 && (data.totalMatched || 0) === 0);
                empty.style.display = nothing ? 'block' : 'none';
            }

            if (!data.hasMore){
                setStatus(section, (data.totalMatched || 0) ? 'No more to load.' : '');
            } else {
                setStatus(section, '');
            }

            setLoadMoreEnabled(section, data.hasMore);

            // Re-bind controls for newly injected cards
            bindDownloadAddButtons();
            bindLatestControls();

        }catch(err){
            console.error(err);
            setStatus(section, 'Failed to load.');
            setLoadMoreEnabled(section, true);
        }finally{
            state[section].loading = false;
        }
    }

    function applyTabVisibility(){
        const subsections = document.querySelectorAll('.subsection[data-section]');
        subsections.forEach(sec => {
            const secType = sec.getAttribute('data-section');
            if (state.filter === 'all' || state.filter === secType) sec.style.display = '';
            else sec.style.display = 'none';
        });
    }

    function resetAndLoad(){
        clearSection('movie');
        clearSection('tv');
        applyTabVisibility();

        // Load initial only for visible sections
        fetchSection('movie', true);
        fetchSection('tv', true);
    }

    function bindTabsAndSearch(){
        const qEl = el('adminContentSearch');
        if (qEl){
            let t=null;
            qEl.addEventListener('input', function(){
                // debounce to avoid spam
                clearTimeout(t);
                t=setTimeout(()=>{
                    state.q = (qEl.value || '').trim().toLowerCase();
                    resetAndLoad();
                }, 150);
            });
        }

        const tabs = document.querySelectorAll('.filter-tabs .tab');
        tabs.forEach(btn => {
            btn.addEventListener('click', function(){
                tabs.forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                state.filter = this.getAttribute('data-filter') || 'all';
                // When switching tabs, reset paging so we don't carry old offsets
                resetAndLoad();
            });
        });

        const lmM = el('adminLoadMoreMovie');
        if (lmM) lmM.addEventListener('click', () => fetchSection('movie', true));

        const lmT = el('adminLoadMoreTv');
        if (lmT) lmT.addEventListener('click', () => fetchSection('tv', true));
    }

    function bindDownloadAddButtons(){
        document.querySelectorAll('.downloads-controls').forEach(form => {
            const addBtn = form.querySelector('.add-dl-btn');
            if (!addBtn || addBtn.dataset.bound === '1') return;
            addBtn.dataset.bound = '1';

            addBtn.addEventListener('click', () => {
                const hidden = form.querySelector('.dl-row.is-hidden');
                if (hidden) {
                    hidden.classList.remove('is-hidden');
                } else {
                    addBtn.disabled = true;
                    addBtn.textContent = '✔ Max links reached';
                }
            });
        });
    }

    // Latest-controls UX:
    // - Auto enable number field when "Show in Latest Home" is checked (even before save)
    // - When unchecked, disable and clear the number field
    function bindLatestControls(){
        document.querySelectorAll('.latest-controls').forEach(form => {
            const cb = form.querySelector('input[type="checkbox"][name="show_in_latest"]');
            const num = form.querySelector('input[type="number"][name="latest_order"]');
            if (!cb || !num) return;
            if (cb.dataset.bound === '1') return;
            cb.dataset.bound = '1';

            const sync = () => {
                if (cb.checked) {
                    num.disabled = false;
                    if (num.value === '') num.focus({preventScroll:true});
                } else {
                    num.value = '';
                    num.disabled = true;
                }
            };

            cb.addEventListener('change', sync);
            // initial sync in case HTML was injected with a stale disabled state
            sync();
        });
    }

    document.addEventListener('DOMContentLoaded', function(){
        bindTabsAndSearch();
        resetAndLoad();
        bindLatestControls();
    });
})();
</script>

<script>
function toggleLatest(){
    const box=document.getElementById('latestPreview');
    const btn=document.querySelector('.toggle-preview');
    if(box.style.display==='none'){
        box.style.display='block';
        btn.innerText='Hide';
    }else{
        box.style.display='none';
        btn.innerText='Show';
    }
}


            // Download links: add another row
            document.querySelectorAll('.downloads-controls').forEach(form => {
                const addBtn = form.querySelector('.add-dl-btn');
                if (!addBtn) return;

                addBtn.addEventListener('click', () => {
                    const hidden = form.querySelector('.dl-row.is-hidden');
                    if (hidden) {
                        hidden.classList.remove('is-hidden');
                    } else {
                        addBtn.disabled = true;
                        addBtn.textContent = '✔ Max links reached';
                    }
                });
            });
async function runBulkYearImport() {
    const year = document.getElementById('bulk_year').value;
    const mode = document.getElementById('bulk_mode').value;
    const limit = document.getElementById('bulk_limit').value;

    if (!year) {
        alert('Please enter a year');
        return;
    }

    const resultBox = document.getElementById('bulk_result');
    resultBox.style.display = 'block';
    resultBox.textContent = "⏳ Importing... please wait";

    try {
        const formData = new FormData();
        formData.append('year', year);
        formData.append('mode', mode);
        formData.append('limit', limit);

        const res = await fetch('bulk_import_year_v2.php', {
                    method: 'POST',
                    body: formData
                });

                const rawText = await res.text();
                if (!rawText) {
                    resultBox.textContent = "❌ Empty response from server. This usually means the host blocked outgoing requests (cURL/allow_url_fopen).";
                    return;
                }
                let data = null;
                try {
                    data = JSON.parse(rawText);
                } catch (e) {
                    resultBox.textContent = "❌ Server returned non-JSON output:\n\n" + rawText;
                    return;
                }

        if (!data.ok) {
            resultBox.textContent = "❌ " + (data.error || "Import failed") + (data.details ? "\n\n" + JSON.stringify(data.details, null, 2) : "");
            return;
        }

        resultBox.textContent =
            `✅ Done!\n\n` +
            `Year: ${data.year}\n` +
            `Mode: ${data.mode}\n` +
            `Added: ${data.added_count}\n` +
            `Skipped: ${data.skipped_count}\n` +
            (data.errors && data.errors.length ? `\nErrors:\n- ${data.errors.join("\n- ")}` : "");

        // Auto refresh page after a short delay so the list updates
        setTimeout(() => window.location.reload(), 1500);

    } catch (err) {
        console.error(err);
        resultBox.textContent = "❌ Error: " + err.message;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const btn = document.getElementById('bulk_run_btn');
    if (btn) {
        btn.addEventListener('click', () => {
            console.log('Bulk year import clicked');
            runBulkYearImport();
        });
    }
});
</script>


<script>
(function(){
  const btn = document.getElementById('bulk_run_btn');
  if(!btn) return;

  const yearEl  = document.getElementById('bulk_year');
  const modeEl  = document.getElementById('bulk_mode');
  const limitEl = document.getElementById('bulk_limit');
  const box     = document.getElementById('bulk_result');

  async function postForm(url, formData){
    const res = await fetch(url, { method:'POST', body: formData });
    const txt = await res.text();
    try { return JSON.parse(txt); } catch(e){ return {ok:false, error:'Invalid JSON response', raw: txt}; }
  }

  btn.addEventListener('click', async function(){
    const year  = (yearEl?.value || '').trim();
    const mode  = (modeEl?.value || 'all');
    const limit = parseInt(limitEl?.value || '60', 10);

    if(!year){
      box.style.display='block';
      box.textContent='⚠️ Please enter a year.';
      return;
    }

    box.style.display='block';
    box.textContent = '⏳ Fetching candidates and importing... please wait';

    
    // Reset + prepare candidate cache (prevents stale schema + slow TMDb discover per batch)
    {
            // reset cache to avoid stale candidate schema
      const rfd = new FormData();
      rfd.append('year', year);
      rfd.append('mode', mode);
      rfd.append('limit', String(limit));
      rfd.append('action', 'reset');
      await postForm('bulk_import_year_v2.php', rfd);

      const pfd = new FormData();
      pfd.append('year', year);
      pfd.append('mode', mode);
      pfd.append('limit', String(limit));
      pfd.append('action', 'prepare');

      const prep = await postForm('bulk_import_year_v2.php', pfd);
      if(!prep || prep.ok !== true){
        box.textContent = '❌ Error: ' + (prep.error || 'Failed to prepare candidates') + (prep.raw ? ('\n\n' + prep.raw) : '');
        return;
      }
      box.textContent = '✅ Candidates ready: ' + (prep.total_candidates || 0) + '\n⏳ Importing...';
    }

    let cursor = 0;
    const batch = 20;
    let totalAdded = 0;
    let totalSkipped = 0;
    let safety = 0;
    let lastSkips = [];

    while(true){
      safety++;
      if(safety > 200){
        box.textContent = '⚠️ Stopped (safety limit reached). Try lower limit.';
        break;
      }

      const fd = new FormData();
      fd.append('year', year);
      fd.append('action', 'batch');
      fd.append('mode', mode);
      fd.append('limit', String(limit));
      fd.append('cursor', String(cursor));
      fd.append('batch', String(batch));

      const data = await postForm('bulk_import_year_v2.php', fd);

      if(!data || data.ok !== true){
        box.textContent = '❌ Error: ' + (data.error || 'Unknown') + (data.raw ? ('\n\n' + data.raw) : '');
        break;
      }

      totalAdded   += (data.added_count || 0);
      const skippedNow = (data.skipped_count || (data.skipped ? data.skipped.length : 0));
      totalSkipped += skippedNow;
      if (data.skipped && data.skipped.length) lastSkips = data.skipped;

      box.textContent =
        '⏳ Importing...\n' +
        'Processed: ' + (data.next_cursor || 0) + '/' + (data.total_candidates || '?') + '\n' +
        'Added: ' + totalAdded + '\n' +
        'Skipped: ' + totalSkipped + (data.processed_count ? ('\nBatch processed: ' + data.processed_count) : '') + (data.errors && data.errors.length ? ('\nErrors: ' + data.errors.length) : '');

      if(totalAdded >= limit){
        let msg = '✅ Done!\nAdded: ' + totalAdded + '\nSkipped: ' + totalSkipped + '\n\nReached your limit (' + limit + ').';
        box.textContent = msg;
        setTimeout(()=>location.reload(), 900);
        break;
      }

      if(data.done){
        if(totalAdded===0 && totalSkipped===0){
          msg += "\n\n⚠️ No items were added or skipped. This usually means TMDb/OMDb fetch failed OR movies.json could not be written.\nCheck TMDB_API_KEY/OMDB_API_KEY and that movies.json is writable.";
        }
        let msg = '✅ Done!\nAdded: ' + totalAdded + '\nSkipped: ' + totalSkipped;

        if(totalAdded === 0 && totalSkipped > 0){
          msg += '\n\nℹ️ Looks like the items for ' + year + ' already exist in your library (duplicates were skipped).';
          msg += '\nTip: try a higher Limit, or pick a different year/type.';
        }

        if(lastSkips && lastSkips.length){
          msg += '\n\nWhy skipped? (sample)';
          const top = lastSkips.slice(0,10).map(x=>'- ' + (x.reason||'skip') + ' ' + (x.imdb_id||x.tmdb_id||''));
          msg += '\n\nTop skips:\n' + top.join('\n');
        }

        box.textContent = msg;
        // refresh only if something was added
        if(totalAdded > 0){
          setTimeout(()=>location.reload(), 900);
        }
        break;
      }

      cursor = data.next_cursor || (cursor + batch);
      await new Promise(r => setTimeout(r, 450));
    }
  });
})();
</script>

<script>
async function fetchIMDbData(){
    const btn = document.querySelector('.imdb-fetch');
    const imdbInput = document.getElementById('imdb_id');
    const imdbID = (imdbInput?.value || '').trim();
    if(!imdbID){
        alert('Please enter an IMDb ID (e.g., tt1375666).');
        imdbInput?.focus();
        return;
    }
    if(btn){
        btn.disabled = true;
        btn.dataset.oldText = btn.innerText;
        btn.innerText = 'Fetching...';
    }
    try{
        const res = await fetch('fetch_imdb.php?imdb_id=' + encodeURIComponent(imdbID), {
            headers: { 'Accept': 'application/json' },
            cache: 'no-store'
        });
        const json = await res.json().catch(()=>null);
        if(!res.ok || !json){
            throw new Error('Request failed');
        }
        if(!json.success){
            alert(json.message || 'Fetch failed. Please check the IMDb ID and try again.');
            return;
        }
        const data = json.data || {};
        // Fill fields if present
        const setVal = (id, val) => {
            const el = document.getElementById(id);
            if(el && val !== undefined && val !== null && val !== 'N/A') el.value = val;
        };
        setVal('title', data.title || '');
        setVal('year', data.year || '');
        setVal('poster', data.poster || '');
        setVal('rating', data.rating || '');
        // If OMDb returns type, try to map
        if(data.type){
            const t = (data.type + '').toLowerCase();
            const typeEl = document.getElementById('type');
            if(typeEl){
                // dashboard uses Movie/TV Series? ensure values maybe 'Movie'/'TV Series' or 'movie'/'tv'
                for(const opt of typeEl.options){
                    if(opt.value.toLowerCase() === t || opt.text.toLowerCase().includes(t)){
                        typeEl.value = opt.value;
                        break;
                    }
                }
            }
        }
    }catch(e){
        console.error(e);
        alert('Fetch failed. Please check your OMDb API key and server connection.');
    }finally{
        if(btn){
            btn.disabled = false;
            btn.innerText = btn.dataset.oldText || '📥 Fetch';
        }
    }
}
</script>
</body>
</html>
