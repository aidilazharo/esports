/* Mobile icon labels + tooltips. This file never changes link destinations. */
(function () {
    'use strict';

    const mobileQuery = window.matchMedia('(max-width: 767.98px)');
    const selectors = [
        'a.btn',
        '#mainNav a.nav-link',
        '#mainNav a.dropdown-item',
        '.portal-sidebar a.nav-link',
        '.jpn-sidebar a.nav-link',
        '.mobile-top-nav a.mobile-nav-link',
        '.mobile-bottom-nav a.mobile-nav-link',
        '.mobile-bottom-nav button.mobile-nav-action'
    ].join(', ');

    const iconRules = [
        [/youtube|youtu\.be|watch.*video/i, 'bi-youtube'],
        [/whatsapp/i, 'bi-whatsapp'],
        [/logout|sign.?out/i, 'bi-box-arrow-right'],
        [/login|sign.?in/i, 'bi-person-circle'],
        [/home|index/i, 'bi-house'],
        [/about|explore|information/i, 'bi-info-circle'],
        [/previous|\bback\b|return/i, 'bi-arrow-left-circle'],
        [/next|continue|proceed/i, 'bi-arrow-right-circle'],
        [/download|export/i, 'bi-download'],
        [/upload|import/i, 'bi-upload'],
        [/payment|pay\b|checkout/i, 'bi-credit-card'],
        [/certificate|award/i, 'bi-award'],
        [/attendance|scan/i, 'bi-qr-code-scan'],
        [/support|help|faq/i, 'bi-question-circle'],
        [/contact|email|message/i, 'bi-envelope'],
        [/manual|guide/i, 'bi-book'],
        [/tournament|competition|event/i, 'bi-trophy'],
        [/register.*school|schools?/i, 'bi-building'],
        [/register.*teacher|teachers?/i, 'bi-person-badge'],
        [/register.*student|students?|members/i, 'bi-people'],
        [/programme|learning|module|course/i, 'bi-journal-richtext'],
        [/profile/i, 'bi-person-vcard'],
        [/report|analytics|statistic/i, 'bi-bar-chart-line'],
        [/dashboard/i, 'bi-speedometer2'],
        [/setting/i, 'bi-gear'],
        [/coupon|redeem/i, 'bi-ticket-perforated'],
        [/edit|update|modify/i, 'bi-pencil-square'],
        [/delete|remove|trash/i, 'bi-trash'],
        [/create|add|new|join|apply/i, 'bi-plus-circle'],
        [/save|submit/i, 'bi-check2-circle'],
        [/view|show|preview|detail|open/i, 'bi-eye'],
        [/search|find/i, 'bi-search']
    ];

    function getLabel(link) {
        // The visible text is more reliable than icon-only title attributes.
        const label = link.getAttribute('aria-label')
            || link.getAttribute('title')
            || link.textContent;
        return String(label || '').replace(/\s+/g, ' ').trim().slice(0, 140);
    }

    function resolveIcon(label, href) {
        const words = label + ' ' + href;
        for (const rule of iconRules) {
            if (rule[0].test(words)) return rule[1];
        }
        return 'bi-link-45deg';
    }

    function enhance(link) {
        if (link.dataset.mobileIconReady === '1') return;
        if (link.closest('.breadcrumb, .pagination, .btn-group[role="group"], [data-mobile-icon-links="off"]')) return;
        if (link.querySelector('img, svg, iframe, video, table, p, h1, h2, h3, h4, div')) return;

        const label = getLabel(link);
        if (!label) return;

        const href = link.getAttribute('href') || '';
        const hadIcon = Array.from(link.querySelectorAll('i.bi')).find(function (icon) {
            return !icon.classList.contains('bi-arrow-right')
                && !icon.classList.contains('bi-box-arrow-up-right')
                && !icon.classList.contains('bi-chevron-right');
        });

        // Only direct text and clearly textual children are hidden on mobile.
        // Desktop content is structurally and visually preserved.
        Array.from(link.childNodes).forEach(function (child) {
            if (child.nodeType === Node.TEXT_NODE && child.textContent.trim()) {
                const wrapper = document.createElement('span');
                wrapper.className = 'mobile-icon-label';
                wrapper.textContent = child.textContent;
                child.replaceWith(wrapper);
            } else if (child.nodeType === Node.ELEMENT_NODE) {
                if (child.matches('i.bi-arrow-right, i.bi-box-arrow-up-right, i.bi-chevron-right')) {
                    child.classList.add('mobile-icon-decoration');
                } else if (
                    child.matches('span, strong, small, b, em')
                    && !child.classList.contains('visually-hidden')
                    && !child.querySelector('i, img, svg')
                ) {
                    child.classList.add('mobile-icon-label');
                }
            }
        });

        if (!hadIcon) {
            const icon = document.createElement('i');
            icon.className = 'bi ' + resolveIcon(label, href) + ' mobile-only-icon';
            icon.setAttribute('aria-hidden', 'true');
            link.prepend(icon);
        }

        link.classList.add('mobile-icon-link');
        link.dataset.mobileIconReady = '1';
        link.setAttribute('title', label);
        if (!link.hasAttribute('aria-label')) link.setAttribute('aria-label', label);
    }

    function initTooltip(link) {
        // Native title and aria-label still work if Bootstrap fails to load.
        if (!mobileQuery.matches || link.dataset.mobileTooltipReady === '1') return;
        if (!window.bootstrap || !window.bootstrap.Tooltip) return;
        link.dataset.mobileTooltipReady = '1';
        const tooltip = new window.bootstrap.Tooltip(link, {
            title: link.getAttribute('aria-label') || link.getAttribute('title'),
            trigger: 'manual',
            placement: 'top',
            container: 'body',
            boundary: 'viewport'
        });

        let holdTimer = null;
        let wasLongPress = false;
        let dismissTimer = null;
        const hide = function () {
            if (holdTimer !== null) window.clearTimeout(holdTimer);
            if (dismissTimer !== null) window.clearTimeout(dismissTimer);
            holdTimer = null;
            dismissTimer = null;
            tooltip.hide();
        };
        const show = function () {
            if (mobileQuery.matches) tooltip.show();
        };

        link.addEventListener('mouseenter', show);
        link.addEventListener('mouseleave', hide);
        link.addEventListener('focus', show);
        link.addEventListener('blur', hide);
        link.addEventListener('pointerdown', function (event) {
            if (!mobileQuery.matches || event.pointerType !== 'touch') return;
            wasLongPress = false;
            if (holdTimer !== null) window.clearTimeout(holdTimer);
            holdTimer = window.setTimeout(function () {
                wasLongPress = true;
                tooltip.show();
                dismissTimer = window.setTimeout(function () {
                    tooltip.hide();
                }, 2500);
            }, 550);
        });
        link.addEventListener('pointerup', function () {
            if (holdTimer !== null) window.clearTimeout(holdTimer);
            holdTimer = null;
        });
        link.addEventListener('pointercancel', hide);
        // Short taps follow the original link immediately. Long-press shows
        // the tooltip instead and prevents just that synthetic click.
        link.addEventListener('click', function (event) {
            if (wasLongPress && event.detail > 0) {
                event.preventDefault();
                event.stopPropagation();
                wasLongPress = false;
            }
        }, true);
        link.addEventListener('contextmenu', function (event) {
            if (wasLongPress && mobileQuery.matches) event.preventDefault();
        });
    }

    function start() {
        const links = document.querySelectorAll(selectors);
        links.forEach(enhance);
        function enableMobileTooltips() {
            if (!mobileQuery.matches) return;
            links.forEach(function (link) {
                if (link.dataset.mobileIconReady === '1') initTooltip(link);
            });
        }
        enableMobileTooltips();
        if (typeof mobileQuery.addEventListener === 'function') {
            mobileQuery.addEventListener('change', enableMobileTooltips);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start, { once: true });
    } else {
        start();
    }
})();
