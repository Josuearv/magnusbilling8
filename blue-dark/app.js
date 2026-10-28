/**
 * MagnusBilling 8 - Theme Fallback Shim: app.js
 * Invoked if a cached Sencha Microloader requests <theme>/app.js directly.
 */
(function() {
    function loadScript(src, next) {
        var el = document.createElement('script');
        el.type = 'text/javascript';
        el.src = src;
        if (next) {
            el.onload = next;
        }
        document.head.appendChild(el);
    }

    if (typeof Ext === 'undefined' || !Ext.application) {
        loadScript('ext/build/ext-all.js', function() {
            if (typeof MBilling === 'undefined' || !MBilling.Application) {
                loadScript('classic/src/Application.js', function() {
                    loadScript('app.js');
                });
            } else {
                loadScript('app.js');
            }
        });
    } else if (typeof MBilling === 'undefined' || !MBilling.Application) {
        loadScript('classic/src/Application.js', function() {
            loadScript('app.js');
        });
    } else if (typeof MBilling.app === 'undefined') {
        loadScript('app.js');
    }
})();
