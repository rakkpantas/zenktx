<?php
// admin/content_fragment.php
// Reusable renderer for admin content cards.

function zp_admin_render_movie_item($movie){
    $type = strtolower($movie['type'] ?? 'movie');
    if (in_array($type, ['tv', 'tv series', 'series'])) $type = 'tv';
    else $type = 'movie';

    $poster = $movie['poster'] ?? '';
    $title  = $movie['title'] ?? '';
    $year   = $movie['year'] ?? '';
    $imdb   = $movie['imdb_id'] ?? '';
    $rating = $movie['rating'] ?? '0.0';
    $curPlat = strtolower($movie['platform'] ?? 'all');
    if ($curPlat === '') $curPlat = 'all';

    // data-search must be safe
    $search = strtolower(trim($title.' '.$year.' '.$imdb));
    ?>
    <div class="movie-item" data-type="<?= htmlspecialchars($type) ?>" data-search="<?= htmlspecialchars($search) ?>">
        <img src="<?= htmlspecialchars($poster) ?>" alt="<?= htmlspecialchars($title) ?>" class="movie-poster" onerror="this.src='https://via.placeholder.com/200x300?text=No+Image'">
        <div class="movie-info">
            <div class="movie-title"><?= htmlspecialchars($title) ?></div>
            <div class="movie-details">
                <?= htmlspecialchars($year) ?> | <?= strtoupper(htmlspecialchars($type)) ?><br>
                ⭐ <?= htmlspecialchars($rating) ?><br>
                ID: <?= htmlspecialchars($imdb) ?>
            </div>

            <div class="platform-edit">
                <label class="platform-label">Platform</label>
                <form method="POST" class="platform-form">
                    <input type="hidden" name="action" value="update_platform">
                    <input type="hidden" name="imdb_id" value="<?= htmlspecialchars($imdb) ?>">
                    <select name="platform" onchange="this.form.submit()">
                        <option value="all" <?= $curPlat==='all' ? 'selected' : '' ?>>All</option>
                        <option value="netflix" <?= $curPlat==='netflix' ? 'selected' : '' ?>>Netflix</option>
                        <option value="vivamax" <?= $curPlat==='vivamax' ? 'selected' : '' ?>>VivaMax</option>
                        <option value="disneyplus" <?= $curPlat==='disneyplus' ? 'selected' : '' ?>>Disney+</option>
                        <option value="primevideo" <?= $curPlat==='primevideo' ? 'selected' : '' ?>>Prime Video</option>
                        <option value="hulu" <?= $curPlat==='hulu' ? 'selected' : '' ?>>Hulu</option>
                                                <option value="hbomax" <?= $curPlat==='hbomax' ? 'selected' : '' ?>>HBO Max</option>
                        <option value="appletvplus" <?= $curPlat==='appletvplus' ? 'selected' : '' ?>>Apple TV+</option>
                        <option value="warnerbros" <?= $curPlat==='warnerbros' ? 'selected' : '' ?>>Warner Bros</option>
                    </select>
                </form>
            </div>

            <form method="POST" class="latest-controls">
                <input type="hidden" name="action" value="update_latest">
                <input type="hidden" name="imdb_id" value="<?= htmlspecialchars($imdb) ?>">
                <label>
                    <input type="checkbox" name="show_in_latest" <?= (!empty($movie['show_in_latest'])) ? 'checked' : '' ?>>
                    Show in Latest Home
                </label>
                <label>
                    Latest Order (1–10)
                    <input type="number" name="latest_order" min="1" max="10"
                        value="<?= isset($movie['latest_order']) ? (int)$movie['latest_order'] : '' ?>"
                        <?= (!empty($movie['show_in_latest'])) ? '' : 'disabled' ?>>
                </label>
                <button type="submit" class="btn btn-small">💾 Save</button>
            </form>

            <form method="POST" class="downloads-controls">
                <input type="hidden" name="action" value="update_downloads">
                <input type="hidden" name="imdb_id" value="<?= htmlspecialchars($imdb) ?>">

                <?php $dl = $movie['downloads'] ?? []; ?>

                <div class="dl-title">⬇️ Download Links (optional)</div>

                <div class="dl-rows">
                    <?php for ($i=0; $i<3; $i++):
                        // Backward compatibility: older builds used {label,url}
                        $row = $dl[$i] ?? ['server'=>'','password'=>'','quality'=>'','url'=>''];
                        $server = $row['server'] ?? ($row['label'] ?? '');
                        $password = $row['password'] ?? '';
                        $quality = $row['quality'] ?? '';
                        $url = $row['url'] ?? '';

                        $isHidden = ($i>0 && empty(trim(($server ?? '').($url ?? '').($password ?? '').($quality ?? ''))));
                    ?>
                        <div class="dl-row <?= $isHidden ? 'is-hidden' : '' ?>">
                            <input type="text" name="dl_server[]" placeholder="Server (e.g., Terabox)" value="<?= htmlspecialchars($server ?? '') ?>">
                            <input type="text" name="dl_password[]" placeholder="Password (e.g., sl)" value="<?= htmlspecialchars($password ?? '') ?>">
                            <input type="text" name="dl_quality[]" placeholder="Quality (e.g., HD)" value="<?= htmlspecialchars($quality ?? '') ?>">
                            <input type="url"  name="dl_url[]"   placeholder="Download URL" value="<?= htmlspecialchars($url ?? '') ?>">
                        </div>
                    <?php endfor; ?>
                </div>

                <div class="dl-actions">
                    <button type="button" class="btn btn-small add-dl-btn">➕ Add link</button>
                    <button type="submit" class="btn btn-small">💾 Save Links</button>
                </div>
            </form>

            <form method="POST" onsubmit="return confirm('Delete this entry?');" class="delete-form">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="imdb_id" value="<?= htmlspecialchars($imdb) ?>">
                <button type="submit" class="btn btn-danger btn-small">🗑 Delete</button>
            </form>
        </div>
    </div>
    <?php
}
