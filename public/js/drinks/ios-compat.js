(function() {
    function isOldIOS() {
        var ua = navigator.userAgent;
        var m = ua.match(/OS (\d+)_/);
        if (!m) return false;
        var v = parseInt(m[1], 10);
        return /iPad|iPhone|iPod/.test(ua) && v <= 12;
    }
    function supportsSticky() {
        var el = document.createElement('div');
        el.style.position = 'sticky';
        return el.style.position.indexOf('sticky') !== -1;
    }
    document.addEventListener('DOMContentLoaded', function() {
        if (!isOldIOS() || supportsSticky()) return;
        var bar = document.querySelector('.user-info-bar.sticky-user-info-bar');
        if (!bar) return;
        // Insert placeholder to prevent layout shift
        var placeholder = document.createElement('div');
        placeholder.style.display = 'none';
        bar.parentNode.insertBefore(placeholder, bar);
        var barHeight = bar.offsetHeight;
        var origStyle = bar.getAttribute('style') || '';
        function fixBar() {
            bar.style.position = 'fixed';
            bar.style.top = '0';
            bar.style.left = '0';
            bar.style.right = '0';
            bar.style.width = '100%';
            bar.style.zIndex = '2000';
            bar.style.boxShadow = '0 4px 8px rgba(33,150,243,0.12)';
            placeholder.style.display = 'block';
            placeholder.style.height = barHeight + 'px';
        }
        function unfixBar() {
            bar.setAttribute('style', origStyle);
            placeholder.style.display = 'none';
        }
        function onScroll() {
            var scrollY = window.scrollY || window.pageYOffset;
            var barTop = bar.offsetTop;
            if (scrollY > barTop) {
                fixBar();
            } else {
                unfixBar();
            }
        }
        window.addEventListener('scroll', onScroll);
        window.addEventListener('resize', function() {
            barHeight = bar.offsetHeight;
            if (placeholder.style.display === 'block') {
                placeholder.style.height = barHeight + 'px';
            }
        });
    });
})();
