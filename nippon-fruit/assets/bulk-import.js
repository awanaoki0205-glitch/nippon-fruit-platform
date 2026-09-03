jQuery(function($){
    const $search = $('#nf_bulk_search');
    if (!$search.length) return;

    const $stop = $('#nf_bulk_stop');
    const $progress = $('#nf_bulk_progress');
    const $area = $('#nf_bulk_results_area');
    const $tbody = $('#nf_bulk_results tbody');
    const $count = $('#nf_bulk_match_count');
    const $selectAll = $('#nf_bulk_select_all');
    const $import = $('#nf_bulk_import');
    const $importResult = $('#nf_bulk_import_result');

    let stopped = false;
    let searching = false;
    let currentPage = 1;
    let pageCount = 1;
    let matchCount = 0;
    let fetchedCount = 0;
    let totalCount = 0;

    if ($progress.length && !$progress.text().trim()) {
        $progress
            .addClass('nf-bulk-js-ready')
            .text('一括取込機能を読み込みました。ショップコードを入力して検索してください。');
    }

    function escText(v) {
        return String(v == null ? '' : v);
    }

    function findRowByCode(code) {
        let $found = $();
        $('#nf_bulk_results tbody tr').each(function(){
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

        if (!min && !max) return '-';
        if (!max) max = min;
        if (!min) min = max;

        if (min === max) return min.toLocaleString() + '円';
        return min.toLocaleString() + '円〜' + max.toLocaleString() + '円';
    }

    function makeRow(item) {
        if (findRowByCode(item.itemCode).length) {
            return;
        }

        const $tr = $('<tr>').attr('data-code', item.itemCode);

        const $check = $('<input>', {
            type: 'checkbox',
            class: 'nf-bulk-item-check',
            value: item.itemCode,
            checked: true
        });

        $tr.append($('<td>').addClass('check-column').append($check));

        const $imgCell = $('<td>');
        if (item.imageUrl) {
            $imgCell.append(
                $('<img>', {
                    src: item.imageUrl,
                    alt: '',
                    loading: 'lazy'
                }).addClass('nf-bulk-thumb')
            );
        }
        $tr.append($imgCell);

        const $titleCell = $('<td>');
        $titleCell.append($('<strong>').text(escText(item.itemName)));
        if (item.itemUrl) {
            $titleCell.append('<br>');
            $titleCell.append(
                $('<a>', {
                    href: item.itemUrl,
                    target: '_blank',
                    rel: 'noopener'
                }).text('楽天商品ページ')
            );
        }
        $tr.append($titleCell);

        $tr.append($('<td>').text(formatPrice(item.priceMin, item.priceMax)));

        let status = '受付中';
        if (Number(item.availability) === 0) {
            status = '受付終了';
        } else if (String(item.itemName).indexOf('先行予約') !== -1) {
            status = '先行予約';
        }
        $tr.append($('<td>').text(status));

        $tr.append(
            $('<td>').append($('<code>').text(escText(item.itemCode)))
        );

        $tr.append(
            $('<td>').text(
                Array.isArray(item.fruitTerms) && item.fruitTerms.length
                    ? item.fruitTerms.join(' / ')
                    : '-'
            )
        );

        const existingText = Number(item.existing) > 0
            ? '既存商品を更新'
            : '新規';

        $tr.append(
            $('<td>').addClass('nf-bulk-register-state').text(existingText)
        );

        $tbody.append($tr);
    }

    function setSearchState(active) {
        searching = active;
        $search.prop('disabled', active);
        $stop.prop('disabled', !active);
    }

    function clearServerSession(callback) {
        $.post(NF_BULK.ajaxUrl, {
            action: 'nf_bulk_clear_session',
            nonce: NF_BULK.nonce,
            sessionId: NF_BULK.sessionId
        }).always(function(){
            if (callback) callback();
        });
    }

    function requestPage(page) {
        if (stopped) {
            setSearchState(false);
            $progress.text('検索を停止しました。');
            return;
        }

        const shopCode = $('#nf_bulk_shop_code').val().trim();
        const provider = $('#nf_bulk_provider').val().trim();

        $progress.text(
            '楽天APIを検索中… ' + page + 'ページ目' +
            (pageCount > 1 ? ' / ' + pageCount + 'ページ' : '')
        );

        $.post(NF_BULK.ajaxUrl, {
            action: 'nf_bulk_search_page',
            nonce: NF_BULK.nonce,
            sessionId: NF_BULK.sessionId,
            shopCode: shopCode,
            provider: provider,
            page: page
        }).done(function(res){
            if (!res || !res.success) {
                const message = res && res.data && res.data.message
                    ? res.data.message
                    : '検索に失敗しました。';

                $progress.html(
                    $('<span>').css('color','#b32d2e').text(message)
                );
                setSearchState(false);
                return;
            }

            const d = res.data;
            pageCount = Number(d.pageCount || 1);

            (d.matches || []).forEach(function(item){
                makeRow(item);
            });

            matchCount = Number(d.stored || 0);
            fetchedCount += Number(d.pageItems || 0);
            totalCount = Number(d.count || totalCount || 0);

            $count.text(matchCount);

            if (matchCount > 0) {
                $area.show();
            }

            if (page < pageCount && page < 100 && !stopped) {
                currentPage = page + 1;

                $progress.text(
                    '検索中… ' + page + '/' + pageCount + 'ページ完了。' +
                    '楽天商品総数 ' + totalCount + '件 / ' +
                    '取得済み ' + fetchedCount + '件 / ' +
                    '提供事業者一致 ' + matchCount + '件'
                );

                setTimeout(function(){
                    requestPage(currentPage);
                }, Number(NF_BULK.delayMs || 1200));

            } else {
                setSearchState(false);

                $progress.html(
                    $('<span>').css('color','#008a20').text(
                        '検索完了。楽天商品総数 ' + totalCount + '件 / ' +
                        '取得済み ' + fetchedCount + '件 / ' +
                        '提供事業者一致 ' + matchCount + '件'
                    )
                );

                if (!matchCount) {
                    $area.hide();
                }
            }

        }).fail(function(xhr){
            let message = '検索に失敗しました。';

            if (
                xhr.responseJSON &&
                xhr.responseJSON.data &&
                xhr.responseJSON.data.message
            ) {
                message = xhr.responseJSON.data.message;
            }

            $progress.html(
                $('<span>').css('color','#b32d2e').text(message)
            );
            setSearchState(false);
        });
    }

    $search.on('click', function(){
        const shopCode = $('#nf_bulk_shop_code').val().trim();
        const provider = $('#nf_bulk_provider').val().trim();

        if (!shopCode) {
            $progress.text('楽天ショップコードを入力してください。');
            return;
        }

        if (!provider) {
            $progress.text('提供事業者名を入力してください。');
            return;
        }

        stopped = false;
        currentPage = 1;
        pageCount = 1;
        matchCount = 0;
        fetchedCount = 0;
        totalCount = 0;

        $tbody.empty();
        $count.text('0');
        $area.hide();
        $importResult.empty();
        $selectAll.prop('checked', true);

        setSearchState(true);

        clearServerSession(function(){
            requestPage(1);
        });
    });

    $stop.on('click', function(){
        stopped = true;
        $stop.prop('disabled', true);
    });

    $selectAll.on('change', function(){
        $('.nf-bulk-item-check').prop('checked', $(this).prop('checked'));
    });

    $(document).on('change', '.nf-bulk-item-check', function(){
        const all = $('.nf-bulk-item-check').length;
        const checked = $('.nf-bulk-item-check:checked').length;
        $selectAll.prop('checked', all > 0 && all === checked);
    });

    $import.on('click', function(){
        const itemCodes = $('.nf-bulk-item-check:checked').map(function(){
            return $(this).val();
        }).get();

        if (!itemCodes.length) {
            $importResult.text('登録する商品を選択してください。');
            return;
        }

        const municipality = $('#nf_bulk_municipality').val().trim();
        const autoFruit = $('#nf_bulk_auto_fruit').prop('checked') ? 1 : 0;
        const postStatus = $('#nf_bulk_post_status').val();

        $import.prop('disabled', true);
        $importResult.text(
            itemCodes.length + '件をWordPressへ登録しています…'
        );

        $.post(NF_BULK.ajaxUrl, {
            action: 'nf_bulk_import_selected',
            nonce: NF_BULK.nonce,
            sessionId: NF_BULK.sessionId,
            itemCodes: itemCodes,
            municipality: municipality,
            autoFruit: autoFruit,
            postStatus: postStatus
        }).done(function(res){
            if (!res || !res.success) {
                const message = res && res.data && res.data.message
                    ? res.data.message
                    : '一括登録に失敗しました。';

                $importResult.html(
                    $('<div>')
                        .addClass('notice notice-error inline')
                        .append($('<p>').text(message))
                );
                return;
            }

            const d = res.data;

            (d.rows || []).forEach(function(row){
                const $row = findRowByCode(row.itemCode);

                const $state = $row.find('.nf-bulk-register-state').empty();

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
            });

            const $box = $('<div>').addClass(
                'notice ' +
                ((d.errors || []).length ? 'notice-warning' : 'notice-success') +
                ' inline'
            );

            $box.append(
                $('<p>').html(
                    '<strong>一括登録完了：</strong> 新規 ' +
                    Number(d.created || 0) + '件 / 更新 ' +
                    Number(d.updated || 0) + '件 / エラー ' +
                    (d.errors || []).length + '件'
                )
            );

            if ((d.errors || []).length) {
                const $ul = $('<ul>').css({
                    listStyle: 'disc',
                    paddingLeft: '20px'
                });

                d.errors.forEach(function(error){
                    $ul.append($('<li>').text(error));
                });

                $box.append($ul);
            }

            $importResult.empty().append($box);

        }).fail(function(xhr){
            let message = '一括登録に失敗しました。';

            if (
                xhr.responseJSON &&
                xhr.responseJSON.data &&
                xhr.responseJSON.data.message
            ) {
                message = xhr.responseJSON.data.message;
            }

            $importResult.html(
                $('<div>')
                    .addClass('notice notice-error inline')
                    .append($('<p>').text(message))
            );

        }).always(function(){
            $import.prop('disabled', false);
        });
    });
});
