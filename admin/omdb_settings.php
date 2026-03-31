<?php
$configPath = __DIR__ . '/../config/omdb.php';
$config = include $configPath;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $key = trim($_POST['omdb_key']);
    file_put_contents($configPath, "<?php\nreturn ['api_key' => '".addslashes($key)."'];");
    $config['api_key'] = $key;
    $saved = true;
}
?>
<!DOCTYPE html>
<html>
<head>
<title>OMDb Settings</title>
<style>
body{font-family:Arial;background:#111;color:#fff;padding:20px}
input,button{padding:10px;font-size:16px}
</style>
</head>
<body>
<h2>OMDb API Settings</h2>
<?php if(!empty($saved)) echo "<p style='color:lime'>Saved successfully</p>"; ?>
<form method="post">
<input type="text" name="omdb_key" value="<?=htmlspecialchars($config['api_key'])?>" placeholder="OMDb API Key" style="width:300px">
<br><br>
<button type="submit">Save</button>
</form>
</body>
</html>
