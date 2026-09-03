document.addEventListener('DOMContentLoaded', function () {
    const mainButton = document.querySelector('.nf-gallery-main');
    const mainImage = document.getElementById('nf_gallery_main_image');
    const thumbs = Array.from(document.querySelectorAll('.nf-gallery-thumb'));
    const mainCurrent = document.getElementById('nf_gallery_current');

    if (!mainButton || !mainImage) return;

    const images = thumbs.length
        ? thumbs.map((thumb) => thumb.dataset.full).filter(Boolean)
        : [mainImage.src];

    if (!images.length) return;

    let currentIndex = 0;

    const lightbox = document.getElementById('nf_gallery_lightbox');
    const lightboxImage = document.getElementById('nf_gallery_lightbox_image');
    const lightboxCurrent = document.getElementById('nf_gallery_lightbox_current');
    const closeButton = document.getElementById('nf_gallery_lightbox_close');
    const prevButton = document.getElementById('nf_gallery_lightbox_prev');
    const nextButton = document.getElementById('nf_gallery_lightbox_next');

    function normalizeIndex(index) {
        if (!images.length) return 0;
        return (index + images.length) % images.length;
    }

    function setImage(index, options = {}) {
        currentIndex = normalizeIndex(index);
        const url = images[currentIndex];

        mainImage.src = url;

        if (mainCurrent) {
            mainCurrent.textContent = String(currentIndex + 1);
        }

        thumbs.forEach((thumb, thumbIndex) => {
            const active = thumbIndex === currentIndex;
            thumb.classList.toggle('is-active', active);
            thumb.setAttribute('aria-selected', active ? 'true' : 'false');
        });

        if (options.scrollThumb && thumbs[currentIndex]) {
            thumbs[currentIndex].scrollIntoView({
                behavior: 'smooth',
                block: 'nearest',
                inline: 'nearest'
            });
        }

        if (lightboxImage) {
            lightboxImage.src = url;
        }

        if (lightboxCurrent) {
            lightboxCurrent.textContent = String(currentIndex + 1);
        }
    }

    thumbs.forEach((thumb, index) => {
        thumb.addEventListener('click', function () {
            setImage(index, { scrollThumb: true });
        });
    });

    function openLightbox() {
        if (!lightbox || !lightboxImage) return;

        setImage(currentIndex);
        lightbox.hidden = false;
        lightbox.setAttribute('aria-hidden', 'false');
        document.documentElement.classList.add('nf-gallery-lightbox-open');

        if (closeButton) {
            closeButton.focus({ preventScroll: true });
        }
    }

    function closeLightbox() {
        if (!lightbox) return;

        lightbox.hidden = true;
        lightbox.setAttribute('aria-hidden', 'true');
        document.documentElement.classList.remove('nf-gallery-lightbox-open');

        mainButton.focus({ preventScroll: true });
    }

    mainButton.addEventListener('click', openLightbox);

    if (closeButton) {
        closeButton.addEventListener('click', closeLightbox);
    }

    if (lightbox) {
        lightbox.addEventListener('click', function (event) {
            if (event.target === lightbox) {
                closeLightbox();
            }
        });
    }

    if (prevButton) {
        prevButton.addEventListener('click', function () {
            setImage(currentIndex - 1, { scrollThumb: true });
        });
    }

    if (nextButton) {
        nextButton.addEventListener('click', function () {
            setImage(currentIndex + 1, { scrollThumb: true });
        });
    }

    document.addEventListener('keydown', function (event) {
        if (!lightbox || lightbox.hidden) return;

        if (event.key === 'Escape') {
            closeLightbox();
        } else if (event.key === 'ArrowLeft' && images.length > 1) {
            setImage(currentIndex - 1, { scrollThumb: true });
        } else if (event.key === 'ArrowRight' && images.length > 1) {
            setImage(currentIndex + 1, { scrollThumb: true });
        }
    });

    // スマホのメイン画像左右スワイプ。
    let touchStartX = null;
    let touchStartY = null;

    mainButton.addEventListener('touchstart', function (event) {
        if (!event.touches || !event.touches[0]) return;
        touchStartX = event.touches[0].clientX;
        touchStartY = event.touches[0].clientY;
    }, { passive: true });

    mainButton.addEventListener('touchend', function (event) {
        if (
            touchStartX === null ||
            touchStartY === null ||
            !event.changedTouches ||
            !event.changedTouches[0] ||
            images.length < 2
        ) {
            touchStartX = null;
            touchStartY = null;
            return;
        }

        const dx = event.changedTouches[0].clientX - touchStartX;
        const dy = event.changedTouches[0].clientY - touchStartY;

        touchStartX = null;
        touchStartY = null;

        // 縦スクロールを邪魔しないよう横移動が明確な場合だけ切替。
        if (Math.abs(dx) < 45 || Math.abs(dx) <= Math.abs(dy)) {
            return;
        }

        if (dx < 0) {
            setImage(currentIndex + 1, { scrollThumb: true });
        } else {
            setImage(currentIndex - 1, { scrollThumb: true });
        }
    }, { passive: true });

    // Lightbox画像もスワイプ。
    if (lightboxImage) {
        let lbStartX = null;
        let lbStartY = null;

        lightboxImage.addEventListener('touchstart', function (event) {
            if (!event.touches || !event.touches[0]) return;
            lbStartX = event.touches[0].clientX;
            lbStartY = event.touches[0].clientY;
        }, { passive: true });

        lightboxImage.addEventListener('touchend', function (event) {
            if (
                lbStartX === null ||
                lbStartY === null ||
                !event.changedTouches ||
                !event.changedTouches[0] ||
                images.length < 2
            ) {
                lbStartX = null;
                lbStartY = null;
                return;
            }

            const dx = event.changedTouches[0].clientX - lbStartX;
            const dy = event.changedTouches[0].clientY - lbStartY;

            lbStartX = null;
            lbStartY = null;

            if (Math.abs(dx) < 45 || Math.abs(dx) <= Math.abs(dy)) {
                return;
            }

            if (dx < 0) {
                setImage(currentIndex + 1, { scrollThumb: true });
            } else {
                setImage(currentIndex - 1, { scrollThumb: true });
            }
        }, { passive: true });
    }
});


