// FlixMo JavaScript Functions

// Inline SVG placeholder for missing/broken posters
const ZP_PLACEHOLDER_POSTER = 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(
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


// Search functionality (smooth + optimized)
let __flixmoSearch = {
    cards: null,
    lastQuery: null,
};

function initSearchCache() {
    // Cache cards once to avoid querying on every keypress
    __flixmoSearch.cards = Array.from(document.querySelectorAll('.movie-card'));
}

// Apply a lightweight "fade-in" only to cards that become visible
function applyShowAnimation(card) {
    card.classList.remove('flixmo-fade-in');
    // force reflow so animation restarts only when needed
    void card.offsetWidth;
    card.classList.add('flixmo-fade-in');
}

function searchContent() {
    const input = document.getElementById('searchInput');
    if (!input) return;

    const query = (input.value || '').toLowerCase().trim();
    if (__flixmoSearch.lastQuery === query) return;
    __flixmoSearch.lastQuery = query;

    const cards = __flixmoSearch.cards || Array.from(document.querySelectorAll('.movie-card'));
    let visibleCount = 0;

    // Batch DOM writes for smoother typing
    window.requestAnimationFrame(() => {
        for (const card of cards) {
            const titleEl = card.querySelector('.movie-title');
            const yearEl = card.querySelector('.movie-year');

            const title = titleEl ? titleEl.textContent.toLowerCase() : '';
            const year = yearEl ? yearEl.textContent.toLowerCase() : '';

            const shouldShow = query.length === 0 || title.includes(query) || year.includes(query);

            // Only update DOM if state changes
            const isHidden = card.classList.contains('flixmo-hidden');
            if (shouldShow) {
                visibleCount++;
                if (isHidden) {
                    card.classList.remove('flixmo-hidden');
                    applyShowAnimation(card);
                }
            } else {
                if (!isHidden) card.classList.add('flixmo-hidden');
            }
        }

        // Show/hide empty state
        updateEmptyState(visibleCount === 0 && query.length > 0);
    });
}

// Real-time search
document.addEventListener('DOMContentLoaded', function() {
    initSearchCache();

    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('input', debounce(searchContent, 200));
    }
});

