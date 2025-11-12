document.addEventListener('DOMContentLoaded', function () {
    if (typeof InitRPData === 'undefined' || !InitRPData.postId) return;

    const postId = InitRPData.postId;
    const delay = InitRPData.delay || 1000;
    const isLoggedIn = !!InitRPData.loggedIn;
    const savedPosition = InitRPData.savedPosition || 0;
    const storageKey = 'init_rp_' + postId;
    const restBase = ((InitRPData.restUrl ? String(InitRPData.restUrl) : '/wp-json/initrepo/v1').replace(/\/$/, '')) + '/scroll';
    const headersJSON = {
        'Content-Type': 'application/json',
        'X-WP-Nonce': InitRPData.nonce || ''
    };

    // NEW: auto-clear at end of content area (from localized PHP; default ON)
    const autoClearOnEnd = !!InitRPData.autoClearOnEnd;

    // Multiple selectors support: ".entry-content, .post-content, #main"
    const selectors = (InitRPData && typeof InitRPData.selector === 'string')
        ? InitRPData.selector.split(',').map(s => s.trim()).filter(Boolean)
        : [];

    // Resolve elements once on load; if DOM mutates heavily, you can refresh this list.
    const scopeElements = selectors.length
        ? selectors.map(sel => document.querySelector(sel)).filter(el => el && el.isConnected)
        : [];

    let timeout;
    let lastScrollY = window.scrollY || window.pageYOffset;

    function getDevice() {
        if (/Mobi|Android/i.test(navigator.userAgent)) return 'Mobile';
        if (window.innerWidth >= 1024) return 'PC';
        return 'Tablet';
    }
    const device = getDevice();

    // Restore (window-based, giữ nguyên)
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

    // Utils for scoped metrics
    function getAbsTopBottom(el, viewportY) {
        const rect = el.getBoundingClientRect();
        const absTop = viewportY + rect.top;
        const heightGuess = Math.max(
            el.scrollHeight || 0,
            rect.height || 0,
            el.clientHeight || 0
        );
        const absBottom = absTop + heightGuess;
        return { absTop, absBottom, heightGuess };
    }

    // Compute percent (giữ nguyên core logic)
    function computePercent(viewportY, innerH) {
        if (scopeElements.length) {
            for (let i = 0; i < scopeElements.length; i++) {
                const el = scopeElements[i];
                const m = getAbsTopBottom(el, viewportY);
                if (viewportY >= (m.absTop - 1) && viewportY < (m.absBottom - 1)) {
                    const yRel = Math.max(0, viewportY - m.absTop);
                    const denom = Math.max(1, m.heightGuess - innerH);
                    return Math.min(100, Math.max(0, Math.round((yRel / denom) * 100)));
                }
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

    // Save allowed?
    function inScope(viewportY) {
        if (!scopeElements.length) return true;
        for (let i = 0; i < scopeElements.length; i++) {
            const m = getAbsTopBottom(scopeElements[i], viewportY);
            if (viewportY >= (m.absTop - 1) && viewportY < (m.absBottom - 1)) {
                return true;
            }
        }
        return false;
    }

    // Near bottom of the PAGE (giữ logic cũ)
    function isNearBottomPage(viewportY, innerH) {
        const scrollHeight = Math.max(
            document.body.scrollHeight || 0,
            document.documentElement.scrollHeight || 0
        );
        return (innerH + viewportY) >= (scrollHeight - 100);
    }

    // NEW: Near end of ANY scoped element (nếu có selector và bật autoClearOnEnd)
    function isNearEndOfAnyScope(viewportY, innerH) {
        if (!scopeElements.length) return false;
        for (let i = 0; i < scopeElements.length; i++) {
            const m = getAbsTopBottom(scopeElements[i], viewportY);
            if ((innerH + viewportY) >= (m.absBottom - 100)) {
                return true;
            }
        }
        return false;
    }

    window.addEventListener('scroll', () => {
        clearTimeout(timeout);
        timeout = setTimeout(() => {
            const viewportY = window.scrollY || window.pageYOffset || 0;
            const innerHeight = window.innerHeight || 0;

            const percent = computePercent(viewportY, innerHeight);

            // NEW: prefer clearing at end of content area when enabled; else fallback to page bottom
            const nearEndScope = autoClearOnEnd && isNearEndOfAnyScope(viewportY, innerHeight);
            const nearBottomPage = isNearBottomPage(viewportY, innerHeight);

            if (nearEndScope || (!autoClearOnEnd && nearBottomPage)) {
                if (isLoggedIn) {
                    fetch(restBase, {
                        method: 'POST',
                        headers: headersJSON,
                        credentials: 'same-origin',
                        body: JSON.stringify({
                            post_id: postId,
                            device: device,
                            action: 'delete'
                        })
                    });
                }
                localStorage.removeItem(storageKey);
                return;
            }

            // Only save when scrolling down AND within any scoped element (if provided)
            if (viewportY > lastScrollY && inScope(viewportY)) {
                localStorage.setItem(storageKey, String(viewportY));

                if (isLoggedIn) {
                    fetch(restBase, {
                        method: 'POST',
                        headers: headersJSON,
                        credentials: 'same-origin',
                        body: JSON.stringify({
                            post_id: postId,
                            device: device,
                            scroll: viewportY,   // keep window-based Y
                            percent: percent,    // scoped percent if in scope, else page percent
                            screen_height: innerHeight
                        })
                    });
                }
            }

            lastScrollY = viewportY;
        }, delay);
    });
});
