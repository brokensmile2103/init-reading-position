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

    // Optional selector
    const selector = (InitRPData && typeof InitRPData.selector === 'string') ? InitRPData.selector.trim() : '';
    const scopeEl = selector ? document.querySelector(selector) : null;

    let timeout;
    let lastScrollY = window.scrollY || window.pageYOffset;

    function getDevice() {
        if (/Mobi|Android/i.test(navigator.userAgent)) return 'Mobile';
        if (window.innerWidth >= 1024) return 'PC';
        return 'Tablet';
    }
    const device = getDevice();

    // Restore
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

    // percent theo selector nếu có; KHÔNG dùng cho nearBottom
    function computePercent(viewportY, innerH) {
        if (scopeEl && scopeEl.isConnected) {
            const rect = scopeEl.getBoundingClientRect();
            const scopeTop = viewportY + rect.top;
            const scopeHeight = Math.max(
                1,
                scopeEl.scrollHeight ||
                Math.max(rect.height || 0, scopeEl.clientHeight || 0)
            );
            const yRel = Math.max(0, viewportY - scopeTop);
            const denom = Math.max(1, scopeHeight - innerH);
            return Math.min(100, Math.max(0, Math.round((yRel / denom) * 100)));
        } else {
            const scrollHeight = Math.max(
                document.body.scrollHeight || 0,
                document.documentElement.scrollHeight || 0
            );
            const denom = Math.max(1, scrollHeight - innerH);
            return Math.min(100, Math.max(0, Math.round((viewportY / denom) * 100)));
        }
    }

    // NEW: chỉ “được phép lưu” khi viewport đang nằm trong vùng selector (nếu có)
    function inScope(viewportY, innerH) {
        if (!(scopeEl && scopeEl.isConnected)) return true; // không có selector => hành vi cũ
        const rect = scopeEl.getBoundingClientRect();
        const scopeTop = viewportY + rect.top;
        const scopeHeight = Math.max(
            rect.height || 0,
            scopeEl.scrollHeight || 0,
            scopeEl.clientHeight || 0
        );
        const scopeBottom = scopeTop + scopeHeight;

        // Dùng mép TRÊN của viewport để quyết định: 
        // khi top của viewport đã đi qua đáy scope => coi như ra ngoài, NGỪNG LƯU
        return (viewportY >= (scopeTop - 1)) && (viewportY < (scopeBottom - 1));
    }

    // nearBottom của TOÀN TRANG (dùng để XOÁ)
    function isNearBottomPage(viewportY, innerH) {
        const scrollHeight = Math.max(
            document.body.scrollHeight || 0,
            document.documentElement.scrollHeight || 0
        );
        return (innerH + viewportY) >= (scrollHeight - 100);
    }

    window.addEventListener('scroll', () => {
        clearTimeout(timeout);
        timeout = setTimeout(() => {
            const viewportY = window.scrollY || window.pageYOffset || 0;
            const innerHeight = window.innerHeight || 0;

            const percent = computePercent(viewportY, innerHeight);
            const nearBottomPage = isNearBottomPage(viewportY, innerHeight);

            if (nearBottomPage) {
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

            // Giữ điều kiện cũ: chỉ lưu khi cuộn xuống
            // NEW: và CHỈ khi đang ở trong vùng selector (nếu có)
            if (viewportY > lastScrollY && inScope(viewportY, innerHeight)) {
                localStorage.setItem(storageKey, String(viewportY));

                if (isLoggedIn) {
                    fetch(restBase, {
                        method: 'POST',
                        headers: headersJSON,
                        credentials: 'same-origin',
                        body: JSON.stringify({
                            post_id: postId,
                            device: device,
                            scroll: viewportY,       // y theo window (giữ nguyên)
                            percent: percent,        // percent scoped nếu có selector
                            screen_height: innerHeight
                        })
                    });
                }
            }

            lastScrollY = viewportY;
        }, delay);
    });
});
