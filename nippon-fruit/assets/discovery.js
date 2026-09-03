jQuery(function($){
    const $search = $('#nf_discovery_search');
    if (!$search.length) return;

    const $stop = $('#nf_discovery_stop');
    const $progress = $('#nf_discovery_progress');
    const $summary = $('#nf_discovery_summary');
    const $match = $('#nf_discovery_match_count');
    const $newCount = $('#nf_discovery_new_count');
    const $existingCount = $('#nf_discovery_existing_count');
    const $wpActualCount = $('#nf_discovery_wp_actual_count');
    const $priceChangeCount = $('#nf_discovery_price_change_count');
    const $statusChangeCount = $('#nf_discovery_status_change_count');
    const $municipalityCount = $('#nf_discovery_municipality_count');
    const $groups = $('#nf_discovery_groups');
    const $area = $('#nf_discovery_results_area');
    const $tbody = $('#nf_discovery_results tbody');
    const $selectAll = $('#nf_discovery_select_all');
    const $import = $('#nf_discovery_import');
    const $importResult = $('#nf_discovery_import_result');

    let stopped = false;
    let phase = 'global';
    let currentPage = 1;
    let pageCount = 1;
    let knownShopQueue = [];
    let currentShopIndex = 0;
    let currentShopCode = '';
    let municipalityProbeQueue = [];
    let currentProbeIndex = 0;

    function findRowByCode(code) {
        let $found = $();

        $tbody.find('tr').each(function(){
            if (String($(this).attr('data-code') || '') === String(code || '')) {
                $found = $(this);
                return false;
            }
        });

        return $found;
    }

    function formatPrice(min, max) {
        min = Number(min || 0);
        max = Number(max || 0);

        if (min <= 100 && max <= 100) return '価格取得不可';
        if (min <= 100) min = max;
        if (max <= 100) max = min;

        if (!min && !max) return '価格取得不可';
        if (min === max) return min.toLocaleString() + '円';

        return min.toLocaleString() + '円〜' + max.toLocaleString() + '円';
    }

    function renderSummary(summary, wpActualCount) {
        summary = summary || {};

        $match.text(Number(summary.total || 0));
        $newCount.text(Number(summary.new || 0));
        $existingCount.text(Number(summary.existing || 0));

        if (typeof wpActualCount !== 'undefined' && wpActualCount !== null) {
            $wpActualCount.text(Number(wpActualCount || 0));
        }
        $priceChangeCount.text(Number(summary.priceChanged || 0));
        $statusChangeCount.text(Number(summary.statusChanged || 0));

        const municipalities = summary.municipalities || {};
        const entries = Object.entries(municipalities);

        $municipalityCount.text(entries.length);
        $summary.show();

        $groups.empty();

        entries.forEach(function(entry){
            $groups.append(
                $('<span>')
                    .addClass('nf-discovery-group-chip')
                    .text(entry[0] + ' ' + entry[1] + '件')
            );
        });

        if (entries.length) {
            $groups.show();
        } else {
            $groups.hide();
        }

        if (Number(summary.total || 0) > 0) {
            $area.show();
        }
    }

    function decisionText(item) {
        if (!Number(item.existing || 0)) {
            return '新規';
        }

        const changes = [];

        if (item.priceChanged) changes.push('価格変更');
        if (item.statusChanged) changes.push('受付変更');

        if (changes.length) {
            return '既存・' + changes.join('・');
        }

        return '既存';
    }

    function makeOrUpdateRow(item) {
        let $tr = findRowByCode(item.itemCode);

        if (!$tr.length) {
            $tr = $('<tr>').attr('data-code', item.itemCode);
            $tbody.append($tr);
        } else {
            $tr.empty();
        }

        $tr.append(
            $('<td>')
                .addClass('check-column')
                .append(
                    $('<input>', {
                        type: 'checkbox',
                        class: 'nf-discovery-item-check',
                        value: item.itemCode,
                        checked: true
                    })
                )
        );

        const $img = $('<td>');

        if (item.imageUrl) {
            $img.append(
                $('<img>', {
                    src: item.imageUrl,
                    alt: '',
                    loading: 'lazy'
                }).addClass('nf-discovery-thumb')
            );
        }

        $tr.append($img);

        $tr.append(
            $('<td>').text(item.municipality || item.shopName || '-')
        );

        const $title = $('<td>');
        $title.append($('<strong>').text(item.itemName || ''));

        if (item.itemUrl) {
            $title.append('<br>');
            $title.append(
                $('<a>', {
                    href: item.itemUrl,
                    target: '_blank',
                    rel: 'noopener'
                }).text('楽天商品ページ')
            );
        }

        $tr.append($title);

        $tr.append(
            $('<td>').text(formatPrice(item.priceMin, item.priceMax))
        );

        $tr.append(
            $('<td>').text(item.statusText || '-')
        );

        $tr.append(
            $('<td>').text(
                Array.isArray(item.fruitTerms) && item.fruitTerms.length
                    ? item.fruitTerms.join(' / ')
                    : '-'
            )
        );

        $tr.append(
            $('<td>').append($('<code>').text(item.itemCode || ''))
        );

        $tr.append(
            $('<td>')
                .addClass('nf-discovery-register-state')
                .text(decisionText(item))
        );
    }

    function setSearchState(active) {
        $search.prop('disabled', active);
        $stop.prop('disabled', !active);
    }

    function clearSession(callback) {
        $.post(NF_DISCOVERY.ajaxUrl, {
            action: 'nf_discovery_clear_session',
            nonce: NF_DISCOVERY.nonce,
            sessionId: NF_DISCOVERY.sessionId
        }).always(function(){
            if (callback) callback();
        });
    }

    function getKnownShops(callback) {
        $.post(NF_DISCOVERY.ajaxUrl, {
            action: 'nf_discovery_get_known_shops',
            nonce: NF_DISCOVERY.nonce
        }).done(function(res){
            if (!res || !res.success) {
                callback([]);
                return;
            }

            const shopsObj = res.data.shops || {};
            const shops = Object.keys(shopsObj).map(function(code){
                return {
                    code: code,
                    data: shopsObj[code] || {}
                };
            });

            const unconfirmed = Array.isArray(res.data.unconfirmedMunicipalities)
                ? res.data.unconfirmedMunicipalities
                : [];

            callback(shops, unconfirmed);
        }).fail(function(){
            callback([], []);
        });
    }

    function requestPage(keyword, shopCode, page, callback) {
        const provider = $('#nf_discovery_provider').val().trim();

        $.post(NF_DISCOVERY.ajaxUrl, {
            action: 'nf_discovery_search_page',
            nonce: NF_DISCOVERY.nonce,
            sessionId: NF_DISCOVERY.sessionId,
            provider: provider,
            keyword: keyword,
            shopFilter: shopCode,
            page: page
        }).done(function(res){
            if (!res || !res.success) {
                const msg = res && res.data && res.data.message
                    ? res.data.message
                    : '検索に失敗しました。';

                callback(new Error(msg));
                return;
            }

            const d = res.data;

            (d.matches || []).forEach(makeOrUpdateRow);
            renderSummary(d.summary || {}, d.wpActualCount);

            if (
                typeof d.wpActualCount !== 'undefined' &&
                typeof d.wpCodedCount !== 'undefined'
            ) {
                $progress.attr(
                    'data-wp-code-count',
                    'WordPress実在 ' + Number(d.wpActualCount || 0) +
                    ' / itemCode登録済み ' + Number(d.wpCodedCount || 0)
                );
            }

            callback(null, d);

        }).fail(function(xhr){
            let msg = '検索に失敗しました。';

            if (
                xhr.responseJSON &&
                xhr.responseJSON.data &&
                xhr.responseJSON.data.message
            ) {
                msg = xhr.responseJSON.data.message;
            }

            callback(new Error(msg));
        });
    }

    function runGlobalPage(page) {
        if (stopped) return finishStopped();

        const keyword = $('#nf_discovery_keyword').val().trim();

        $progress.text(
            '第1段階：楽天全体検索（熊本県内自治体ショップのみ採用） ' + page +
            (pageCount > 1 ? ' / ' + pageCount + 'ページ' : 'ページ目')
        );

        requestPage(keyword, '', page, function(err, d){
            if (err) return finishError(err.message);

            pageCount = Number(d.pageCount || 1);

            if (page < pageCount && page < 100 && !stopped) {
                setTimeout(function(){
                    runGlobalPage(page + 1);
                }, Number(NF_DISCOVERY.delayMs || 1200));
                return;
            }

            beginMunicipalityProbe();
        });
    }

    function beginMunicipalityProbe() {
        const explicitShop = $('#nf_discovery_shop_filter').val().trim();
        const scanKnown = $('#nf_discovery_scan_known_shops').prop('checked');

        if (explicitShop || !scanKnown) {
            beginKnownShopScan();
            return;
        }

        getKnownShops(function(shops, unconfirmed){
            municipalityProbeQueue = unconfirmed || [];
            currentProbeIndex = 0;

            if (!municipalityProbeQueue.length) {
                beginKnownShopScan();
                return;
            }

            runMunicipalityProbe();
        });
    }

    function runMunicipalityProbe() {
        if (stopped) return finishStopped();

        if (currentProbeIndex >= municipalityProbeQueue.length) {
            beginKnownShopScan();
            return;
        }

        const municipality = municipalityProbeQueue[currentProbeIndex];
        const baseKeyword = $('#nf_discovery_keyword').val().trim() || '';
        const probeKeyword = baseKeyword + ' ' + municipality;

        $progress.text(
            '第2段階：未確認自治体を探索 ' +
            (currentProbeIndex + 1) + '/' + municipalityProbeQueue.length +
            ' — ' + municipality
        );

        requestPage(probeKeyword, '', 1, function(){
            currentProbeIndex++;

            setTimeout(
                runMunicipalityProbe,
                Number(NF_DISCOVERY.delayMs || 1200)
            );
        });
    }

    function beginKnownShopScan() {
        const explicitShop = $('#nf_discovery_shop_filter').val().trim();
        const scanKnown = $('#nf_discovery_scan_known_shops').prop('checked');

        if (explicitShop) {
            knownShopQueue = [{code: explicitShop, data:{}}];
            currentShopIndex = 0;
            runShopStart();
            return;
        }

        if (!scanKnown) {
            finishSuccess();
            return;
        }

        getKnownShops(function(shops){
            knownShopQueue = shops;
            currentShopIndex = 0;

            if (!knownShopQueue.length) {
                finishSuccess();
                return;
            }

            runShopStart();
        });
    }

    function runShopStart() {
        if (stopped) return finishStopped();

        if (currentShopIndex >= knownShopQueue.length) {
            finishSuccess();
            return;
        }

        currentShopCode = knownShopQueue[currentShopIndex].code;
        pageCount = 1;

        runShopPage(1);
    }

    function runShopPage(page) {
        if (stopped) return finishStopped();

        $progress.text(
            '第3段階：熊本県内の確認済み自治体ショップ全走査 ' +
            (currentShopIndex + 1) + '/' + knownShopQueue.length +
            'ショップ — ' + currentShopCode +
            ' — ' + page +
            (pageCount > 1 ? '/' + pageCount : '') + 'ページ'
        );

        requestPage('', currentShopCode, page, function(err, d){
            if (err) {
                // 1ショップのエラーで全検索を止めず、次ショップへ進む。
                currentShopIndex++;
                setTimeout(runShopStart, Number(NF_DISCOVERY.delayMs || 1200));
                return;
            }

            pageCount = Number(d.pageCount || 1);

            if (page < pageCount && page < 100 && !stopped) {
                setTimeout(function(){
                    runShopPage(page + 1);
                }, Number(NF_DISCOVERY.delayMs || 1200));
                return;
            }

            currentShopIndex++;

            setTimeout(
                runShopStart,
                Number(NF_DISCOVERY.delayMs || 1200)
            );
        });
    }

    function finishStopped() {
        setSearchState(false);
        $progress.text('検索を停止しました。');
    }

    function finishError(msg) {
        setSearchState(false);
        $progress.html(
            $('<span>').css('color','#b32d2e').text(msg)
        );
    }

    function finishSuccess() {
        setSearchState(false);

        const total = Number($match.text() || 0);
        const municipalityTotal = Number($municipalityCount.text() || 0);

        $progress.html(
            $('<span>').css('color','#008a20').text(
                '完全検索が完了しました。対象商品 ' +
                total + '件 / 発見自治体 ' +
                municipalityTotal + '件'
            )
        );
    }

    $search.on('click', function(){
        const provider = $('#nf_discovery_provider').val().trim();
        const keyword = $('#nf_discovery_keyword').val().trim();
        const explicitShop = $('#nf_discovery_shop_filter').val().trim();

        if (!provider) {
            $progress.text('提供事業者名を入力してください。');
            return;
        }

        if (!keyword && !explicitShop) {
            $progress.text('検索キーワードまたはショップコードが必要です。');
            return;
        }

        stopped = false;
        phase = 'global';
        currentPage = 1;
        pageCount = 1;
        knownShopQueue = [];
        currentShopIndex = 0;
        currentShopCode = '';
        municipalityProbeQueue = [];
        currentProbeIndex = 0;

        $tbody.empty();
        $area.hide();
        $summary.hide();
        $wpActualCount.text('0');
        $groups.hide().empty();
        $importResult.empty();
        $selectAll.prop('checked', true);

        setSearchState(true);

        clearSession(function(){
            if (explicitShop) {
                beginKnownShopScan();
            } else {
                runGlobalPage(1);
            }
        });
    });

    $stop.on('click', function(){
        stopped = true;
        $stop.prop('disabled', true);
    });

    $selectAll.on('change', function(){
        $('.nf-discovery-item-check').prop(
            'checked',
            $(this).prop('checked')
        );
    });

    $(document).on('change', '.nf-discovery-item-check', function(){
        const all = $('.nf-discovery-item-check').length;
        const checked = $('.nf-discovery-item-check:checked').length;

        $selectAll.prop(
            'checked',
            all > 0 && all === checked
        );
    });

    $import.on('click', function(){
        const itemCodes = $('.nf-discovery-item-check:checked').map(function(){
            return $(this).val();
        }).get();

        if (!itemCodes.length) {
            $importResult.text('登録する商品を選択してください。');
            return;
        }

        const batchSize = 5;
        const batches = [];

        for (let i = 0; i < itemCodes.length; i += batchSize) {
            batches.push(itemCodes.slice(i, i + batchSize));
        }

        let batchIndex = 0;
        let processed = 0;
        let createdTotal = 0;
        let updatedTotal = 0;
        let errorsTotal = [];
        let lastPostCounts = null;

        $import.prop('disabled', true);
        $search.prop('disabled', true);

        function showProgress() {
            $importResult.html(
                $('<div>')
                    .addClass('notice notice-info inline')
                    .append(
                        $('<p>').html(
                            '<strong>WordPressへ登録中：</strong> ' +
                            processed + ' / ' + itemCodes.length + '件' +
                            '（新規 ' + createdTotal + ' / 更新 ' + updatedTotal +
                            ' / エラー ' + errorsTotal.length + '）'
                        )
                    )
            );
        }

        function finishImport() {
            const $box = $('<div>').addClass(
                'notice ' +
                (errorsTotal.length ? 'notice-warning' : 'notice-success') +
                ' inline'
            );

            let countText = '';
            if (lastPostCounts) {
                countText =
                    ' / 現在の返礼品：合計 ' + Number(lastPostCounts.total || 0) +
                    '件（公開 ' + Number(lastPostCounts.publish || 0) +
                    ' / 下書き ' + Number(lastPostCounts.draft || 0) + '）';
            }

            $box.append(
                $('<p>').html(
                    '<strong>一括登録完了：</strong> 新規 ' +
                    createdTotal + '件 / 更新 ' +
                    updatedTotal + '件 / エラー ' +
                    errorsTotal.length + '件' + countText
                )
            );

            if (errorsTotal.length) {
                const $ul = $('<ul>').css({
                    listStyle: 'disc',
                    paddingLeft: '20px',
                    maxHeight: '260px',
                    overflow: 'auto'
                });

                errorsTotal.forEach(function(error){
                    $ul.append($('<li>').text(error));
                });

                $box.append($ul);
            }

            $importResult.empty().append($box);
            $import.prop('disabled', false);
            $search.prop('disabled', false);
        }

        function runBatch() {
            if (batchIndex >= batches.length) {
                finishImport();
                return;
            }

            const batch = batches[batchIndex];

            showProgress();

            $.post(NF_DISCOVERY.ajaxUrl, {
                action: 'nf_discovery_import_selected',
                nonce: NF_DISCOVERY.nonce,
                sessionId: NF_DISCOVERY.sessionId,
                itemCodes: batch,
                autoMunicipality: $('#nf_discovery_auto_municipality').prop('checked') ? 1 : 0,
                autoFruit: $('#nf_discovery_auto_fruit').prop('checked') ? 1 : 0,
                postStatus: $('#nf_discovery_post_status').val()
            }).done(function(res){
                if (!res || !res.success) {
                    const msg = res && res.data && res.data.message
                        ? res.data.message
                        : 'バッチ登録に失敗しました。';

                    errorsTotal.push(
                        'バッチ ' + (batchIndex + 1) + ': ' + msg
                    );

                    processed += batch.length;
                    batchIndex++;

                    setTimeout(runBatch, 350);
                    return;
                }

                const d = res.data;

                createdTotal += Number(d.created || 0);
                updatedTotal += Number(d.updated || 0);
                processed += Number(d.processed || batch.length);
                lastPostCounts = d.postCounts || lastPostCounts;

                (d.errors || []).forEach(function(error){
                    errorsTotal.push(error);
                });

                (d.rows || []).forEach(function(row){
                    const $row = findRowByCode(row.itemCode);
                    const $state = $row.find('.nf-discovery-register-state').empty();

                    if (row.editUrl) {
                        $state.append(
                            $('<a>', {
                                href: row.editUrl,
                                target: '_blank',
                                rel: 'noopener'
                            }).text(row.status)
                        );
                    } else {
                        $state.text(row.status);
                    }

                    $row.find('.nf-discovery-item-check').prop('checked', false);
                });

                batchIndex++;
                showProgress();

                // WordPressサーバー負荷を抑えて次へ。
                setTimeout(runBatch, 350);

            }).fail(function(xhr){
                let msg = '通信エラー';

                if (
                    xhr.responseJSON &&
                    xhr.responseJSON.data &&
                    xhr.responseJSON.data.message
                ) {
                    msg = xhr.responseJSON.data.message;
                } else if (xhr.status) {
                    msg += ' (HTTP ' + xhr.status + ')';
                }

                errorsTotal.push(
                    'バッチ ' + (batchIndex + 1) + ': ' + msg
                );

                processed += batch.length;
                batchIndex++;

                setTimeout(runBatch, 500);
            });
        }

        showProgress();
        runBatch();
    });
});
