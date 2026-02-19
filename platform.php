<?php
// Auto cache-busting (filemtime)
$asset_v_script = @filemtime(__DIR__.'/js/script.js') ?: time();

$platform = strtolower($_GET['name'] ?? 'all');

$platformTitles = [
  'all' => 'All Platforms',
  'vivamax' => 'VivaMax',
  'netflix' => 'Netflix',
  'warnerbros' => 'Warner Bros',
  'hulu' => 'Hulu',
  'primevideo' => 'Prime Video',
  'disneyplus' => 'Disney+'
];

$pageTitle = $platformTitles[$platform] ?? strtoupper($platform);

// Load movies.json
$moviesPath = __DIR__ . '/movies.json';
$moviesData = [];
if (file_exists($moviesPath)) {
  $moviesData = json_decode(file_get_contents($moviesPath), true) ?? [];
}

// Filter
$filteredMovies = [];
foreach ($moviesData as $id => $movie) {
  $p = strtolower($movie['platform'] ?? 'all');
  if ($platform === 'all' || $p === $platform) {
    $filteredMovies[] = $movie;
  }
}

// Sort
usort($filteredMovies, function($a, $b){
  return ($a['latest_order'] ?? 9999) <=> ($b['latest_order'] ?? 9999);
});

// Site logo loader (admin-uploaded) – keep consistent with index.php
$logoFile = 'logo.png';
$metaFile = __DIR__ . '/assets/img/logo_meta.json';
if (file_exists($metaFile)) {
  $meta = json_decode(file_get_contents($metaFile), true);
  if (is_array($meta) && !empty($meta['file'])) {
    $logoFile = basename($meta['file']);
  }
}
$siteLogoPath = 'assets/img/' . $logoFile;
$hasSiteLogo = file_exists(__DIR__ . '/' . $siteLogoPath);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" href="/favicon.ico" type="image/x-icon">
    <link rel="icon" href="/favicon.png" type="image/png">

    <base href="/">

  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo htmlspecialchars($pageTitle); ?> - FlixMo</title>

  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

  <link rel="stylesheet" href="css/style.css?v=20260218" />
</head>

<body>

  <!-- Header (same style as index.php) -->
  <header class="header">
    <div class="container">
      <div class="nav-container">
<a href="/" class="logo">
          <?php if (!empty($hasSiteLogo)): ?>
            <img src="<?php echo htmlspecialchars($siteLogoPath); ?>" alt="FlixMo" class="logo-img">
          <?php else: ?>
            <span class="logo-text">🎬 FlixMo</span>
          <?php endif; ?>
        </a>
<div class="nav-search" role="search" aria-label="Site search">
                    <form method="GET" action="/search">
                        
                        <input type="text" name="q" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>" placeholder="Search movies or TV..." aria-label="Search" />
                        <button type="submit" aria-label="Search button"><i class="fas fa-search"></i></button>
                    </form>
                </div>


        <button class="mobile-menu-toggle" id="mobileMenuToggle">
          <span class="hamburger-line"></span>
          <span class="hamburger-line"></span>
          <span class="hamburger-line"></span>
        </button>

        <nav class="nav-menu" id="navMenu">
          <div class="nav-overlay" id="navOverlay"></div>
          <ul class="nav-list">
            <li class="nav-item">
              <a href="/" class="nav-link">
                <i class="fas fa-home"></i>
                <span>Home</span>
              </a>
            </li>
            <li class="nav-item">
              <a href="/movies" class="nav-link active">
                <i class="fas fa-film"></i>
                <span>Movies</span>
              </a>
            </li>
            <li class="nav-item">
              <a href="/tv" class="nav-link">
                <i class="fas fa-tv"></i>
                <span>TV Series</span>
              </a>
            </li>
            <li class="nav-item">
              <a href="/request" class="nav-link">
                <i class="fas fa-paper-plane"></i>
                <span>Request</span>
              </a>
            </li>
            <li class="nav-item">
              <a href="/watchlist" class="nav-link">
                <i class="fas fa-star"></i>
                <span>Watchlist</span>
              </a>
            </li>
          </ul>
        </nav>

                

      </div>
    </div>
  </header>

  <!-- Main Content -->
  <main class="main-content">
    <div class="container">

      <div class="page-top">
                <h2 class="section-title"><?php echo htmlspecialchars($pageTitle); ?> Movies</h2>
              </div>

      <div class="content-grid" id="platformMoviesGrid">
        <?php if (count($filteredMovies) === 0): ?>
          <div class="empty-state">
            <h3>No movies found</h3>
            <p>Add <code>"platform": "<?php echo htmlspecialchars($platform); ?>"</code> inside <code>movies.json</code> for the movies you want to show here.</p>
          </div>
        <?php endif; ?>

        <?php foreach ($filteredMovies as $movie): ?>
          <div class="movie-card" onclick="watchMovie('<?php echo htmlspecialchars($movie['imdb_id']); ?>')">
            <div style="position: relative;">
              <img src="<?php echo htmlspecialchars($movie['poster']); ?>" alt="<?php echo htmlspecialchars($movie['title']); ?>" class="movie-poster">
              <span class="movie-label"><?php echo strtoupper(htmlspecialchars($movie['type'] ?? 'MOVIE')); ?></span>
              <span class="movie-rating movie-rating-overlay">⭐ <?php echo htmlspecialchars($movie['rating'] ?? 'N/A'); ?></span>
            </div>
            <div class="movie-info">
              <h3 class="movie-title"><?php echo htmlspecialchars($movie['title']); ?></h3>
              <p class="movie-meta"><?php echo htmlspecialchars($movie['year'] ?? ''); ?></p>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

    </div>
  </main>

  <script>
    // Mobile menu toggle functionality (same as index.php)
    const mobileMenuToggle = document.getElementById('mobileMenuToggle');
    const navMenu = document.getElementById('navMenu');
    const navOverlay = document.getElementById('navOverlay');
    const body = document.body;

    mobileMenuToggle.addEventListener('click', function() {
      navMenu.classList.toggle('active');
      mobileMenuToggle.classList.toggle('active');
      body.classList.toggle('menu-open');
    });

    navOverlay.addEventListener('click', function() {
      navMenu.classList.remove('active');
      mobileMenuToggle.classList.remove('active');
      body.classList.remove('menu-open');
    });

    document.querySelectorAll('.nav-link').forEach(link => {
      link.addEventListener('click', function() {
        // Close the slide menu on mobile + tablet widths
        if (window.innerWidth <= 1024) {
          navMenu.classList.remove('active');
          mobileMenuToggle.classList.remove('active');
          body.classList.remove('menu-open');
        }
      });
    });

    function watchMovie(imdbId) {
      window.location.href = `/watch/${imdbId}`;
    }

    window.addEventListener('resize', function() {
      // When back to desktop nav, ensure the slide menu is closed
      if (window.innerWidth > 1024) {
        navMenu.classList.remove('active');
        mobileMenuToggle.classList.remove('active');
        body.classList.remove('menu-open');
      }
    });
  </script>

  <script src="js/script.js?v=<?php echo $asset_v_script; ?>"></script>
</body>
</html>
