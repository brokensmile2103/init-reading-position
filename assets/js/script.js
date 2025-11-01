document.addEventListener('DOMContentLoaded', function () {
    if (typeof InitRPData === 'undefined' || !InitRPData.postId) return;

    const postId = InitRPData.postId;
    const delay = InitRPData.delay || 1000;
    const isLoggedIn = !!InitRPData.loggedIn;
    const savedPosition = InitRPData.savedPosition || 0;
    // Giữ key cũ để không gãy dữ liệu localStorage
    const storageKey = 'init_rp_' + postId;
    const restBase = ((InitRPData.restUrl ? String(InitRPData.restUrl) : '/wp-json/initrepo/v1').replace(/\/$/, '')) + '/scroll';
    const headersJSON = {
        'Content-Type': 'application/json',
        'X-WP-Nonce': InitRPData.nonce || ''
    };

    let timeout;
    let lastScrollY = window.scrollY || window.pageYOffset;

    // Giữ device như cũ: 'Mobile' | 'PC' | 'Tablet' (khớp backend đang đọc)
    function getDevice() {
        if (/Mobi|Android/i.test(navigator.userAgent)) return 'Mobile';
        if (window.innerWidth >= 1024) return 'PC';
        return 'Tablet';
    }
    const device = getDevice();

    // Scroll to saved position
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

    window.addEventListener('scroll', () => {
        clearTimeout(timeout);
        timeout = setTimeout(() => {
            const y = window.scrollY || window.pageYOffset || 0;
            const innerHeight = window.innerHeight || 0;
            const scrollHeight = Math.max(
                document.body.scrollHeight || 0,
                document.documentElement.scrollHeight || 0
            );
            const denom = Math.max(1, scrollHeight - innerHeight); // tránh chia 0
            const percent = Math.min(100, Math.max(0, Math.round((y / denom) * 100)));
            const nearBottom = (innerHeight + y) >= (scrollHeight - 100);

            if (nearBottom) {
                if (isLoggedIn) {
                    // Giữ hành vi cũ: POST + action=delete
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
            if (y > lastScrollY) {
                localStorage.setItem(storageKey, String(y));

                if (isLoggedIn) {
                    fetch(restBase, {
                        method: 'POST',
                        headers: headersJSON,
                        credentials: 'same-origin',
                        body: JSON.stringify({
                            post_id: postId,
                            device: device,
                            scroll: y,
                            percent: percent,
                            screen_height: innerHeight
                        })
                    });
                }
            }

            lastScrollY = y;
        }, delay);
    });
});
