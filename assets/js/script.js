document.addEventListener('DOMContentLoaded', function () {
    if (typeof InitRPData === 'undefined' || !InitRPData.postId) return;

    const postId        = InitRPData.postId;
    const delay         = InitRPData.delay || 1000;
    const isLoggedIn    = !!InitRPData.loggedIn;
    const savedPosition = InitRPData.savedPosition || 0;
    const storageKey    = 'init_rp_' + postId;
    const restBase      = ((InitRPData.restUrl ? String(InitRPData.restUrl) : '/wp-json/initrepo/v1').replace(/\/$/, '')) + '/scroll';
    const headersJSON   = {
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

    // ─── Cache absolute bounds per element ───────────────────────────────────
    // getBoundingClientRect() is expensive on every scroll tick.
    // We compute absTop/absBottom once after DOMContentLoaded (and on resize)
    // so scroll handlers only do arithmetic, never layout queries.

    /** @type {Array<{absTop: number, absBottom: number}>} */
    let scopeBounds = [];

    function refreshScopeBounds() {
        const viewportY = window.scrollY || window.pageYOffset || 0;
        scopeBounds = scopeElements.map(el => {
            const rect       = el.getBoundingClientRect();
            const absTop     = viewportY + rect.top;
            const heightGuess = Math.max(el.scrollHeight || 0, rect.height || 0, el.clientHeight || 0);
            return { absTop, absBottom: absTop + heightGuess };
        });
    }

    refreshScopeBounds();

    // Debounced resize so we don't hammer layout on every px change.
    let resizeTimer;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(refreshScopeBounds, 200);
    });
    // ─────────────────────────────────────────────────────────────────────────

    let timeout;
    let lastScrollY = window.scrollY || window.pageYOffset;
    let lastSent    = 0;
    const SEND_INTERVAL = 5000;

    // Single source of truth for device — JS only.
    // PHP no longer guesses via UA sniffing; this value is sent with every REST call.
    function getDevice() {
        if (/Mobi|Android/i.test(navigator.userAgent)) return 'mobile';
        if (window.innerWidth >= 1024) return 'pc';
        return 'tablet';
    }
    const device = getDevice();

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

    // ─── Scroll metric helpers (use cached bounds) ────────────────────────────

    function computePercent(viewportY, innerH) {
        for (let i = 0; i < scopeBounds.length; i++) {
            const { absTop, absBottom } = scopeBounds[i];
            if (viewportY >= (absTop - 1) && viewportY < (absBottom - 1)) {
                const yRel = Math.max(0, viewportY - absTop);
                const denom = Math.max(1, (absBottom - absTop) - innerH);
                return Math.min(100, Math.max(0, Math.round((yRel / denom) * 100)));
            }
        }
        // Fallback: whole page
        const scrollHeight = Math.max(
            document.body.scrollHeight || 0,
            document.documentElement.scrollHeight || 0
        );
        const denom = Math.max(1, scrollHeight - innerH);
        return Math.min(100, Math.max(0, Math.round((viewportY / denom) * 100)));
    }

    function inScope(viewportY) {
        if (!scopeBounds.length) return true;
        for (let i = 0; i < scopeBounds.length; i++) {
            const { absTop, absBottom } = scopeBounds[i];
            if (viewportY >= (absTop - 1) && viewportY < (absBottom - 1)) return true;
        }
        return false;
    }

    function isNearBottomPage(viewportY, innerH) {
        const scrollHeight = Math.max(
            document.body.scrollHeight || 0,
            document.documentElement.scrollHeight || 0
        );
        return (innerH + viewportY) >= (scrollHeight - 100);
    }

    // Near end of ANY scoped element (uses cached bounds)
    function isNearEndOfAnyScope(viewportY, innerH) {
        for (let i = 0; i < scopeBounds.length; i++) {
            if ((innerH + viewportY) >= (scopeBounds[i].absBottom - 100)) return true;
        }
        return false;
    }

    // ─────────────────────────────────────────────────────────────────────────

    window.addEventListener('scroll', () => {
        clearTimeout(timeout);
        timeout = setTimeout(() => {
            const viewportY    = window.scrollY || window.pageYOffset || 0;
            const innerHeight  = window.innerHeight || 0;
            const percent      = computePercent(viewportY, innerHeight);

            // FIX: autoClearOnEnd=true + no selector → fall through to page-bottom check.
            // Old logic: (nearEndScope || (!autoClearOnEnd && nearBottomPage))
            //   → when autoClearOnEnd=true but no selector, nearEndScope=false and the
            //     second branch was also false, so clear NEVER fired. Bug fixed below.
            const nearEndScope  = autoClearOnEnd && scopeBounds.length > 0 && isNearEndOfAnyScope(viewportY, innerHeight);
            const nearEndPage   = autoClearOnEnd && scopeBounds.length === 0 && isNearBottomPage(viewportY, innerHeight);
            const shouldClear   = nearEndScope || nearEndPage;

            if (shouldClear) {
                if (isLoggedIn) {
                    fetch(restBase, {
                        method: 'POST',
                        headers: headersJSON,
                        credentials: 'same-origin',
                        body: JSON.stringify({
                            post_id: postId,
                            device:  device,
                            action:  'delete'
                        })
                    }).catch(() => {});
                }
                localStorage.removeItem(storageKey);
                return;
            }

            // Only save when scrolling down AND within any scoped element (if provided)
            if (viewportY > lastScrollY && inScope(viewportY)) {
                localStorage.setItem(storageKey, String(viewportY));

                if (isLoggedIn) {
                    const now = Date.now();
                    if (now - lastSent > SEND_INTERVAL) {
                        lastSent = now;
                        fetch(restBase, {
                            method: 'POST',
                            headers: headersJSON,
                            credentials: 'same-origin',
                            body: JSON.stringify({
                                post_id:       postId,
                                device:        device,
                                scroll:        viewportY,
                                percent:       percent,
                                screen_height: innerHeight
                            })
                        }).catch(() => {});
                    }
                }
            }

            lastScrollY = viewportY;
        }, delay);
    });
});
