(() => {
    'use strict';

    const supported = new Set(['en', 'ms', 'zh', 'ta']);
    const dictionary = window.portalLanguageCatalog || {};

    // Translate only explicitly reviewed interface strings, never arbitrary content.
    const translate = (text) => {
        if (!text || typeof text !== 'string') return null;
        const key = text.trim().replace(/\s+/g, ' ');
        return Object.prototype.hasOwnProperty.call(dictionary, key)
            ? dictionary[key]
            : null;
    };

    const skip = 'script,style,noscript,textarea,pre,code,[contenteditable],[data-language-no-translate]';

    const translateTree = (root) => {
        if (!root || !Object.keys(dictionary).length) return;

        if (root.nodeType === Node.TEXT_NODE) {
            if (root.parentElement?.closest(skip)) return;
            const replacement = translate(root.nodeValue);
            if (replacement !== null) {
                const original = root.nodeValue;
                const leading = original.match(/^\s*/)?.[0] || '';
                const trailing = original.match(/\s*$/)?.[0] || '';
                root.nodeValue = leading + replacement + trailing;
            }
            return;
        }

        if (root.nodeType !== Node.ELEMENT_NODE) return;
        if (root.closest(skip)) return;

        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
        const nodes = [];
        while (walker.nextNode()) nodes.push(walker.currentNode);
        nodes.forEach((node) => translateTree(node));

        const elements = [root, ...root.querySelectorAll('[aria-label],[title],[placeholder],input[type="submit"],input[type="button"]')];
        for (const element of elements) {
            if (element.closest(skip)) continue;
            for (const attribute of ['aria-label', 'title', 'placeholder']) {
                if (!element.hasAttribute(attribute)) continue;
                const value = translate(element.getAttribute(attribute));
                if (value !== null) element.setAttribute(attribute, value);
            }
            if (element.matches('input[type="submit"],input[type="button"]')) {
                const value = translate(element.value);
                if (value !== null) element.value = value;
            }
        }
    };

    const ready = () => {
        document.querySelectorAll('[data-portal-language-select]').forEach((select) => {
            select.addEventListener('change', () => {
                const language = select.value;
                if (!supported.has(language)) return;

                let destination;
                try {
                    destination = new URL(select.dataset.returnUrl || location.pathname + location.search, location.origin);
                    if (destination.origin !== location.origin || !destination.pathname.startsWith('/')) return;
                } catch (_) {
                    return;
                }

                const secure = location.protocol === 'https:' ? '; Secure' : '';
                document.cookie = 'portal_lang=' + encodeURIComponent(language)
                    + '; Path=/; Max-Age=31536000; SameSite=Lax' + secure;

                // The original PHP route bypasses /html/ snapshot redirect for non-English languages.
                window.location.assign(destination.pathname + destination.search + destination.hash);
            });
        });

        translateTree(document.body);

        // Some portal panels are rendered after page load. Translate added
        // reviewed UI strings without affecting form values or fetched data.
        if (Object.keys(dictionary).length && window.MutationObserver) {
            const observer = new MutationObserver((mutations) => {
                for (const mutation of mutations) {
                    for (const node of mutation.addedNodes) {
                        if (node.nodeType === Node.ELEMENT_NODE || node.nodeType === Node.TEXT_NODE) {
                            translateTree(node);
                        }
                    }
                }
            });
            observer.observe(document.body, { childList: true, subtree: true });
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', ready, { once: true });
    } else {
        ready();
    }
})();