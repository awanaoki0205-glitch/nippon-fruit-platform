jQuery(function($){
    const $results = $('#nf_catalog_results');
    if (!$results.length) return;

    const $keyword = $('#nf_catalog_keyword');
    const $municipality = $('#nf_catalog_municipality');
    const $fruit = $('#nf_catalog_fruit');
    const $category = $('#nf_catalog_category');
    const $subcategory = $('#nf_catalog_subcategory');
    const $type = $('#nf_catalog_type');
    const $status = $('#nf_catalog_status');
    const $priceRange = $('#nf_catalog_price_range');
    const $priceMin = $('#nf_catalog_price_min');
    const $priceMax = $('#nf_catalog_price_max');
    const $portal = $('#nf_catalog_portal');
    const $yahooStore = $('#nf_catalog_yahoo_store');
    const $order = $('#nf_catalog_order');
    const $perPage = $('#nf_catalog_per_page');

    const $count = $('#nf_catalog_count');
    const $loading = $('#nf_catalog_loading');
    const $pagination = $('#nf_catalog_pagination');
    const $filterToggle = $('#nf_catalog_filter_toggle');
    const $filterDrawer = $('#nf_catalog_filter_body');
    const $applyFilters = $('#nf_catalog_apply_filters');
    const $browseToggle = $('#nf_catalog_browse_toggle');
    const $browseDrawer = $('#nf_catalog_browse_drawer');
    const $seasonSection = $('#nf_catalog_season_section');
    const $ownedContentBlocks = $('.nf-owned-content-block');
    const $hero = $('.nf-catalog-hero');
    const $listTitle = $('#nf_catalog_list_title');
    const $range = $('#nf_catalog_range');

    const $activeFilters = $('#nf_catalog_active_filters');
    const $activeFilterCount = $('#nf_catalog_active_filter_count');
    const $activeFilterChips = $('#nf_catalog_active_filter_chips');
    const $changeFilters = $('#nf_catalog_change_filters');
    const $categorySidebar = $('.nf-catalog-category-sidebar');
    const $searchPanel = $('#nf_catalog_search_panel');
    const $browseSection = $('#nf_catalog_browse_section');
    const $headerRefine = $('#nf_furusato_refine_button');

    let request = null;
    let previewRequest = null;
    let previewTimer = null;
    let appliedState = null;
    const multiMunicipality = !!(window.NF_CATALOG && NF_CATALOG.multiMunicipality);
    const multiCategory = !!(window.NF_CATALOG && NF_CATALOG.multiCategory);

    function selectedSlugs(selector, dataKey) {
        return $(selector + ':checked').map(function(){ return String($(this).data(dataKey) || ''); }).get().filter(Boolean);
    }
    function selectedCategorySlugs() {
        return $('.nf-category-tree-check:checked').map(function(){
            return String($(this).data('type') || $(this).data('subcategory') || $(this).data('category') || '');
        }).get().filter(function(value,index,all){ return value && all.indexOf(value)===index; });
    }

    function isMobileFilterMode() {
        return !!(window.matchMedia && window.matchMedia('(max-width: 820px)').matches);
    }

    function autoApplyOnDesktop() {
        if (isMobileFilterMode()) return;
        runFilter(1, {scroll:false, closeFilter:false});
    }

    // 複数選択中は閉じず、適用ボタンで確定する。
    function applyTreeChoice() {
        updateSelectionSummary();
        if (multiMunicipality || multiCategory) schedulePreviewCount();
        else runFilter(1, {scroll:isMobileFilterMode(),closeFilter:false});
    }

    function schedulePreviewCount() {
        window.clearTimeout(previewTimer);
        previewTimer=window.setTimeout(function(){
            const state=currentState();
            if(previewRequest&&previewRequest.readyState!==4) previewRequest.abort();
            previewRequest=$.post(NF_CATALOG.ajaxUrl,{action:'nf_catalog_filter',nonce:NF_CATALOG.nonce,count_only:1,
                keyword:state.q,municipality:state.municipality,municipalities:(state.municipalities||[]).join(','),fruit:state.fruit,
                category:state.category,subcategory:state.subcategory,type:state.type,categories:(state.categories||[]).join(','),status:state.status,
                price_range:state.price,price_min:state.price_min,price_max:state.price_max,portal:state.portal,yahoo_store:state.yahoo_store,order:state.order,per_page:state.per_page,paged:1
            }).done(function(res){if(res&&res.success){const n=Number(res.data.found||0).toLocaleString('ja-JP');$applyFilters.text(n+'件の商品を見る');}});
        },250);
    }

    function updateSelectionSummary() {
        const municipalities=selectedSlugs('.nf-municipality-tree-check','slug');
        const categories=selectedCategorySlugs();
        if ($municipalityTreeSummary.length) $municipalityTreeSummary.text(municipalities.length+'自治体選択中').prop('hidden',!municipalities.length);
        if ($categoryTreeSummary.length) $categoryTreeSummary.text(categories.length+'カテゴリ選択中').prop('hidden',!categories.length);
        $applyFilters.text((municipalities.length||categories.length) ? municipalities.length+'自治体・'+categories.length+'カテゴリを適用' : 'この条件で絞り込む');
    }

    function placeSearchToolsAboveCategory() {
        if (!$categorySidebar.length) return;

        const $categoryStart = $categorySidebar
            .children('.nf-mobile-filter-section-toggle')
            .first();

        if (!$categoryStart.length) return;

        $searchPanel.insertBefore($categoryStart);
        $browseSection.insertBefore($categoryStart);
        $categorySidebar.addClass('has-relocated-search-tools');
    }

    placeSearchToolsAboveCategory();

    function initializeMobileFilterSections() {
        $('.nf-catalog-filters>label').each(function(index){
            const $body = $(this);
            const $title = $body.children('span').first();
            const title = $.trim($title.text()) || '検索条件';
            const id = 'nf_mobile_filter_body_' + index;

            $body.attr('id', id).addClass('nf-mobile-filter-body');

            $('<button type="button" class="nf-mobile-filter-section-toggle nf-mobile-generated-toggle"></button>')
                .attr({
                    'aria-expanded': 'false',
                    'aria-controls': id
                })
                .append($('<span></span>').text(title))
                .append('<span aria-hidden="true">▼</span>')
                .insertBefore($body);
        });
    }

    initializeMobileFilterSections();

    $(document).on('click', '.nf-mobile-filter-section-toggle', function(){
        const $toggle = $(this);
        const id = $toggle.attr('aria-controls');
        const $body = id ? $('#' + id) : $();
        const open = $toggle.attr('aria-expanded') !== 'true';

        $toggle.attr('aria-expanded', open ? 'true' : 'false');
        $toggle.find('span').last().text(open ? '▲' : '▼');
        $body.toggleClass('is-mobile-open', open);
    });

    function setDrawer($toggle, $drawer, open) {
        if (!$drawer.length || !$toggle.length) return;

        $drawer.prop('hidden', !open);
        $toggle.attr('aria-expanded', open ? 'true' : 'false');

        const $icon = $toggle.find('span').last();
        if ($icon.length) {
            $icon.text(open ? '−' : '＋');
        }

        if ($drawer.is($filterDrawer)) {
            $searchPanel.toggleClass('is-refine-open', !!open);
            $headerRefine.attr('aria-expanded', open ? 'true' : 'false');
        }
    }

    function optionText($select) {
        if (!$select || !$select.length || !$select.val()) return '';

        const text = $select.find('option:selected').text() || '';

        return String(text)
            .replace(/\s+/g, ' ')
            .trim();
    }

    function currentState() {
        let priceMin = $priceMin.length ? (parseInt($priceMin.val(), 10) || 0) : 0;
        let priceMax = $priceMax.length ? (parseInt($priceMax.val(), 10) || 0) : 0;

        if (priceMin > 0 && priceMax > 0 && priceMin > priceMax) {
            const swap = priceMin;
            priceMin = priceMax;
            priceMax = swap;
        }

        return {
            q: $keyword.val().trim(),
            municipality: $municipality.val() || '',
            municipalities: multiMunicipality ? selectedSlugs('.nf-municipality-tree-check','slug') : [],
            fruit: $fruit.val() || '',
            category: $category.length ? ($category.val() || '') : '',
            subcategory: $subcategory.length ? ($subcategory.val() || '') : '',
            type: $type.length ? ($type.val() || '') : '',
            categories: multiCategory ? selectedCategorySlugs() : [],
            status: $status.val() || '',
            price: $priceRange.length ? ($priceRange.val() || '') : '',
            price_min: priceMin,
            price_max: priceMax,
            portal: $portal.length ? ($portal.val() || '') : '',
            yahoo_store: $yahooStore.length ? ($yahooStore.val() || '') : '',
            order: $order.val() || 'season',
            per_page: parseInt($perPage.val(), 10) || 30
        };
    }

    function isDefaultState(state) {
        state = state || currentState();

        return !(
            state.q ||
            state.municipality ||
            (state.municipalities && state.municipalities.length) ||
            state.fruit ||
            state.category ||
            state.subcategory ||
            state.type ||
            (state.categories && state.categories.length) ||
            state.status ||
            state.price ||
            state.price_min ||
            state.price_max ||
            state.portal ||
            state.yahoo_store ||
            (state.order && state.order !== 'season')
        );
    }

    function setSeasonVisibility(show) {
        if (!$seasonSection.length) return;
        $seasonSection.prop('hidden', !show);
    }

    function setOwnedContentVisibility(show) {
        if (!$ownedContentBlocks.length) return;
        $ownedContentBlocks.prop('hidden', !show);
    }

    function setHeroVisibility(show) {
        if (!$hero.length) return;
        $hero.prop('hidden', !show);
        $hero.toggleClass('is-hidden-after-search', !show);
    }

    function setRefineAvailability(available) {
        if (!$searchPanel.length) return;
        $searchPanel.toggleClass('is-refine-available', !!available);
        $headerRefine.prop('hidden', !available);
        if (!available) {
            $searchPanel.removeClass('is-refine-open');
            $headerRefine.attr('aria-expanded', 'false');
        }
    }

    function updateYahooStoreState() {
        if (!$yahooStore.length) return;

        const portal = $portal.length ? $portal.val() : '';
        const enabled = portal === '' || portal === 'yahoo';

        $yahooStore.prop('disabled', !enabled);

        if (!enabled) {
            $yahooStore.val('');
        }

        $yahooStore.closest('label').toggleClass(
            'is-disabled',
            !enabled
        );
    }

    const categoryTree = (window.NF_CATALOG && Array.isArray(NF_CATALOG.categoryTree)) ? NF_CATALOG.categoryTree : [];
    const municipalityTree = (window.NF_CATALOG && Array.isArray(NF_CATALOG.municipalityTree)) ? NF_CATALOG.municipalityTree : [];
    const $categoryEntryChips = $('.nf-category-entry-chips');
    const $categoryDrill = $('#nf_category_drill');
    const $categoryDrillTitle = $('#nf_category_drill_title');
    const $categoryDrillChips = $('#nf_category_drill_chips');
    const $categoryTreeRoot = $('#nf_catalog_category_tree');
    const $categoryTreeSummary = $('#nf_catalog_category_tree_summary');
    const $municipalityTreeRoot = $('#nf_catalog_municipality_tree');
    const $municipalityTreeSummary = $('#nf_catalog_municipality_tree_summary');
    let categoryDrillHistory = [];
    let currentCategoryDrillView = null;
    let categoryTreeBranchIndex = 0;


    function findCategory(slug) {
        return categoryTree.find(function(row){ return row.slug === slug; }) || null;
    }

    function findSubcategory(categorySlug, subSlug) {
        const cat = findCategory(categorySlug);
        if (!cat || !Array.isArray(cat.children)) return null;
        return cat.children.find(function(row){ return row.slug === subSlug; }) || null;
    }

    function categoryNodeForPath(categorySlug, subSlug, typeSlug) {
        const cat = findCategory(categorySlug || '');
        if (!cat) return null;
        if (!subSlug) return cat;
        const sub = findSubcategory(categorySlug, subSlug);
        if (!sub) return cat;
        if (!typeSlug) return sub;
        if (!Array.isArray(sub.children)) return sub;
        return sub.children.find(function(row){ return row.slug === typeSlug; }) || sub;
    }

    function applyCategoryPath(categorySlug, subSlug, typeSlug) {
        const cat = findCategory(categorySlug || '');
        const sub = cat && subSlug ? findSubcategory(cat.slug, subSlug) : null;
        const selectedType = sub && typeSlug && Array.isArray(sub.children)
            ? sub.children.find(function(row){ return row.slug === typeSlug; })
            : null;

        $category.val(cat ? cat.slug : '');
        refreshSubcategories(sub ? sub.slug : '');
        refreshTypes(selectedType ? selectedType.slug : '');
        syncCategoryTreeSelection();

        return {
            category: cat ? cat.slug : '',
            subcategory: sub ? sub.slug : '',
            type: selectedType ? selectedType.slug : ''
        };
    }

    function categoryDrillView(title, categorySlug, subSlug, typeSlug, children) {
        return {
            title: title || 'カテゴリを選択',
            category: categorySlug || '',
            subcategory: subSlug || '',
            type: typeSlug || '',
            children: Array.isArray(children) ? children : []
        };
    }

    function parentCategoryDrillView(categorySlug, subSlug, typeSlug) {
        if (typeSlug) {
            const sub = findSubcategory(categorySlug, subSlug);
            return sub
                ? categoryDrillView(sub.name, categorySlug, subSlug, '', sub.children)
                : null;
        }

        if (subSlug) {
            const cat = findCategory(categorySlug);
            return cat
                ? categoryDrillView(cat.name, categorySlug, '', '', cat.children)
                : null;
        }

        return null;
    }

    function renderCategoryDrill(view) {
        if (!$categoryDrill.length || !view || !view.children.length) return false;

        $categoryDrillTitle.text(view.title);
        $categoryDrillChips.empty();
        view.children.forEach(function(child){
            const nextCategory = view.category || child.slug;
            const nextSub = view.category ? (view.subcategory || child.slug) : '';
            const nextType = (view.category && view.subcategory) ? child.slug : '';
            const $button = $('<button type="button" class="nf-tax-chip nf-tax-chip-category-drill"></button>')
                .attr('data-category', nextCategory)
                .attr('data-subcategory', nextSub)
                .attr('data-type', nextType)
                .text(child.name);
            if (child.count) $button.append($('<span></span>').text(child.count));
            $categoryDrillChips.append($button);
        });
        $categoryEntryChips.attr('hidden', true);
        $categoryDrill.prop('hidden', false);
        return true;
    }

    function showCategoryDrill(title, categorySlug, subSlug, typeSlug, children, rememberCurrent) {
        if (!$categoryDrill.length || !Array.isArray(children) || !children.length) return false;

        if (rememberCurrent && currentCategoryDrillView) {
            categoryDrillHistory.push(currentCategoryDrillView);
        }

        currentCategoryDrillView = categoryDrillView(
            title,
            categorySlug,
            subSlug,
            typeSlug,
            children
        );
        return renderCategoryDrill(currentCategoryDrillView);
    }

    function startCategoryDrill(title, categorySlug, subSlug, typeSlug, children) {
        categoryDrillHistory = [];
        currentCategoryDrillView = null;

        const parentView = parentCategoryDrillView(categorySlug, subSlug, typeSlug);
        if (parentView && parentView.children.length) {
            categoryDrillHistory.push(parentView);
        }

        return showCategoryDrill(
            title,
            categorySlug,
            subSlug,
            typeSlug,
            children,
            false
        );
    }

    function resetCategoryDrill() {
        if (!$categoryDrill.length) return;
        categoryDrillHistory = [];
        currentCategoryDrillView = null;
        $categoryDrill.prop('hidden', true);
        $categoryDrillChips.empty();
        $categoryEntryChips.removeAttr('hidden');
    }

    function unavailableOption($select, text) {
        $select.empty().append($('<option value=""></option>').text(text));
        $select.prop('disabled', true);
    }

    function populateCategories() {
        if (!$category.length) return;
        $category.empty().append($('<option value="">すべて</option>'));
        categoryTree.forEach(function(row){
            $category.append($('<option></option>').val(row.slug).text(row.name));
        });

        if (!categoryTree.length) {
            unavailableOption($category, '該当なし');
        } else {
            $category.prop('disabled', false);
        }
    }

    function refreshSubcategories(selected) {
        if (!$subcategory.length) return;
        const cat = findCategory($category.val() || '');

        if (!cat) {
            unavailableOption($subcategory, '大カテゴリを選択してください');
            refreshTypes('');
            return;
        }

        if (!Array.isArray(cat.children) || !cat.children.length) {
            unavailableOption($subcategory, '該当なし');
            refreshTypes('');
            return;
        }

        $subcategory.empty().append($('<option value="">すべて</option>'));
        cat.children.forEach(function(row){
            $subcategory.append($('<option></option>').val(row.slug).text(row.name));
        });
        $subcategory.prop('disabled', false);
        if (selected && findSubcategory(cat.slug, selected)) $subcategory.val(selected);
        refreshTypes('');
    }

    function refreshTypes(selected) {
        if (!$type.length) return;
        const sub = findSubcategory($category.val() || '', $subcategory.val() || '');

        if (!sub) {
            unavailableOption($type, '小カテゴリを選択してください');
            return;
        }

        if (!Array.isArray(sub.children) || !sub.children.length) {
            unavailableOption($type, '該当なし');
            return;
        }

        $type.empty().append($('<option value="">すべて</option>'));
        sub.children.forEach(function(row){
            $type.append($('<option></option>').val(row.slug).text(row.name));
        });
        $type.prop('disabled', false);
        if (selected && sub.children.some(function(row){ return row.slug === selected; })) {
            $type.val(selected);
        }
    }

    function categoryPathLabel(categorySlug, subSlug, typeSlug) {
        const labels = [];
        const cat = findCategory(categorySlug || '');
        if (!cat) return '';
        labels.push(cat.name);

        const sub = subSlug ? findSubcategory(cat.slug, subSlug) : null;
        if (sub) labels.push(sub.name);

        if (sub && typeSlug && Array.isArray(sub.children)) {
            const typeNode = sub.children.find(function(row){ return row.slug === typeSlug; });
            if (typeNode) labels.push(typeNode.name);
        }

        return labels.join(' ＞ ');
    }

    function setCategoryBranchOpen($button, open) {
        const $item = $button.closest('.nf-category-tree-item');
        const $children = $item.children('.nf-category-tree-children');
        $children.prop('hidden', !open);
        $button
            .attr('aria-expanded', open ? 'true' : 'false')
            .attr('aria-label', ($button.data('name') || 'カテゴリ') + (open ? 'を閉じる' : 'を開く'))
            .toggleClass('is-open', open)
            .text(open ? '▲' : '▼');
    }

    function buildCategoryTreeItems(nodes, level, path) {
        const $fragment = $(document.createDocumentFragment());

        (Array.isArray(nodes) ? nodes : []).forEach(function(node){
            const nextPath = {
                category: level === 1 ? node.slug : path.category,
                subcategory: level === 2 ? node.slug : path.subcategory,
                type: level === 3 ? node.slug : path.type
            };
            const hasChildren = Array.isArray(node.children) && node.children.length > 0;
            const $item = $('<div class="nf-category-tree-item"></div>')
                .addClass('is-level-' + level);
            const $row = $('<div class="nf-category-tree-row"></div>');
            const $label = $('<label class="nf-category-tree-choice"></label>');
            const $checkbox = $('<input type="checkbox" class="nf-category-tree-check">')
                .attr('data-category', nextPath.category || '')
                .attr('data-subcategory', nextPath.subcategory || '')
                .attr('data-type', nextPath.type || '')
                .attr('aria-label', node.name + 'を選択');
            const $name = $('<span class="nf-category-tree-name"></span>').text(node.name);
            const $count = $('<small class="nf-category-tree-count"></small>')
                .text('（' + Number(node.count || 0).toLocaleString('ja-JP') + '）');

            $label.append($checkbox, $name, $count);
            $row.append($label);

            if (hasChildren) {
                categoryTreeBranchIndex += 1;
                const childrenId = 'nf_category_tree_branch_' + categoryTreeBranchIndex;
                const $toggle = $('<button type="button" class="nf-category-tree-toggle">▼</button>')
                    .attr('aria-expanded', 'false')
                    .attr('aria-controls', childrenId)
                    .attr('aria-label', node.name + 'を開く')
                    .attr('data-name', node.name);
                const $children = $('<div class="nf-category-tree-children" hidden></div>')
                    .attr('id', childrenId)
                    .append(buildCategoryTreeItems(node.children, level + 1, nextPath));

                $row.append($toggle);
                $item.append($row, $children);
            } else {
                $item.append($row);
            }

            $fragment.append($item);
        });

        return $fragment;
    }

    function renderCategoryTree() {
        if (!$categoryTreeRoot.length) return;
        categoryTreeBranchIndex = 0;
        $categoryTreeRoot.empty();

        if (!categoryTree.length) {
            $categoryTreeRoot.append(
                $('<p class="nf-category-tree-empty"></p>').text('該当するカテゴリはありません。')
            );
            return;
        }

        $categoryTreeRoot.append(buildCategoryTreeItems(categoryTree, 1, {
            category: '', subcategory: '', type: ''
        }));
        syncCategoryTreeSelection();
    }

    function syncCategoryTreeSelection() {
        if (!$categoryTreeRoot.length) return;

        const categorySlug = $category.val() || '';
        const subSlug = $subcategory.val() || '';
        const typeSlug = $type.val() || '';
        const $checks = $categoryTreeRoot.find('.nf-category-tree-check');
        $checks.prop('checked', false);
        $categoryTreeRoot.find('.nf-category-tree-row')
            .removeClass('is-selected is-parent-selected');
        $categoryTreeSummary.prop('hidden', true).text('');

        if (!categorySlug) {
            return;
        }

        const $target = $checks.filter(function(){
            return String($(this).data('category') || '') === categorySlug &&
                String($(this).data('subcategory') || '') === subSlug &&
                String($(this).data('type') || '') === typeSlug;
        }).first();

        if (!$target.length) return;
        $target.prop('checked', true);
        const $targetRow = $target.closest('.nf-category-tree-row');
        $targetRow.addClass('is-selected');

        const $ownToggle = $targetRow.find('.nf-category-tree-toggle').first();
        if ($ownToggle.length) setCategoryBranchOpen($ownToggle, true);

        $target.parents('.nf-category-tree-children').each(function(){
            const $children = $(this);
            const $parentRow = $children.siblings('.nf-category-tree-row').first();
            const $parentCheck = $parentRow.find('.nf-category-tree-check').first();
            const $toggle = $parentRow.find('.nf-category-tree-toggle').first();

            $parentCheck.prop('checked', true);
            $parentRow.addClass('is-parent-selected');
            if ($toggle.length) setCategoryBranchOpen($toggle, true);
        });

        const label = categoryPathLabel(categorySlug, subSlug, typeSlug);
        $categoryTreeSummary
            .text(label ? '選択中：' + label : '')
            .prop('hidden', !label);
    }

    function buildMunicipalityTreeItems(nodes, level) {
        const $fragment = $(document.createDocumentFragment());
        (Array.isArray(nodes) ? nodes : []).forEach(function(node){
            const hasChildren = Array.isArray(node.children) && node.children.length > 0;
            const $item = $('<div class="nf-category-tree-item"></div>').addClass('is-level-' + level);
            const $row = $('<div class="nf-category-tree-row"></div>');
            const $label = $('<label class="nf-category-tree-choice"></label>');
            const $checkbox = $('<input type="checkbox" class="nf-municipality-tree-check">')
                .attr('data-slug', node.slug || '')
                .attr('data-name', node.name || '')
                .attr('aria-label', (node.name || '自治体') + 'を選択');
            const $name = $('<span class="nf-category-tree-name"></span>').text(node.name || '');
            const $count = $('<small class="nf-category-tree-count"></small>')
                .text('（' + Number(node.count || 0).toLocaleString('ja-JP') + '）');

            $label.append($checkbox, $name, $count);
            $row.append($label);

            if (hasChildren) {
                categoryTreeBranchIndex += 1;
                const childrenId = 'nf_municipality_tree_branch_' + categoryTreeBranchIndex;
                const $toggle = $('<button type="button" class="nf-category-tree-toggle">▼</button>')
                    .attr('aria-expanded', 'false')
                    .attr('aria-controls', childrenId)
                    .attr('aria-label', node.name + 'を開く')
                    .attr('data-name', node.name);
                const $children = $('<div class="nf-category-tree-children" hidden></div>')
                    .attr('id', childrenId)
                    .append(buildMunicipalityTreeItems(node.children, level + 1));
                $row.append($toggle);
                $item.append($row, $children);
            } else {
                $item.append($row);
            }
            $fragment.append($item);
        });
        return $fragment;
    }

    function renderMunicipalityTree() {
        if (!$municipalityTreeRoot.length) return;
        $municipalityTreeRoot.empty();
        if (!municipalityTree.length) {
            $municipalityTreeRoot.append(
                $('<p class="nf-category-tree-empty"></p>').text('公開承認された自治体はありません。')
            );
            return;
        }
        $municipalityTreeRoot.append(buildMunicipalityTreeItems(municipalityTree, 1));
        syncMunicipalityTreeSelection();
    }

    function syncMunicipalityTreeSelection() {
        if (!$municipalityTreeRoot.length) return;
        const slug = $municipality.val() || '';
        const $checks = $municipalityTreeRoot.find('.nf-municipality-tree-check');
        $checks.prop('checked', false);
        $municipalityTreeRoot.find('.nf-category-tree-row').removeClass('is-selected');
        $municipalityTreeSummary.prop('hidden', true).text('');
        if (!slug) return;
        const $target = $checks.filter(function(){ return String($(this).data('slug') || '') === slug; }).first();
        if (!$target.length) return;
        $target.prop('checked', true);
        const $targetRow = $target.closest('.nf-category-tree-row');
        $targetRow.addClass('is-selected');
        // Match the gift-category tree: selecting a parent also opens its own
        // branch so the prefectural office and municipalities appear at once.
        const $ownToggle = $targetRow.find('.nf-category-tree-toggle').first();
        if ($ownToggle.length) setCategoryBranchOpen($ownToggle, true);
        $target.parents('.nf-category-tree-children').each(function(){
            const $toggle = $(this).siblings('.nf-category-tree-row').find('.nf-category-tree-toggle').first();
            if ($toggle.length) setCategoryBranchOpen($toggle, true);
        });
        $municipalityTreeSummary.text('選択中：' + String($target.data('name') || '')).prop('hidden', false);
    }

    populateCategories();
    refreshSubcategories('');
    renderCategoryTree();
    renderMunicipalityTree();

    function updateUrl(state) {
        if (!window.history || !window.history.replaceState) return;

        const url = new URL(window.location.href);

        const params = {
            q: state.q,
            municipality: state.municipality,
            municipalities: (state.municipalities || []).join(','),
            fruit: state.fruit,
            category: state.category,
            subcategory: state.subcategory,
            type: state.type,
            categories: (state.categories || []).join(','),
            status: state.status,
            price: state.price,
            price_min: state.price_min || '',
            price_max: state.price_max || '',
            portal: state.portal,
            yahoo_store: state.yahoo_store,
            order: state.order === 'season' ? '' : state.order,
            per_page: state.per_page === 30 ? '' : state.per_page
        };

        Object.keys(params).forEach(function(key){
            if (params[key]) {
                url.searchParams.set(key, params[key]);
            } else {
                url.searchParams.delete(key);
            }
        });

        window.history.replaceState({}, '', url.toString());
    }

    function activeFilterItems(state) {
        const items = [];

        if (state.q) {
            items.push({
                key: 'q',
                label: '検索',
                value: state.q
            });
        }

        if (state.municipalities && state.municipalities.length) {
            $('.nf-municipality-tree-check:checked').each(function(){ items.push({key:'municipality:'+String($(this).data('slug')||''),label:'自治体',value:String($(this).data('name')||'')}); });
        } else if (state.municipality) {
            items.push({
                key: 'municipality',
                label: '自治体',
                value: optionText($municipality)
            });
        }

        if (state.fruit) {
            items.push({
                key: 'fruit',
                label: (window.NF_CATALOG && NF_CATALOG.fruitLabel) ? NF_CATALOG.fruitLabel : 'カテゴリ',
                value: optionText($fruit)
            });
        }

        if (state.categories && state.categories.length) {
            $('.nf-category-tree-check:checked').each(function(){
                const slug=String($(this).data('type')||$(this).data('subcategory')||$(this).data('category')||'');
                items.push({key:'category:'+slug,label:'カテゴリ',value:$(this).siblings('.nf-category-tree-name').text()||slug});
            });
        } else if (state.category) {
            items.push({key:'category',label:'大カテゴリ',value:optionText($category)});
        }
        if (state.subcategory) {
            items.push({key:'subcategory',label:'小カテゴリ',value:optionText($subcategory)});
        }
        if (state.type) {
            items.push({key:'type',label:'詳細分類',value:optionText($type)});
        }

        if (state.price_min || state.price_max) {
            const minLabel = state.price_min ? Number(state.price_min).toLocaleString() + '円' : '下限なし';
            const maxLabel = state.price_max ? Number(state.price_max).toLocaleString() + '円' : '上限なし';
            items.push({
                key: 'price_custom',
                label: '寄附額',
                value: minLabel + '〜' + maxLabel
            });
        } else if (state.price) {
            items.push({
                key: 'price',
                label: '寄附額',
                value: optionText($priceRange)
            });
        }

        if (state.portal) {
            items.push({
                key: 'portal',
                label: '掲載先',
                value: optionText($portal)
            });
        }

        if (state.yahoo_store) {
            items.push({
                key: 'yahoo_store',
                label: 'Yahoo!ストア',
                value: optionText($yahooStore)
            });
        }

        if (state.status) {
            items.push({
                key: 'status',
                label: '受付',
                value: optionText($status)
            });
        }

        if (state.order && state.order !== 'season') {
            items.push({
                key: 'order',
                label: '並び順',
                value: optionText($order)
            });
        }

        return items;
    }

    function renderActiveFilters(state, found) {
        if (!$activeFilters.length) return;

        const items = activeFilterItems(state);
        const active = items.length > 0;

        $activeFilters.prop('hidden', !active);

        if (!active) {
            $activeFilterChips.empty();
            $activeFilterCount.text('');
            return;
        }

        $activeFilterCount.text(
            Number(found || 0).toLocaleString() + '件'
        );

        $activeFilterChips.empty();

        items.forEach(function(item){
            const $button = $('<button type="button" class="nf-active-filter-chip"></button>');
            const $label = $('<small></small>').text(item.label);
            const $value = $('<strong></strong>').text(item.value);
            const $remove = $('<span aria-hidden="true">×</span>');

            $button.attr('data-filter-key', item.key);
            $button.attr(
                'aria-label',
                item.label + '「' + item.value + '」を解除'
            );

            $button.append($label, $value, $remove);
            $activeFilterChips.append($button);
        });
    }

    function clearFilterKey(key) {
        if (key.indexOf('municipality:')===0) {
            const slug=key.slice(13); $('.nf-municipality-tree-check').filter(function(){return String($(this).data('slug')||'')===slug;}).prop('checked',false); updateSelectionSummary(); return;
        }
        if (key.indexOf('category:')===0) {
            const slug=key.slice(9); $('.nf-category-tree-check').filter(function(){return String($(this).data('type')||$(this).data('subcategory')||$(this).data('category')||'')===slug;}).prop('checked',false); updateSelectionSummary(); return;
        }
        switch (key) {
            case 'q':
                $keyword.val('');
                break;
            case 'municipality':
                $municipality.val('');
                break;
            case 'fruit':
                $fruit.val('');
                break;
            case 'category':
                $category.val('');
                refreshSubcategories('');
                resetCategoryDrill();
                syncCategoryTreeSelection();
                break;
            case 'subcategory':
                $subcategory.val('');
                refreshTypes('');
                syncCategoryTreeSelection();
                break;
            case 'type':
                $type.val('');
                syncCategoryTreeSelection();
                break;
            case 'status':
                $status.val('');
                break;
            case 'price':
                if ($priceRange.length) $priceRange.val('');
                break;
            case 'price_custom':
                if ($priceMin.length) $priceMin.val('');
                if ($priceMax.length) $priceMax.val('');
                break;
            case 'portal':
                if ($portal.length) $portal.val('');
                if ($yahooStore.length) $yahooStore.val('');
                break;
            case 'yahoo_store':
                if ($yahooStore.length) $yahooStore.val('');
                break;
            case 'order':
                $order.val('season');
                break;
        }

        updateYahooStoreState();
    }

    function scrollToProducts() {
        const target = document.getElementById(
            'nf_catalog_products_section'
        );

        if (!target) return;

        const reduceMotion = window.matchMedia &&
            window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        const header = document.getElementById('nf_furusato_header');
        const headerHeight = header ? header.getBoundingClientRect().height : 0;
        const adminBar = document.getElementById('wpadminbar');
        const adminBarHeight = adminBar ? adminBar.getBoundingClientRect().height : 0;
        const top = target.getBoundingClientRect().top + window.pageYOffset -
            headerHeight - adminBarHeight - 8;

        window.scrollTo({
            top: Math.max(0, top),
            behavior: reduceMotion ? 'auto' : 'smooth'
        });
    }

    function commitViewState(state, found, page) {
        appliedState = state;

        page = Number(page || 1);
        const active = !isDefaultState(state) || page > 1;

        setHeroVisibility(!active);
        setSeasonVisibility(!active);
        setOwnedContentVisibility(!active);
        setRefineAvailability(active);
        renderActiveFilters(state, found);
        updateUrl(state);

        // 検索後は大きなフィルターを残さない。
        if (active) {
            setDrawer(
                $filterToggle,
                $filterDrawer,
                false
            );
            setDrawer(
                $browseToggle,
                $browseDrawer,
                false
            );
        }
    }

    function runFilter(page, options) {
        options = $.extend({
            scroll: false,
            closeFilter: false
        }, options || {});

        page = Number(page || 1);

        if (request && request.readyState !== 4) {
            request.abort();
        }

        const state = currentState();

        $loading.show();
        $results.addClass('is-loading');

        // 条件検索中は旬枠を一覧から除外しない。
        const showFeature = isDefaultState(state);
        setSeasonVisibility(showFeature);

        request = $.post(NF_CATALOG.ajaxUrl, {
            action: 'nf_catalog_filter',
            nonce: NF_CATALOG.nonce,
            keyword: state.q,
            municipality: state.municipality,
            municipalities: (state.municipalities || []).join(','),
            fruit: state.fruit,
            category: state.category,
            subcategory: state.subcategory,
            type: state.type,
            categories: (state.categories || []).join(','),
            status: state.status,
            price_range: state.price,
            price_min: state.price_min,
            price_max: state.price_max,
            portal: state.portal,
            yahoo_store: state.yahoo_store,
            order: state.order,
            per_page: state.per_page,
            paged: page,
            show_feature: showFeature ? 1 : 0
        }).done(function(res){
            if (!res || !res.success) return;

            $results.html(res.data.html || '');
            $pagination.html(res.data.pagination || '');
            $count.text(Number(res.data.found || 0));
            $listTitle.text(
                res.data.listTitle || '返礼品一覧'
            );
            $range.text(res.data.rangeText || '');

            commitViewState(
                state,
                Number(res.data.found || 0),
                page
            );

            if (options.closeFilter) {
                setDrawer(
                    $filterToggle,
                    $filterDrawer,
                    false
                );
            }

            if (options.scroll) {
                window.setTimeout(
                    scrollToProducts,
                    80
                );
            }
        }).always(function(){
            $loading.hide();
            $results.removeClass('is-loading');
        });
    }

    // ========================================================
    // Filter drawer
    // ========================================================
    $filterToggle.on('click', function(){
        const open = $filterDrawer.prop('hidden');
        setDrawer(
            $filterToggle,
            $filterDrawer,
            open
        );
    });

    $headerRefine.on('click', function(){
        const open = $filterDrawer.prop('hidden');
        setDrawer($filterToggle, $filterDrawer, open);

        if (open) {
            const panel = document.getElementById('nf_catalog_search_panel');
            if (panel) {
                panel.scrollIntoView({behavior:'smooth', block:'start'});
            }
        }
    });

    $changeFilters.on('click', function(){
        setDrawer(
            $filterToggle,
            $filterDrawer,
            true
        );

        const panel = document.getElementById(
            'nf_catalog_search_panel'
        );

        if (panel) {
            panel.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        }
    });

    $applyFilters.on('click', function(){
        runFilter(1, {
            scroll: true,
            closeFilter: true
        });
    });

    // セレクト変更中は検索しない。
    // Yahoo!ストアだけ、依存する掲載ポータル状態を整える。
    $portal.on('change', function(){
        if (
            $yahooStore.length &&
            $portal.val() !== 'yahoo'
        ) {
            $yahooStore.val('');
        }

        updateYahooStoreState();
        autoApplyOnDesktop();
    });

    $yahooStore.on('change', function(){
        if (
            $yahooStore.val() &&
            $portal.length
        ) {
            $portal.val('yahoo');
        }

        updateYahooStoreState();
        autoApplyOnDesktop();
    });

    $status.on('change', autoApplyOnDesktop);
    $order.add($perPage).on('change', function(){
        runFilter(1, {
            scroll:isMobileFilterMode(),
            closeFilter:false
        });
    });

    // ========================================================
    // Keyword search
    // ========================================================
    $('#nf_catalog_search_button').on('click', function(){
        runFilter(1, {
            scroll: true,
            closeFilter: true
        });
    });

    $keyword.on('keydown', function(e){
        if (e.key === 'Enter') {
            e.preventDefault();

            runFilter(1, {
                scroll: true,
                closeFilter: true
            });
        }
    });

    // ========================================================
    // Reset / remove chips
    // ========================================================
    $('#nf_catalog_reset').on('click', function(){
        $keyword.val('');
        $municipality.val('');
        $('.nf-municipality-tree-check').prop('checked',false);
        $fruit.val('');
        $category.val('');
        refreshSubcategories('');
        resetCategoryDrill();
        syncCategoryTreeSelection();
        $('.nf-category-tree-check').prop('checked',false);
        updateSelectionSummary();
        $status.val('');

        if ($priceRange.length) {
            $priceRange.val('');
        }
        if ($priceMin.length) $priceMin.val('');
        if ($priceMax.length) $priceMax.val('');

        if ($portal.length) {
            $portal.val('');
        }

        if ($yahooStore.length) {
            $yahooStore.val('');
        }

        $order.val('season');
        $perPage.val('30');

        updateYahooStoreState();

        runFilter(1, {
            scroll: true,
            closeFilter: true
        });
    });

    $(document).on(
        'click',
        '.nf-active-filter-chip',
        function(){
            clearFilterKey(
                $(this).data('filter-key')
            );

            runFilter(1, {
                scroll: false,
                closeFilter: true
            });
        }
    );

    // ========================================================
    // Browse chips: 1-click direct filtering
    // ========================================================
    $browseToggle.on('click', function(){
        const open = $browseDrawer.prop('hidden');

        setDrawer(
            $browseToggle,
            $browseDrawer,
            open
        );
    });

    $(document).on(
        'click',
        '.nf-tax-chip-municipality',
        function(){
            $municipality.val(
                $(this).data('slug')
            );

            setDrawer(
                $browseToggle,
                $browseDrawer,
                false
            );

            runFilter(1, {
                scroll: true,
                closeFilter: true
            });
        }
    );

    $(document).on(
        'click',
        '.nf-tax-chip-fruit',
        function(){
            $fruit.val(
                $(this).data('slug')
            );
            $category.val('');
            refreshSubcategories('');
            resetCategoryDrill();
            syncCategoryTreeSelection();

            setDrawer(
                $browseToggle,
                $browseDrawer,
                false
            );

            runFilter(1, {
                scroll: true,
                closeFilter: true
            });
        }
    );

    $category.on('change', function(){
        $fruit.val('');
        refreshSubcategories('');
        syncCategoryTreeSelection();
    });
    $subcategory.on('change', function(){
        refreshTypes('');
        syncCategoryTreeSelection();
    });
    $type.on('change', function(){
        syncCategoryTreeSelection();
    });

    $priceRange.on('change', function(){
        if ($(this).val()) {
            $priceMin.val('');
            $priceMax.val('');
        }
    });
    $priceMin.add($priceMax).on('input change', function(){
        if ($(this).val() !== '') {
            $priceRange.val('');
        }
        // 金額は桁を入力している途中で検索しない。「適用」または検索
        // ボタンを押した時点で初めて反映し、入力パネルを維持する。
    });

    $(document).on('click', '.nf-category-tree-toggle', function(){
        setCategoryBranchOpen($(this), $(this).attr('aria-expanded') !== 'true');
    });

    $(document).on('change', '.nf-category-tree-check', function(){
        const $choice = $(this);
        if (multiCategory) {
            if ($choice.prop('checked')) {
                $choice.closest('.nf-category-tree-item').find('>.nf-category-tree-children .nf-category-tree-check').prop('checked',false);
                $choice.parents('.nf-category-tree-children').each(function(){ $(this).siblings('.nf-category-tree-row').find('.nf-category-tree-check').prop('checked',false); });
            }
            updateSelectionSummary();
            return;
        }
        if (!$choice.prop('checked')) {
            applyCategoryPath('', '', '');
            applyTreeChoice();
            return;
        }

        $fruit.val('');
        applyCategoryPath(
            String($choice.data('category') || ''),
            String($choice.data('subcategory') || ''),
            String($choice.data('type') || '')
        );
        applyTreeChoice();
    });

    $(document).on('change', '.nf-municipality-tree-check', function(){
        const $choice = $(this);
        if (!multiMunicipality) $municipalityTreeRoot.find('.nf-municipality-tree-check').not(this).prop('checked', false);
        $municipality.val($choice.prop('checked') ? String($choice.data('slug') || '') : '');
        if (!multiMunicipality) syncMunicipalityTreeSelection(); else updateSelectionSummary();
        applyTreeChoice();
    });

    $(document).on('click', '.nf-tax-chip-category', function(){
        const categorySlug = String($(this).data('category') || $(this).data('slug') || '');
        const subSlug = String($(this).data('subcategory') || '');
        const typeSlug = String($(this).data('type') || '');
        $fruit.val('');
        applyCategoryPath(categorySlug, subSlug, typeSlug);

        const node = categoryNodeForPath(categorySlug, subSlug, typeSlug);
        const children = node && Array.isArray(node.children) ? node.children : [];
        const hasChildren = startCategoryDrill(
            node ? node.name : $(this).text(),
            categorySlug,
            subSlug,
            typeSlug,
            children
        );

        if (hasChildren) return;

        resetCategoryDrill();
        setDrawer($browseToggle, $browseDrawer, false);
        runFilter(1, {scroll:true, closeFilter:true});
    });

    $(document).on('click', '.nf-tax-chip-category-drill', function(){
        const categorySlug = String($(this).data('category') || '');
        const subSlug = String($(this).data('subcategory') || '');
        const typeSlug = String($(this).data('type') || '');
        applyCategoryPath(categorySlug, subSlug, typeSlug);

        const node = categoryNodeForPath(categorySlug, subSlug, typeSlug);
        const children = node && Array.isArray(node.children) ? node.children : [];
        const hasChildren = showCategoryDrill(
            node ? node.name : $(this).text(),
            categorySlug,
            subSlug,
            typeSlug,
            children,
            true
        );

        if (hasChildren) return;

        resetCategoryDrill();
        setDrawer($browseToggle, $browseDrawer, false);
        runFilter(1, {scroll:true, closeFilter:true});
    });

    $(document).on('click', '#nf_category_drill_back', function(){
        if (categoryDrillHistory.length) {
            currentCategoryDrillView = categoryDrillHistory.pop();
            applyCategoryPath(
                currentCategoryDrillView.category,
                currentCategoryDrillView.subcategory,
                currentCategoryDrillView.type
            );
            renderCategoryDrill(currentCategoryDrillView);
            return;
        }

        resetCategoryDrill();
        applyCategoryPath('', '', '');
    });

    // ========================================================
    // Pagination
    // ========================================================
    $(document).on(
        'click',
        '.nf-catalog-page-button',
        function(){
            runFilter(
                $(this).data('page'),
                {
                    scroll: true,
                    closeFilter: true
                }
            );
        }
    );

    // ========================================================
    // Restore URL state
    // ========================================================
    const params = new URLSearchParams(
        window.location.search
    );

    if (params.get('q')) {
        $keyword.val(params.get('q'));
    }

    if (params.get('municipality')) {
        $municipality.val(
            params.get('municipality')
        );
        syncMunicipalityTreeSelection();
    }
    if (multiMunicipality && params.get('municipalities')) {
        const selected=params.get('municipalities').split(',');
        $municipalityTreeRoot.find('.nf-municipality-tree-check').each(function(){ $(this).prop('checked',selected.indexOf(String($(this).data('slug')||''))!==-1); });
    }

    if (params.get('fruit')) {
        $fruit.val(params.get('fruit'));
    }

    if (params.get('category')) {
        applyCategoryPath(
            params.get('category'),
            params.get('subcategory') || '',
            params.get('type') || ''
        );
    }
    if (multiCategory && params.get('categories')) {
        const selected=params.get('categories').split(',');
        $categoryTreeRoot.find('.nf-category-tree-check').each(function(){
            const slug=String($(this).data('type')||$(this).data('subcategory')||$(this).data('category')||'');
            $(this).prop('checked',selected.indexOf(slug)!==-1);
        });
    }
    updateSelectionSummary();

    if (params.get('status')) {
        $status.val(params.get('status'));
    }

    if (
        $priceRange.length &&
        params.get('price')
    ) {
        $priceRange.val(params.get('price'));
    }

    if ($priceMin.length && params.get('price_min')) {
        $priceMin.val(params.get('price_min'));
        $priceRange.val('');
    }
    if ($priceMax.length && params.get('price_max')) {
        $priceMax.val(params.get('price_max'));
        $priceRange.val('');
    }

    if (
        $portal.length &&
        params.get('portal')
    ) {
        $portal.val(params.get('portal'));
    }

    if (
        $yahooStore.length &&
        params.get('yahoo_store')
    ) {
        $yahooStore.val(
            params.get('yahoo_store')
        );

        if (!$portal.val()) {
            $portal.val('yahoo');
        }
    }

    if (params.get('order')) {
        $order.val(params.get('order'));
    }
    if (params.get('per_page')) {
        $perPage.val(params.get('per_page'));
    }

    updateYahooStoreState();

    const urlState = currentState();
    const hasUrlContext = !isDefaultState(urlState) ||
        (params.get('per_page') && urlState.per_page !== 30);

    if (hasUrlContext) {
        runFilter(1, {
            scroll: false,
            closeFilter: true
        });
    } else {
        appliedState = urlState;
        setHeroVisibility(true);
        setSeasonVisibility(true);
        setOwnedContentVisibility(true);
        setRefineAvailability(false);
        renderActiveFilters(
            urlState,
            Number($count.text() || 0)
        );
    }
});