// Debounce function for search optimization
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const context = this;
        const later = () => {
            timeout = null;
            func.apply(context, args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// Navigate to watch page
function watchMovie(imdbId) {
    window.location.href = `/watch/${imdbId}`;
}

// Add animation to movie cards on load
document.addEventListener('DOMContentLoaded', function() {
    const cards = document.querySelectorAll('.movie-card');
    cards.forEach((card, index) => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(20px)';
        setTimeout(() => {
            card.style.transition = 'all 0.5s ease';
            card.style.opacity = '1';
            card.style.transform = 'translateY(0)';
        }, index * 100);
    });
});

// =========================
// Watchlist (LocalStorage)
// =========================
let watchlist = JSON.parse(localStorage.getItem('flixmo_watchlist')) || [];

function addToWatchlist(movieData) {
    watchlist = JSON.parse(localStorage.getItem('flixmo_watchlist')) || [];
    const existingIndex = watchlist.findIndex(item => item.imdb_id === movieData.imdb_id);

    if (existingIndex === -1) {
        watchlist.push(movieData);
        localStorage.setItem('flixmo_watchlist', JSON.stringify(watchlist));
        showNotification('Added to watchlist!', 'success');
        return true;
    } else {
        showNotification('Already in watchlist!', 'info');
        return false;
    }
}

function removeFromWatchlist(imdbId) {
    watchlist = JSON.parse(localStorage.getItem('flixmo_watchlist')) || [];
    watchlist = watchlist.filter(item => item.imdb_id !== imdbId);
    localStorage.setItem('flixmo_watchlist', JSON.stringify(watchlist));
    showNotification('Removed from watchlist!', 'success');

    // Ensure list stays accurate
    setTimeout(loadWatchlist, 50);
}

function loadWatchlist() {
    const watchlistContainer = document.getElementById('watchlistContainer');
    if (!watchlistContainer) return;

    watchlist = JSON.parse(localStorage.getItem('flixmo_watchlist')) || [];

    if (watchlist.length === 0) {
        watchlistContainer.innerHTML = ``;
        return;
    }

    watchlistContainer.innerHTML = watchlist.map(movie => `
        <div class="movie-card" onclick="watchMovie('${movie.imdb_id}')">
            <div style="position: relative;">
                <img src="${movie.poster}" alt="${movie.title}" class="movie-poster" onerror="this.onerror=null;this.src=ZP_PLACEHOLDER_POSTER;">
                <span class="movie-label">${movie.type.toUpperCase()}</span>
                <button class="remove-watchlist-btn" data-remove-id="${movie.imdb_id}" type="button">✕</button>
            </div>
            <div class="movie-info">
                <h3 class="movie-title">${movie.title}</h3>
                <p class="movie-year">${movie.year} • ⭐ ${movie.rating}</p>
            </div>
        </div>
    `).join('');
}

// Watch page: Add to watchlist + redirect to Watchlist page
document.addEventListener('DOMContentLoaded', function() {
    const btn = document.getElementById('watchlistBtn');
    if (btn) {
        btn.addEventListener('click', function() {
            try{
                const b64 = btn.getAttribute('data-movie-b64');
                if (!b64) return;

                const raw = atob(b64);
                const movieData = JSON.parse(raw);

                addToWatchlist(movieData);

                btn.textContent = '✅ Added';
                btn.style.opacity = '0.9';

                setTimeout(() => {
                    window.location.href = '/watchlist';
                }, 350);
            }catch(e){
                console.error(e);
                showNotification('Could not add to watchlist.', 'error');
            }
        });
    }

    // Auto-load watchlist page
    const watchlistContainer = document.getElementById('watchlistContainer');
    if (watchlistContainer) {
        loadWatchlist();

        // Remove button delegation (instant)
        watchlistContainer.addEventListener('click', function(e){
            const btn = e.target.closest('[data-remove-id]');
            if(!btn) return;
            e.preventDefault();
            e.stopPropagation();

            const imdbId = btn.getAttribute('data-remove-id');
            const card = btn.closest('.movie-card');
            if(card){
                card.style.transition = 'all 0.2s ease';
                card.style.opacity = '0';
                card.style.transform = 'scale(0.98)';
                setTimeout(() => card.remove(), 180);
            }
            removeFromWatchlist(imdbId);
        });
    }
});


// =========================
// Share buttons (Watch page)
// =========================
document.addEventListener('DOMContentLoaded', function() {
    const fbBtn = document.getElementById('shareFbBtn');
    const xBtn = document.getElementById('shareXBtn');
    const watchBtn = document.getElementById('watchlistBtn');

    if (!fbBtn || !xBtn || !watchBtn) return;

    function getMovieData(){
        const b64 = watchBtn.getAttribute('data-movie-b64');
        if (!b64) return null;
        try{
            return JSON.parse(atob(b64));
        }catch(e){
            return null;
        }
    }

    function buildShareText(){
        const data = getMovieData();
        if (!data) return 'Watch this on FlixMo!';
        return `Watch ${data.title} (${data.year}) on FlixMo!`;
    }

    function getShareUrl(){
        // Share the current watch page URL
        return window.location.href;
    }

    fbBtn.addEventListener('click', function(){
        const shareUrl = encodeURIComponent(getShareUrl());
        const quote = encodeURIComponent(buildShareText());
        window.open(`https://www.facebook.com/sharer/sharer.php?u=${shareUrl}&quote=${quote}`, '_blank');
    });

    xBtn.addEventListener('click', function(){
        const shareUrl = encodeURIComponent(getShareUrl());
        const text = encodeURIComponent(buildShareText());
        window.open(`https://twitter.com/intent/tweet?url=${shareUrl}&text=${text}`, '_blank');
    });
});


// =========================
// Genre dropdown filter
// =========================
function applyGenreFilter(){
    const sel = document.getElementById('genreSelect');
    if(!sel) return;

    const genre = sel.value || '';
    const url = new URL(window.location.href);

    if(genre){
        url.searchParams.set('genre', genre);
    }else{
        url.searchParams.delete('genre');
    }

    // Smooth (no refresh) for Movies/TV pages that use infinite.js
    // Keep the URL in sync for sharing/back button.
    try {
        window.history.replaceState({}, '', url.toString());
    } catch (e) {}

    if(typeof window.setGenre === 'function'){
        window.setGenre(genre);
        return;
    }

    // Fallback to old behavior if infinite API isn't present.
    window.location.href = url.toString();
}

// =========================
// Year dropdown filter
// =========================
function applyYearFilter(){
    const sel = document.getElementById('yearSelect');
    if(!sel) return;

    const year = (sel.value || '').trim();
    const url = new URL(window.location.href);

    if(year){
        url.searchParams.set('year', year);
    }else{
        url.searchParams.delete('year');
    }

    // Keep URL in sync (for sharing/back button).
    try { window.history.replaceState({}, '', url.toString()); } catch (e) {}

    if(typeof window.setYear === 'function'){
        window.setYear(year);
        return;
    }

    window.location.href = url.toString();
}


document.addEventListener('DOMContentLoaded', function(){
    const sel = document.getElementById('genreSelect');
    if(!sel) return;

    const url = new URL(window.location.href);
    const genre = url.searchParams.get('genre');
    if(genre){
        sel.value = genre;
    }
});


// =========================
// Smooth drag-scroll for Trending chips (mouse + touch)
// =========================
document.addEventListener('DOMContentLoaded', function(){
    const wrap = document.querySelector('.trending-chips');
    if(!wrap) return;

    // If chips fit OR the layout wraps, no need for drag-scroll.
    const isNoWrap = () => getComputedStyle(wrap).flexWrap === 'nowrap';
    const needsScroll = () => isNoWrap() && (wrap.scrollWidth > wrap.clientWidth + 4);
    const setCursor = () => {
        wrap.style.cursor = needsScroll() ? 'grab' : 'default';
    };
    setCursor();
    window.addEventListener('resize', setCursor, { passive: true });

    // Drag state
    let isDown = false;
    let startX = 0;
    let startY = 0;
    let scrollLeft = 0;
    let hasDragged = false;
    const DRAG_THRESHOLD = 6; // px

    const down = (pageX, pageY) => {
        if(!needsScroll()) return;
        isDown = true;
        hasDragged = false;
        // Don't disable clicks immediately. We'll mark as dragging only after threshold.
        wrap.style.cursor = 'grabbing';
        startX = pageX;
        startY = pageY;
        scrollLeft = wrap.scrollLeft;
    };

    const move = (pageX) => {
        if(!isDown) return;
        const walk = (pageX - startX);
        if(!hasDragged && Math.abs(walk) >= DRAG_THRESHOLD){
            hasDragged = true;
            // Mark as dragging so chip clicks don't accidentally fire.
            wrap.dataset.dragging = '1';
            wrap.classList.add('is-dragging');
        }
        wrap.scrollLeft = scrollLeft - walk;
    };

    const up = () => {
        if(!isDown) return;
        isDown = false;
        wrap.classList.remove('is-dragging');
        setCursor();

        // Allow clicks again shortly after drag ends.
        if(wrap.dataset.dragging){
            window.setTimeout(() => {
                delete wrap.dataset.dragging;
            }, 120);
        }
    };

    // Mouse
    wrap.addEventListener('mousedown', (e) => {
        // Don't start dragging when clicking a chip (let it click)
        if(e.button !== 0) return;
        down(e.pageX, e.pageY);
    });
    window.addEventListener('mousemove', (e) => move(e.pageX), { passive: true });
    window.addEventListener('mouseup', up, { passive: true });
    wrap.addEventListener('mouseleave', up, { passive: true });

    // Touch
    wrap.addEventListener('touchstart', (e) => {
        if(!e.touches || !e.touches[0]) return;
        down(e.touches[0].pageX, e.touches[0].pageY);
    }, { passive: true });
    // NOTE: non-passive so we can prevent page scroll when swiping chips horizontally.
    wrap.addEventListener('touchmove', (e) => {
        if(!e.touches || !e.touches[0]) return;
        if(!isDown || !needsScroll()) return;

        const t = e.touches[0];
        const dx = t.pageX - startX;
        const dy = t.pageY - startY;

        // If the gesture is primarily horizontal, keep the scroll inside the chips row.
        if(Math.abs(dx) > Math.abs(dy)){
            e.preventDefault();
            move(t.pageX);
        }
        // Otherwise let the page scroll normally (vertical gesture).
    }, { passive: false });
    wrap.addEventListener('touchend', up, { passive: true });
    wrap.addEventListener('touchcancel', up, { passive: true });

    // If finger/mouse is released outside the chips area, still reset.
    window.addEventListener('touchend', up, { passive: true });
    window.addEventListener('touchcancel', up, { passive: true });

    // Suppress accidental chip click after a drag (capture phase).
    wrap.addEventListener('click', (e) => {
        if(wrap.dataset.dragging){
            e.preventDefault();
            e.stopPropagation();
        }
    }, true);

    // (kept intentionally minimal; don't duplicate listeners)
});


// =========================
// Trending Now genre chips filter (client-side)
// =========================
function filterTrendingGenre(genre){
    // Navigate using a query param so results are consistent (server-side filter).
    // Also keeps the chip active after refresh.
    try {
        const url = new URL(window.location.href);
        const g = (genre || '').trim();
        if (g) url.searchParams.set('tgenre', g);
        else url.searchParams.delete('tgenre');
        url.hash = 'most-watched';
        window.location.href = url.toString();
        return;
    } catch (e) {
        // Fallback (should be rare)
        window.location.href = 'index.php' + (genre ? ('?tgenre=' + encodeURIComponent(genre)) : '') + '#most-watched';
    }
}



// ----------------------------
// Comments + Report + Account Auth
// ----------------------------
let _authMode = 'login';

function openAuthModal(mode) {
    _authMode = (mode === 'signup') ? 'signup' : 'login';

    const modal = document.getElementById('loginModal');
    const title = document.getElementById('authTitle');
    const tabLogin = document.getElementById('authTabLogin');
    const tabSignup = document.getElementById('authTabSignup');
    const nameWrap = document.getElementById('authNameWrap');
    const confirmWrap = document.getElementById('authConfirmWrap');
    const cta = document.getElementById('authCta');

    if (title) title.textContent = _authMode === 'signup' ? 'Create your account' : 'Welcome back';
    if (tabLogin) tabLogin.classList.toggle('active', _authMode === 'login');
    if (tabSignup) tabSignup.classList.toggle('active', _authMode === 'signup');

    if (nameWrap) nameWrap.style.display = (_authMode === 'signup') ? 'block' : 'none';
    if (confirmWrap) confirmWrap.style.display = (_authMode === 'signup') ? 'block' : 'none';
    if (cta) cta.textContent = (_authMode === 'signup') ? 'Create account' : 'Login';

    // Clear inputs for a cleaner feel
    const email = document.getElementById('authEmail');
    const pass = document.getElementById('authPassword');
    const conf = document.getElementById('authConfirm');
    const name = document.getElementById('authName');
    if (email) email.value = email.value || '';
    if (pass) pass.value = '';
    if (conf) conf.value = '';
    if (name) name.value = name.value || '';

    if (modal) modal.style.display = 'flex';
}

function closeLoginModal() {
    const modal = document.getElementById('loginModal');
    if (modal) modal.style.display = 'none';
}

async function doAuth() {
    const name = (document.getElementById('authName')?.value || '').trim();
    const email = (document.getElementById('authEmail')?.value || '').trim();
    const password = (document.getElementById('authPassword')?.value || '');
    const confirm = (document.getElementById('authConfirm')?.value || '');

    if (!email) {
        alert('Please enter your email.');
        return;
    }
    if (!password || password.length < 6) {
        alert('Password must be at least 6 characters.');
        return;
    }
    if (_authMode === 'signup' && password !== confirm) {
        alert('Passwords do not match.');
        return;
    }

    const fd = new FormData();
    fd.append('mode', _authMode);
    fd.append('email', email);
    fd.append('password', password);
    if (_authMode === 'signup') fd.append('name', name);

    const btn = document.getElementById('authCta');
    const old = btn ? btn.textContent : '';
    if (btn) {
        btn.disabled = true;
        btn.textContent = (_authMode === 'signup') ? 'Creating...' : 'Logging in...';
    }

    try {
        const res = await fetch('auth_login.php', { method: 'POST', body: fd });
        const data = await res.json().catch(() => null);

        if (!data || !data.ok) {
            alert(data?.message || 'Something went wrong.');
            return;
        }
        location.reload();
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.textContent = old;
        }
    }
}

