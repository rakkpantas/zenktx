<?php
$configPath = __DIR__ . '/../config/tmdb.php';
$config = include $configPath;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $key = trim($_POST['tmdb_key'] ?? '');
    file_put_contents($configPath, "<?php\nreturn ['api_key' => '".addslashes($key)."'];\n");
    $config['api_key'] = $key;
    $saved = true;
}
?>
<!DOCTYPE html>
<html>
<head>
<title>TMDb Settings</title>
<style>
body{font-family:Arial;background:#111;color:#fff;padding:20px}
input,button{padding:10px;font-size:16px}
a{color:#7CFFB2}
.small{opacity:.75;font-size:14px;margin-top:8px;line-height:1.4}
</style>
</head>
<body>
<h2>TMDb API Settings</h2>
<?php if(!empty($saved)) echo "<p style='color:lime'>Saved successfully</p>"; ?>

<p class="small">
Needed for real actor photos + character names on the Watch page.<br>
Get an API key from TMDb then paste it here.
</p>

<form method="post">
<input type="text" name="tmdb_key" value="<?=htmlspecialchars($config['api_key'] ?? '')?>" placeholder="TMDb API Key" style="width:360px">
<br><br>
<button type="submit">Save</button>
</form>

<p class="small"><a href="dashboard.php">← Back to Dashboard</a></p>
</body>
</html>
