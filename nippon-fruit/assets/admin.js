jQuery(function($){
    const $btn = $('#nf-fetch-rakuten');

    if ($btn.length) $btn.on('click', function(){
        const url = $('#nf_rakuten_url').val().trim();
        const affiliateHtml = $('#nf_rakuten_affiliate_html').val();
        const title = $('.editor-post-title__input').val() || $('#title').val() || '';
        const savedItemCode = $('#nf_rakuten_item_code').val() || '';

        const $spinner = $('#nf-rakuten-spinner');
        const $result = $('#nf-api-result');

        if (!url) {
            $result.html('<span style="color:#b32d2e">楽天商品URLを入力してください。</span>');
            return;
        }

        $btn.prop('disabled', true);
        $spinner.addClass('is-active');
        $result.text('楽天APIから取得中…');

        $.post(NF_ADMIN.ajaxUrl, {
            action: 'nf_fetch_rakuten',
            nonce: NF_ADMIN.nonce,
            url: url,
            affiliateHtml: affiliateHtml,
            title: title,
            savedItemCode: savedItemCode
        }).done(function(res){
            if (!res || !res.success) {
                const msg = res && res.data && res.data.message ? res.data.message : '取得に失敗しました。';
                $result.html('<span style="color:#b32d2e"></span>').find('span').text(msg);
                return;
            }

            const d = res.data;

            $('#nf_price').val(d.itemPriceMin || d.itemPrice || '');
            $('#nf_price_min').val(d.itemPriceMin || d.itemPrice || '');
            $('#nf_price_max').val(d.itemPriceMax || d.itemPrice || '');

            $('#nf_rakuten_item_code').val(d.itemCode || '');
            $('#nf_rakuten_item_name').val(d.itemName || '');
            $('#nf_rakuten_image_url').val(d.imageUrl || '');

            const imageUrls = Array.isArray(d.imageUrls)
                ? d.imageUrls.filter(Boolean).slice(0, 12)
                : (d.imageUrl ? [d.imageUrl] : []);

            $('#nf_rakuten_image_urls').val(JSON.stringify(imageUrls));
            $('#nf-rakuten-images-preview').text(
                '商品画像: ' + imageUrls.length + '枚'
            );

            $('#nf_rakuten_shop_name').val(d.shopName || '');
            $('#nf_rakuten_affiliate_url').val(d.affiliateUrl || '');
            $('#nf_rakuten_sale_start').val(d.saleStart || '');
            $('#nf_rakuten_sale_end').val(d.saleEnd || '');
            $('#nf_rakuten_description').val(d.description || '');
            $('#nf_rakuten_review_average').val(d.reviewAverage || '');
            $('#nf_rakuten_review_count').val(d.reviewCount || '');

            $('#nf-rakuten-name-preview').text(d.itemName || '');
            $('#nf-rakuten-shop-preview').text(d.shopName || '');
            $('#nf-rakuten-code-preview').text(d.itemCode || '');

            if (d.imageUrl) {
                $('#nf-rakuten-image-preview').attr('src', d.imageUrl).show();
            } else {
                $('#nf-rakuten-image-preview').hide();
            }
            $('#nf-rakuten-preview').show();

            if (d.availability === 0) {
                $('#nf_status').val('受付終了');
            } else if (d.availability === 1 && $('#nf_status').val() === '受付終了') {
                $('#nf_status').val('受付中');
            }

            if (d.saleEnd) {
                const saleEnd = new Date(String(d.saleEnd).replace(' ', 'T'));
                if (!isNaN(saleEnd.getTime()) && saleEnd.getTime() < Date.now()) {
                    $('#nf_status').val('受付終了');
                }
            }

            let priceText = '';
            if (d.itemPriceMin && d.itemPriceMax && d.itemPriceMin !== d.itemPriceMax) {
                priceText = ' 寄附額 ' + Number(d.itemPriceMin).toLocaleString() + '円〜' + Number(d.itemPriceMax).toLocaleString() + '円';
            } else if (d.itemPriceMin) {
                priceText = ' 寄附額 ' + Number(d.itemPriceMin).toLocaleString() + '円';
            }

            let method = '商品URL一致検索で特定';
            if (d.detectedBy === 'affiliate_item_id') {
                method = '楽天アフィリエイトHTMLのitem_idから特定';
            } else if (d.detectedBy === 'saved_item_code') {
                method = '保存済みitemCodeから更新';
            }

            const affiliateStatus = d.affiliateUrl
                ? ' 自動アフィリエイトカード利用可能。'
                : ' affiliateUrlが取得できません。Nippon Fruit設定の楽天Affiliate IDを確認してください。';

            $result.html(
                '<span style="color:#008a20">楽天商品情報を取得しました。' +
                method + '。' + priceText + '。' + affiliateStatus +
                ' 内容を確認して「更新／公開」を押してください。</span>'
            );
        }).fail(function(xhr){
            let msg = '取得に失敗しました。';
            if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                msg = xhr.responseJSON.data.message;
            }
            $result.html('<span style="color:#b32d2e"></span>').find('span').text(msg);
        }).always(function(){
            $btn.prop('disabled', false);
            $spinner.removeClass('is-active');
        });
    });

    // ========================================================
    // v0.6.4: 追加商品画像
    // ========================================================
    const $manualTextarea = $('#nf_manual_image_urls');
    const $manualPreview = $('#nf-manual-image-preview');
    const $manualCount = $('#nf-manual-image-count');
    const $manualAdd = $('#nf-add-manual-images');
    const $manualClear = $('#nf-clear-manual-images');

    function manualImageUrls() {
        if (!$manualTextarea.length) return [];

        const seen = new Set();

        return String($manualTextarea.val() || '')
            .split(/\r?\n/)
            .map((url) => url.trim())
            .filter((url) => {
                if (!url || seen.has(url)) return false;
                seen.add(url);
                return true;
            });
    }

    function saveManualImageUrls(urls) {
        const seen = new Set();
        const clean = (urls || []).filter((url) => {
            url = String(url || '').trim();
            if (!url || seen.has(url)) return false;
            seen.add(url);
            return true;
        });

        $manualTextarea.val(clean.join('\n'));
        renderManualImages();
    }

    function renderManualImages() {
        if (!$manualPreview.length) return;

        const urls = manualImageUrls();

        $manualPreview.empty();
        $manualCount.text(urls.length);

        urls.forEach((url, index) => {
            const $item = $('<div class="nf-manual-image-item"></div>');
            const $img = $('<img alt="">').attr('src', url);
            const $remove = $(
                '<button type="button" class="nf-manual-image-remove" aria-label="画像を削除">×</button>'
            );

            $remove.on('click', function () {
                const current = manualImageUrls();
                current.splice(index, 1);
                saveManualImageUrls(current);
            });

            $item.append($img, $remove);
            $manualPreview.append($item);
        });
    }

    if ($manualTextarea.length) {
        renderManualImages();

        $manualTextarea.on('input change', function () {
            renderManualImages();
        });
    }

    if ($manualAdd.length && window.wp && wp.media) {
        let manualFrame = null;

        $manualAdd.on('click', function (event) {
            event.preventDefault();

            if (manualFrame) {
                manualFrame.open();
                return;
            }

            manualFrame = wp.media({
                title: '追加商品画像を選択',
                button: {
                    text: '選択した画像を追加'
                },
                library: {
                    type: 'image'
                },
                multiple: true
            });

            manualFrame.on('select', function () {
                const urls = manualImageUrls();
                const selection = manualFrame.state().get('selection');

                selection.each(function (attachment) {
                    const data = attachment.toJSON();
                    const url = data && data.url ? String(data.url) : '';

                    if (url && !urls.includes(url)) {
                        urls.push(url);
                    }
                });

                saveManualImageUrls(urls);
            });

            manualFrame.open();
        });
    }

    if ($manualClear.length) {
        $manualClear.on('click', function (event) {
            event.preventDefault();

            if (!manualImageUrls().length) return;

            if (window.confirm('追加商品画像をすべてクリアしますか？')) {
                saveManualImageUrls([]);
            }
        });
    }

});