async function logoutUser() {
    await fetch('auth_logout.php', { method: 'POST' }).catch(() => {});
    location.reload();
}
function openReportModal() {
    const modal = document.getElementById('reportModal');
    if (modal) modal.style.display = 'flex';
}

function closeReportModal() {
    const modal = document.getElementById('reportModal');
    if (modal) modal.style.display = 'none';
}

async function submitReport(imdbId, title, type) {
    const reason = (document.getElementById('reportReason')?.value || '').trim();
    const other = (document.getElementById('reportOther')?.value || '').trim();
    const msg = document.getElementById('reportMsg');

    const finalReason = other ? (reason + ' — ' + other) : reason;

    const fd = new FormData();
    fd.append('imdb_id', imdbId);
    fd.append('title', title);
    fd.append('type', type);
    fd.append('reason', finalReason);

    const res = await fetch('report_submit.php', { method: 'POST', body: fd });
    const data = await res.json().catch(() => null);

    if (!data || !data.ok) {
        if (msg) msg.textContent = data?.message || 'Failed to submit report.';
        return;
    }

    if (msg) msg.textContent = '✅ Report submitted. Admin will be notified.';
    setTimeout(() => {
        closeReportModal();
        if (msg) msg.textContent = '';
        document.getElementById('reportOther').value = '';
    }, 1200);
}

