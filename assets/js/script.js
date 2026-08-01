document.addEventListener('DOMContentLoaded', function () {
    if (typeof InitRPData === 'undefined' || !InitRPData.postId) return;

    const postId         = InitRPData.postId;
    const delay           = InitRPData.delay || 1000;
    const isLoggedIn      = !!InitRPData.loggedIn;
    const savedPositions  = InitRPData.savedPositions || {};
    const storageKey      = 'init_rp_' + postId;
    const restBase        = ((InitRPData.restUrl ? String(InitRPData.restUrl) : '/wp-json/initrepo/v1').replace(/\/$/, '')) + '/scroll';
    const headersJSON     = {
        'Content-Type': 'application/json',
        'X-WP-Nonce': InitRPData.nonce || ''
    };

    // Auto-clear at end of content area (from localized PHP; default ON)
    const autoClearOnEnd = !!InitRPData.autoClearOnEnd;

    // Multiple selectors support: ".entry-content, .post-content, #main"
    const selectors = (InitRPData && typeof InitRPData.selector === 'string')
        ? InitRPData.selector.split(',').map(s => s.trim()).filter(Boolean)
        : [];

    // Resolve elements once on load.
    const scopeElements = selectors.length
        ? selectors.map(sel => document.querySelector(sel)).filter(el => el && el.isConnected)
        : [];

    // ─── Cache absolute bounds (scoped elements + full page height) ───────────
    // getBoundingClientRect() and *.scrollHeight reads are layout-forcing.
    // We compute them once after DOMContentLoaded, refresh on resize (debounced)
    // and once more after full 'load' (images can grow the page afterwards),
    // so scroll handlers only ever do arithmetic, never layout queries.

    /** @type {Array<{absTop: number, absBottom: number}>} */
    let scopeBounds = [];
    let pageHeight  = 0;

    function refreshBounds() {
        const viewportY = window.scrollY || window.pageYOffset || 0;

        scopeBounds = scopeElements.map(el => {
            const rect        = el.getBoundingClientRect();
            const absTop      = viewportY + rect.top;
            const heightGuess = Math.max(el.scrollHeight || 0, rect.height || 0, el.clientHeight || 0);
            return { absTop, absBottom: absTop + heightGuess };
        });

        pageHeight = Math.max(
            document.body.scrollHeight || 0,
            document.documentElement.scrollHeight || 0
        );
    }

    refreshBounds();

    let resizeTimer;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(refreshBounds, 200);
    }, { passive: true });

    window.addEventListener('load', refreshBounds, { passive: true, once: true });
    // ─────────────────────────────────────────────────────────────────────────

    // Single source of truth for device — JS only.
    function getDevice() {
        if (/Mobi|Android/i.test(navigator.userAgent)) return 'mobile';
        if (window.innerWidth >= 1024) return 'pc';
        return 'tablet';
    }
    const device = getDevice();
    const savedPosition = savedPositions[device] || 0;

    // Restore scroll position
    if (savedPosition > 0) {
        window.scrollTo({ top: savedPosition, behavior: 'smooth' });
    } else {
        const localPos = localStorage.getItem(storageKey);
        if (localPos) {
            const y = parseInt(localPos, 10);
            if (!Number.isNaN(y) && y > 0) {
                window.scrollTo({ top: y, behavior: 'smooth' });
            }
        }
    }

    // ─── Scroll metric helpers (use cached bounds, never touch layout) ────────

    function computePercent(y, innerH) {
        for (let i = 0; i < scopeBounds.length; i++) {
            const { absTop, absBottom } = scopeBounds[i];
            if (y >= (absTop - 1) && y < (absBottom - 1)) {
                const yRel  = Math.max(0, y - absTop);
                const denom = Math.max(1, (absBottom - absTop) - innerH);
                return Math.min(100, Math.max(0, Math.round((yRel / denom) * 100)));
            }
        }
        const denom = Math.max(1, pageHeight - innerH);
        return Math.min(100, Math.max(0, Math.round((y / denom) * 100)));
    }

    function inScope(y) {
        if (!scopeBounds.length) return true;
        for (let i = 0; i < scopeBounds.length; i++) {
            const { absTop, absBottom } = scopeBounds[i];
            if (y >= (absTop - 1) && y < (absBottom - 1)) return true;
        }
        return false;
    }

    function isNearBottomPage(y, innerH) {
        return (innerH + y) >= (pageHeight - 100);
    }

    function isNearEndOfAnyScope(y, innerH) {
        for (let i = 0; i < scopeBounds.length; i++) {
            if ((innerH + y) >= (scopeBounds[i].absBottom - 100)) return true;
        }
        return false;
    }

    // ─────────────────────────────────────────────────────────────────────────

    // ─── Behavior-based sync engine ────────────────────────────────────────────
    // Goal: talk to the server only on meaningful checkpoints instead of on a
    // fixed timer, so a reader who scrolls straight through an article and
    // leaves fires close to ONE request total, not one every few seconds.
    //
    //   - Scrolling down continuously  → hold off, just track locally
    //   - Real upward reversal (re-reading) → sync the FURTHEST point reached
    //     (never the current, lower position — so resuming never regresses)
    //   - Long continuous forward read  → occasional heartbeat, as a safety net
    //   - Tab hidden / closed          → final flush of any unsynced progress
    //   - Reaching the end of content  → clear, same as before
    //
    // REVERSE_THRESHOLD_PX filters out hand jitter so a couple of pixels of
    // wobble doesn't count as "the reader turned back". HEARTBEAT_MS bounds
    // the worst case (e.g. the tab is killed without pagehide/visibilitychange
    // ever firing) without reintroducing frequent polling.
    const REVERSE_THRESHOLD_PX = 80;
    const HEARTBEAT_MS         = 30000;
    const MIN_SEND_GAP_MS      = 1000; // hard floor between any two network sends

    let maxY     = Math.max(savedPosition, parseInt(localStorage.getItem(storageKey), 10) || 0, window.scrollY || 0);
    let syncedY  = savedPosition; // what the server already has; 0/unused for guests
    let runPeakY = maxY;
    let dir      = null; // 'down' | 'up'
    let lastSent = Date.now(); // treat page load as t0 so heartbeat doesn't fire on the first tick

    let timeout;
    let lastScrollY      = window.scrollY || window.pageYOffset || 0;
    let lastKnownInnerH  = window.innerHeight || 0;

    function sendPayload(extra) {
        fetch(restBase, {
            method: 'POST',
            headers: headersJSON,
            credentials: 'same-origin',
            body: JSON.stringify(Object.assign({ post_id: postId, device: device }, extra))
        }).catch(() => {});
    }

    /**
     * Persist `y` (always the furthest point, maxY) as the saved position.
     * Guarded by MIN_SEND_GAP_MS so a reversal-trigger and a heartbeat-trigger
     * can never double-fire back to back.
     */
    function syncPosition(y, now) {
        if (!isLoggedIn) return;
        if (now - lastSent < MIN_SEND_GAP_MS) return;
        lastSent = now;
        syncedY  = y;

        const innerH  = window.innerHeight || lastKnownInnerH;
        const percent = computePercent(y, innerH);

        sendPayload({ scroll: y, percent: percent, screen_height: innerH });
    }

    function clearPosition(now) {
        maxY     = 0;
        syncedY  = 0;
        runPeakY = 0;
        localStorage.removeItem(storageKey);

        if (isLoggedIn) {
            lastSent = now;
            sendPayload({ action: 'delete' });
        }
    }

    // Best-effort final save when the tab is hidden/closed — flushes any
    // unsynced progress (maxY) via sendBeacon so it survives page teardown.
    function sendFinalPosition() {
        if (!isLoggedIn) return;
        if (maxY <= syncedY) return; // nothing new to persist

        const now = Date.now();
        if (now - lastSent < MIN_SEND_GAP_MS) return;
        lastSent = now;
        syncedY  = maxY;

        const innerH  = window.innerHeight || lastKnownInnerH;
        const percent = computePercent(maxY, innerH);

        const payload = JSON.stringify({
            post_id:       postId,
            device:        device,
            scroll:        maxY,
            percent:       percent,
            screen_height: innerH
        });

        if (navigator.sendBeacon) {
            // sendBeacon can't set custom headers, so the REST nonce travels via
            // query string instead (WP core also accepts `_wpnonce` there). A
            // Blob with an explicit JSON type makes the request Content-Type
            // application/json so WP parses the body as JSON params.
            const url  = restBase + '?_wpnonce=' + encodeURIComponent(InitRPData.nonce || '');
            const blob = new Blob([payload], { type: 'application/json' });
            navigator.sendBeacon(url, blob);
        } else {
            fetch(restBase, {
                method:      'POST',
                headers:     headersJSON,
                credentials: 'same-origin',
                keepalive:   true,
                body:        payload
            }).catch(() => {});
        }
    }

    window.addEventListener('pagehide', sendFinalPosition);
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') sendFinalPosition();
    });

    // ─────────────────────────────────────────────────────────────────────────

    window.addEventListener('scroll', () => {
        clearTimeout(timeout);
        timeout = setTimeout(() => {
            const viewportY   = window.scrollY || window.pageYOffset || 0;
            const innerHeight = window.innerHeight || 0;
            const now         = Date.now();

            lastKnownInnerH = innerHeight;

            if (viewportY > lastScrollY) {
                dir = 'down';
            } else if (viewportY < lastScrollY) {
                dir = 'up';
            }

            // autoClearOnEnd=true + no selector configured → fall through to page-bottom check.
            const nearEndScope = autoClearOnEnd && scopeBounds.length > 0 && isNearEndOfAnyScope(viewportY, innerHeight);
            const nearEndPage  = autoClearOnEnd && scopeBounds.length === 0 && isNearBottomPage(viewportY, innerHeight);

            if (nearEndScope || nearEndPage) {
                clearPosition(now);
                lastScrollY = viewportY;
                return;
            }

            if (inScope(viewportY)) {
                if (dir === 'down') {
                    runPeakY = Math.max(runPeakY, viewportY);
                    maxY     = runPeakY;
                    localStorage.setItem(storageKey, String(maxY));

                    // Still reading forward, but it's been a while since the last
                    // sync — send a heartbeat so a crash/kill can't lose too much.
                    if (maxY > syncedY && (now - lastSent) >= HEARTBEAT_MS) {
                        syncPosition(maxY, now);
                    }
                } else if (dir === 'up') {
                    const reversedBy = runPeakY - viewportY;
                    if (reversedBy >= REVERSE_THRESHOLD_PX && maxY > syncedY) {
                        // Real backscroll (re-reading), not hand jitter — persist
                        // the furthest point reached, not this lower position.
                        syncPosition(maxY, now);
                    }
                }
            }

            lastScrollY = viewportY;
        }, delay);
    }, { passive: true });
});