/* ========================================
   v0.8.3 最近人気の返礼品 計測
   - 閲覧: 1ブラウザ1商品につき1日1回
   - 申込先クリック: 同じリンクは1時間に1回
   - IP/氏名等は送信しない
======================================== */
document.addEventListener('DOMContentLoaded', function () {
    if (
        typeof NFPopularity === 'undefined' ||
        !NFPopularity ||
        !Number(NFPopularity.enabled) ||
        !Number(NFPopularity.postId) ||
        !NFPopularity.ajaxUrl
    ) {
        return;
    }

    const postId = Number(NFPopularity.postId);
    const ajaxUrl = String(NFPopularity.ajaxUrl);
    const action = String(
        NFPopularity.action || 'nf_track_furusato_popularity'
    );

    function localDateKey() {
        const now = new Date();

        return [
            now.getFullYear(),
            String(now.getMonth() + 1).padStart(2, '0'),
            String(now.getDate()).padStart(2, '0')
        ].join('-');
    }

    function sendEvent(eventName, portal) {
        const body = new URLSearchParams();
        body.set('action', action);
        body.set('post_id', String(postId));
        body.set('event', eventName);

        if (portal) {
            body.set('portal', portal);
        }

        // ページ遷移直前のクリックも送りやすいようkeepalive。
        fetch(ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
            },
            body: body.toString(),
            keepalive: true
        }).catch(function () {
            // 計測失敗でユーザー操作を妨げない。
        });
    }

    // 閲覧は同じブラウザ・同じ商品で1日1回まで。
    try {
        const viewKey =
            'nf_pop_view_' +
            postId +
            '_' +
            localDateKey();

        if (!localStorage.getItem(viewKey)) {
            localStorage.setItem(viewKey, '1');
            sendEvent('view', '');
        }
    } catch (error) {
        // localStorage不可の場合も1回送信する。
        sendEvent('view', '');
    }

    const links = document.querySelectorAll(
        '.nf-single-portals a[href], ' +
        '.nf-single-portal-choice-grid a[href], ' +
        '.nf-mobile-portal-buttons a[href]'
    );

    links.forEach(function (link) {
        link.addEventListener('click', function () {
            const href = String(link.href || '');
            const text = String(link.textContent || '');
            const row = link.closest('.nf-single-portal-row');
            const card = link.closest('.nf-portal-choice-card');

            let portal = 'other';

            if (
                link.classList.contains('is-rakuten') ||
                (row && row.classList.contains('is-rakuten')) ||
                (card && card.classList.contains('is-rakuten')) ||
                href.includes('rakuten') ||
                text.includes('楽天')
            ) {
                portal = 'rakuten';
            } else if (
                link.classList.contains('is-yahoo') ||
                (row && row.classList.contains('is-yahoo')) ||
                (card && card.classList.contains('is-yahoo')) ||
                href.includes('yahoo') ||
                text.includes('Yahoo')
            ) {
                portal = 'yahoo';
            }

            // 同一リンクの連打は1時間に1回まで。
            let shouldSend = true;

            try {
                const targetKey =
                    'nf_pop_click_' +
                    postId +
                    '_' +
                    encodeURIComponent(href).slice(-160);

                const previous = Number(
                    localStorage.getItem(targetKey) || 0
                );

                const now = Date.now();

                if (
                    previous &&
                    (now - previous) < (60 * 60 * 1000)
                ) {
                    shouldSend = false;
                } else {
                    localStorage.setItem(
                        targetKey,
                        String(now)
                    );
                }
            } catch (error) {
                // localStorage不可なら通常計測。
            }

            if (shouldSend) {
                sendEvent('click', portal);
            }
        });
    });
});
