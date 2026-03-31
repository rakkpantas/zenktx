<?php if(!empty($movie)): ?>
<div class="meta-grid">
  <div><b>Genre:</b> <?=$movie['genre']?></div>
  <div><b>Runtime:</b> <?=$movie['runtime']?></div>
  <div><b>Director:</b> <?=$movie['director']?></div>
  <div><b>Actors:</b> <?=$movie['actors']?></div>
  <p><?=$movie['plot']?></p>
</div>
<?php endif; ?>