async function submitComment(imdbId) {
    const text = (document.getElementById('commentText')?.value || '').trim();
    const msg = document.getElementById('commentMsg');

    if (!text) {
        if (msg) msg.textContent = 'Write something first.';
        return;
    }

    const fd = new FormData();
    fd.append('imdb_id', imdbId);
    fd.append('comment', text);

    const res = await fetch('comment_submit.php', { method: 'POST', body: fd });
    const data = await res.json().catch(() => null);

    if (!data || !data.ok) {
        if (msg) msg.textContent = data?.message || 'Failed to post comment.';
        return;
    }

    if (msg) msg.textContent = '✅ Posted!';
    setTimeout(() => location.reload(), 500);
}

// Close modals when clicking outside
document.addEventListener('click', (e) => {
    const login = document.getElementById('loginModal');
    const report = document.getElementById('reportModal');

    if (login && e.target === login) closeLoginModal();
    if (report && e.target === report) closeReportModal();
});


// Likes (server-backed: one like per account per comment)
function toggleLike(commentId, btn){
  // Disallow likes when not logged in
  if (typeof window !== 'undefined' && window.FLIXMO_LOGGED_IN === false) {
    promptLogin();
    return;
  }
  if(!commentId) return;

  // One-like-per-account: if already liked, do nothing
  if (btn && (btn.dataset.liked === '1' || btn.disabled)) return;

  const fd = new FormData();
  fd.append('comment_id', commentId);

  fetch('like_comment.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      if(!res || !res.ok){
        alert((res && res.message) ? res.message : 'Failed to like.');
        return;
      }
      const el = document.getElementById('likeCount_' + commentId);
      if(el && typeof res.count !== 'undefined') el.textContent = res.count;

      if(btn){
        btn.dataset.liked = '1';
        btn.classList.add('disabled');
        btn.disabled = true;
        btn.setAttribute('aria-disabled', 'true');
        btn.textContent = '✅ Liked';
      }
    })
    .catch(() => alert('Network error. Please try again.'));
}













