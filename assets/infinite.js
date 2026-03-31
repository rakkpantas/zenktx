
(() => {
  const grid = document.getElementById('contentGrid');
  const trigger = document.getElementById('loadMoreTrigger');
  const loadingEl = document.getElementById('loading');
  const type = document.body.dataset.type;

  if(!grid || !trigger || !loadingEl) return;
  if(type !== 'movie' && type !== 'tv') return;

  // Start from however many cards are already on the page (pre-rendered by PHP)
  // so we don't get stuck at 10/20 due to offset mismatch.
  // Offset should count ONLY media cards (ads inside the grid should not affect pagination)
  let offset = grid ? grid.querySelectorAll('.movie-card:not(.ad-card)').length : 0;
  let loading = false;
  let reachedEnd = false;
  // Read current filters from URL so subsequent loads match what the user sees.
  const currentUrl = new URL(window.location.href);
  let genre = (currentUrl.searchParams.get('genre') || '').trim();
  let query = (currentUrl.searchParams.get('q') || '').trim();
  let actor = (currentUrl.searchParams.get('actor') || '').trim();
  let year  = (currentUrl.searchParams.get('year') || '').trim();
  let observer = null;

  // Fallback infinite scroll in case IntersectionObserver doesn't fire
  // (some in-app browsers / older WebViews).
  let scrollFallbackBound = false;
  function bindScrollFallback(){
    if(scrollFallbackBound) return;
    scrollFallbackBound = true;

    let ticking = false;
    const onScroll = () => {
      if(ticking) return;
      ticking = true;
      requestAnimationFrame(() => {
        ticking = false;
        if(loading || reachedEnd) return;

        // If the trigger is within ~900px of viewport bottom, load more.
        const rect = trigger.getBoundingClientRect();
        if(rect.top - window.innerHeight < 900){
          loadMore();
        }
      });
    };
    window.addEventListener('scroll', onScroll, { passive: true });
    // Run once to catch short pages.
    onScroll();
  }

  function setStatus(state){
    // state: 'idle' | 'loading' | 'end'
    loadingEl.style.display = 'block';
    if(state === 'loading'){
      loadingEl.innerHTML = '⏳ Loading more...';
      loadingEl.setAttribute('aria-busy', 'true');
      return;
    }
    loadingEl.setAttribute('aria-busy', 'false');
    if(state === 'end'){
      loadingEl.innerHTML = '✅ No more results';
      return;
    }
    loadingEl.innerHTML = '⬇️ Load more';
  }

  function attachFadeIn(container){
    container.classList.add('fade-in');
  }

  function initObserver(){
    if(observer) observer.disconnect();
    if(!('IntersectionObserver' in window)){
      bindScrollFallback();
      return;
    }

    observer = new IntersectionObserver(entries => {
      if(entries[0].isIntersecting && !loading && !reachedEnd){
        loadMore();
      }
    }, {
      // Load earlier so the user never "hits the bottom" waiting for posters
      rootMargin: '700px 0px',
      threshold: 0.01
    });
    observer.observe(trigger);

    // Also bind scroll fallback as a safety net (some browsers will never
    // intersect 0-height sentinels reliably).
    bindScrollFallback();
  }

  function createCard(item){
    const safeTitle = (item && typeof item.title === 'string' && item.title.trim() !== '') ? item.title : 'Untitled';
    const safeYear = (item && (typeof item.year === 'string' || typeof item.year === 'number')) ? String(item.year) : '';
    const safeRating = (item && (typeof item.rating === 'string' || typeof item.rating === 'number')) ? String(item.rating) : '';
    const safeLabel = (item && typeof item.label === 'string' && item.label.trim() !== '') ? item.label : (type === 'tv' ? 'TV SHOW' : 'MOVIE');

    // Lightweight inline SVG placeholder (no extra file needed)
    const placeholderPoster = 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(
      `<svg xmlns="http://www.w3.org/2000/svg" width="400" height="600" viewBox="0 0 400 600">
        <defs>
          <linearGradient id="g" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0" stop-color="#1b1b2b"/>
            <stop offset="1" stop-color="#0d0d16"/>
          </linearGradient>
        </defs>
        <rect width="400" height="600" fill="url(#g)"/>
        <text x="200" y="310" text-anchor="middle" font-size="20" fill="#9aa0a6" font-family="Arial, sans-serif">No Poster</text>
      </svg>`
    );

    // Poster fallback ladder:
    // - If a TMDB image size fails, try larger sizes before giving up.
    // - Finally fall back to the inline SVG placeholder.
    const tmdbSizeOrder = ['w200','w342','w500','w780','original'];
    function tmdbNextUrl(url, attemptIdx) {
      try {
        if (typeof url !== 'string') return null;
        if (!url.includes('image.tmdb.org/t/p/')) return null;
        const m = url.match(/\/t\/p\/(w\d+|original)\//);
        if (!m) return null;
        const current = m[1];
        const startIdx = Math.max(tmdbSizeOrder.indexOf(current), 0);
        const nextIdx = startIdx + (attemptIdx || 1);
        if (nextIdx >= tmdbSizeOrder.length) return null;
        return url.replace(`/t/p/${current}/`, `/t/p/${tmdbSizeOrder[nextIdx]}/`);
      } catch (e) {
        return null;
      }
    }
    function handlePosterError(imgEl, originalUrl) {
      const url = (originalUrl || imgEl.src || '').toString();
      const tries = parseInt(imgEl.dataset.posterTry || '0', 10);
      const next = tmdbNextUrl(url, 1);
      if (next && tries < 4) {
        imgEl.dataset.posterTry = String(tries + 1);
        imgEl.src = next;
        return;
      }
      imgEl.onerror = null;
      imgEl.src = placeholderPoster;
    }

    const rawPoster = item && typeof item.poster === 'string' ? item.poster : '';
    const safePoster = (rawPoster && rawPoster.trim() !== '' && rawPoster !== 'N/A') ? rawPoster : placeholderPoster;

    const card = document.createElement('div');
    card.className = 'movie-card fade-in';
    card.onclick = () => window.watchMovie && window.watchMovie(item && item.imdb_id);

    const wrap = document.createElement('div');
    wrap.style.position = 'relative';

    const img = document.createElement('img');
    img.loading = 'lazy';
    img.src = safePoster;
    img.alt = safeTitle;
    img.className = 'movie-poster';
    img.dataset.posterTry = '0';
    img.onerror = () => handlePosterError(img, rawPoster);

    const label = document.createElement('span');
    label.className = 'movie-label';
    label.textContent = safeLabel;

    const rating = document.createElement('span');
    rating.className = 'movie-rating movie-rating-overlay';
    rating.textContent = `⭐ ${safeRating || 'N/A'}`;

    wrap.appendChild(img);
    wrap.appendChild(label);
    wrap.appendChild(rating);

    const info = document.createElement('div');
    info.className = 'movie-info';

    const titleEl = document.createElement('h3');
    titleEl.className = 'movie-title';
    titleEl.textContent = safeTitle;

    const yearEl = document.createElement('p');
    yearEl.className = 'movie-year';
    yearEl.textContent = safeYear;

    info.appendChild(titleEl);
    info.appendChild(yearEl);

    card.appendChild(wrap);
    card.appendChild(info);
    return card;
  }

  function executeScripts(container){
    // Ensure <script> inside ad HTML executes when inserted via innerHTML
    const scripts = Array.from(container.querySelectorAll('script'));
    for(const s of scripts){
      const n = document.createElement('script');
      // Copy attributes
      for(const attr of s.attributes){
        n.setAttribute(attr.name, attr.value);
      }
      n.text = s.text || '';
      s.parentNode && s.parentNode.replaceChild(n, s);
    }
  }

  function createAdCard(html){
    const card = document.createElement('div');
    card.className = 'movie-card ad-card fade-in';
    card.onclick = null;
    const slot = document.createElement('div');
    slot.className = 'ad-slot ad-movie-card';
    slot.innerHTML = html || '';
    card.appendChild(slot);
    // Try to execute scripts inside ad (if any)
    executeScripts(slot);
    return card;
  }

  function loadMore(){
    loading = true;
    setStatus('loading');

    const url = `load_more.php?format=json&type=${encodeURIComponent(type)}&offset=${offset}&genre=${encodeURIComponent(genre)}&q=${encodeURIComponent(query)}&actor=${encodeURIComponent(actor)}&year=${encodeURIComponent(year)}`;
    fetch(url, { headers: { 'Accept': 'application/json' } })
      .then(r => r.ok ? r.json() : Promise.reject())
      .then(data => {
        const items = (data && Array.isArray(data.items)) ? data.items : [];
        if(items.length === 0){
          reachedEnd = true;
          if(observer) observer.disconnect();
          setStatus('end');
          return;
        }

        const frag = document.createDocumentFragment();
        for (const item of items) {
          if(item && item.kind === 'ad'){
            frag.appendChild(createAdCard(item.html));
          } else {
            frag.appendChild(createCard(item));
            offset += 1;
          }
        }
        grid.appendChild(frag);
        // Note: offset already incremented only for media items.

        if(data && data.hasMore === false){
          reachedEnd = true;
          if(observer) observer.disconnect();
          setStatus('end');
          return;
        }
        setStatus('idle');
      })
      .catch(() => {
        // If the request fails, keep the indicator so the user can tap to retry.
        setStatus('idle');
      })
      .finally(() => {
        loading = false;
        if(!reachedEnd) setStatus('idle');
      });
  }

  // Tap/click "Load more" indicator as a manual fallback.
  loadingEl.style.cursor = 'pointer';
  loadingEl.addEventListener('click', () => {
    if(!loading && !reachedEnd) loadMore();
  });

  // Public API for inline handlers
  window.setGenre = function(g){
    genre = (g || '').trim();
    resetAndLoad();
  };

  window.setSearch = function(q){
    query = (q || '').trim();
    resetAndLoad();
  };

  window.setYear = function(y){
    year = (y || '').trim();
    resetAndLoad();
  };


  function resetAndLoad(){
    offset = 0;
    reachedEnd = false;
    grid.innerHTML = '';
    initObserver();
    loadMore();
  }

  // show bottom indicator even before the first extra load
  setStatus('idle');
  initObserver();
})();