// Platform cards: open platform page
document.addEventListener("click", function(e){
  const btn = e.target.closest(".platform-card");
  if(!btn) return;

  const platform = (btn.getAttribute("data-platform") || "all").toLowerCase();
  window.location.href = "platform.php?name=" + encodeURIComponent(platform);
});



// ===== Hero Spotlight (10 random picks on Home) =====
function initHeroSpotlight(items){
    const root = document.getElementById('heroSpotlight');
    if (!root || !Array.isArray(items) || items.length === 0) return;

    const bg = root.querySelector('.hero-bg');
    const artImg = document.getElementById('heroArtImg');
    const titleEl = document.getElementById('heroTitle');
    const metaEl = document.getElementById('heroMeta');
    const descEl = document.getElementById('heroDesc');
    const thumbsEl = document.getElementById('heroThumbs');
    const playBtn = document.getElementById('heroPlayBtn');
    const moreBtn = document.getElementById('heroMoreBtn');

const thumbsLeft = document.getElementById('heroThumbsLeft');
const thumbsRight = document.getElementById('heroThumbsRight');

function updateThumbArrows(){
    if (!thumbsEl || !thumbsLeft || !thumbsRight) return;
    const max = thumbsEl.scrollWidth - thumbsEl.clientWidth;
    const leftDisabled = thumbsEl.scrollLeft <= 2;
    const rightDisabled = thumbsEl.scrollLeft >= (max - 2);
    thumbsLeft.disabled = leftDisabled;
    thumbsRight.disabled = rightDisabled;
    thumbsLeft.classList.toggle('is-disabled', leftDisabled);
    thumbsRight.classList.toggle('is-disabled', rightDisabled);
}


    let idx = 0;
    let timer = null;

    function pill(text){
        const span = document.createElement('span');
        span.className = 'hero-pill';
        span.textContent = text;
        return span;
    }

    function render(i){
        idx = i;
        const item = items[idx] || items[0];

        if (bg && item.poster) bg.style.backgroundImage = `url('${item.poster}')`;
        if (artImg) {
            artImg.src = item.poster || '';
            artImg.alt = item.title ? (item.title + ' poster') : '';
        }
        if (titleEl) titleEl.textContent = item.title || 'Featured';

        if (metaEl){
            metaEl.innerHTML = '';
            if (item.rating) metaEl.appendChild(pill(`⭐ ${item.rating}/10`));
            if (item.year) metaEl.appendChild(pill(item.year));
            if (item.type) metaEl.appendChild(pill(String(item.type).toUpperCase()));
            if (Array.isArray(item.genres) && item.genres.length){
                item.genres.slice(0,2).forEach(g => metaEl.appendChild(pill(g)));
            }
        }

        if (descEl){
            descEl.textContent = item.plot || '';
            // Reset to clamped state on each hero change
            descEl.classList.remove('expanded');
            descEl.setAttribute('data-expanded', '0');
        }

        if (playBtn){
            playBtn.onclick = (e) => {
                if (e) e.preventDefault();
                if (item.imdb_id) return watchMovie(item.imdb_id);
                if (typeof showNotification === 'function') {
                    showNotification('Sorry, this title is missing an ID. Please pick another.', 'error');
                }
                return false;
            };
        }
        if (moreBtn){
            // "See More" expands/collapses the hero description (prevents overflowing into the title area)
            moreBtn.textContent = 'See More';
            moreBtn.style.display = '';
            moreBtn.onclick = (e) => {
                e.preventDefault();
                if (!descEl) return;
                const expanded = descEl.classList.toggle('expanded');
                descEl.setAttribute('data-expanded', expanded ? '1' : '0');
                moreBtn.textContent = expanded ? 'See Less' : 'See More';
            };

            // Show the button only when the text is actually clamped (overflows 5 lines)
            requestAnimationFrame(() => {
                if (!descEl) return;
                const overflow = (descEl.scrollHeight - descEl.clientHeight) > 4;
                moreBtn.style.display = overflow ? '' : 'none';
            });
        }

        if (thumbsEl){
            const thumbs = thumbsEl.querySelectorAll('.hero-thumb');
            thumbs.forEach((t, ti) => t.classList.toggle('active', ti === idx));
        }
    }

    function buildThumbs(){
        if (!thumbsEl) return;
        // If SSR already rendered thumbs, don't wipe them (prevents late/flashy appearance).
        const existing = thumbsEl.querySelectorAll('.hero-thumb');

        function markLoaded(btn, img){
            if (!btn || !img) return;
            const done = () => {
                btn.classList.remove('is-loading');
                btn.classList.add('is-loaded');
            };
            if (img.complete) {
                requestAnimationFrame(done);
            } else {
                img.addEventListener('load', () => requestAnimationFrame(done), { once: true });
                img.addEventListener('error', () => requestAnimationFrame(done), { once: true });
            }
        }

        function wireThumb(btn, img, item, i){
            if (!btn) return;
            btn.dataset.heroIndex = String(i);
            btn.title = item.title || 'Pick';
            btn.setAttribute('aria-label', item.title || 'Pick');

            if (!img) {
                img = document.createElement('img');
                btn.appendChild(img);
            }
            img.decoding = 'async';
            img.loading = (i < 8) ? 'eager' : 'lazy';
            if (i < 8) img.setAttribute('fetchpriority', 'high');
            img.src = item.poster || '';
            img.alt = item.title || 'Poster';

            markLoaded(btn, img);

            btn.addEventListener('click', () => {
                stopAuto();
                render(i);
                startAuto();
            });
        }

        if (existing && existing.length === items.length) {
            existing.forEach((btn, i) => {
                const img = btn.querySelector('img');
                wireThumb(btn, img, items[i], i);
            });
            return;
        }

        thumbsEl.innerHTML = '';
        items.forEach((item, i) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'hero-thumb is-loading' + (i === 0 ? ' active' : '');
            const img = document.createElement('img');
            btn.appendChild(img);
            wireThumb(btn, img, item, i);
            thumbsEl.appendChild(btn);
        });
    }

    function startAuto(){
        stopAuto();
        timer = window.setInterval(() => {
            const next = (idx + 1) % items.length;
            render(next);
        }, 5500);
    }

    function stopAuto(){
        if (timer) window.clearInterval(timer);
        timer = null;
    }

    
// Arrow controls for hero picks
if (thumbsLeft && thumbsEl){
    thumbsLeft.addEventListener('click', () => {
        thumbsEl.scrollBy({ left: -320, behavior: 'smooth' });
        window.setTimeout(updateThumbArrows, 220);
    });
}
if (thumbsRight && thumbsEl){
    thumbsRight.addEventListener('click', () => {
        thumbsEl.scrollBy({ left: 320, behavior: 'smooth' });
        window.setTimeout(updateThumbArrows, 220);
    });
}
if (thumbsEl){
    thumbsEl.addEventListener('scroll', () => {
        // Throttle-ish: schedule on next frame
        window.requestAnimationFrame(updateThumbArrows);
    }, { passive: true });
}

buildThumbs();
    updateThumbArrows();
    render(0);
    startAuto();

    root.addEventListener('mouseenter', stopAuto);
    root.addEventListener('mouseleave', startAuto);
}

(function(){
    function bootHero(){
        if (window.__heroItems) initHeroSpotlight(window.__heroItems);
    }
    if (document.readyState === 'loading'){
        document.addEventListener('DOMContentLoaded', bootHero);
    } else {
        bootHero();
    }
})();
// ===== End Hero Spotlight =====
