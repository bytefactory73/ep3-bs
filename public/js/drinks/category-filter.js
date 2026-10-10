document.addEventListener('DOMContentLoaded', function() {
    var catBtns = document.querySelectorAll('.category-btn');
    var wrapper = document.querySelector('.drink-tiles-wrapper');
    if (!wrapper) return;
    var tiles = Array.prototype.slice.call(wrapper.querySelectorAll('.drink-tile'));
    // Store original order
    tiles.forEach(function(tile, idx) {
        tile.setAttribute('data-original-index', idx);
    });
    catBtns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            catBtns.forEach(function(b) { b.style.background = '#fff'; });
            this.style.background = '#e3f0fa';
            var cat = this.getAttribute('data-category');
            var allTiles = Array.prototype.slice.call(wrapper.querySelectorAll('.drink-tile'));
            if (cat === 'favourites') {
                // Show only favourites, sort by current order count, then favourite-count, then name
                var favTiles = allTiles.filter(function(tile) {
                    return parseInt(tile.getAttribute('favourite-count')) > 0;
                });
                favTiles.sort(function(a, b) {
                    var inputA = a.querySelector('input[type="hidden"]');
                    var inputB = b.querySelector('input[type="hidden"]');
                    var countA = parseInt(inputA.value) || 0;
                    var countB = parseInt(inputB.value) || 0;
                    if (countA !== countB) return countB - countA;
                    var fa = parseInt(a.getAttribute('favourite-count')) || 0;
                    var fb = parseInt(b.getAttribute('favourite-count')) || 0;
                    if (fa !== fb) return fb - fa;
                    var na = a.querySelector('.drink-name').textContent.trim().toLowerCase();
                    var nb = b.querySelector('.drink-name').textContent.trim().toLowerCase();
                    return na.localeCompare(nb);
                });
                // Hide all, then show and append sorted favs
                allTiles.forEach(function(tile) { tile.style.display = 'none'; });
                favTiles.forEach(function(tile) {
                    tile.style.display = '';
                    wrapper.appendChild(tile);
                });
            } else {
                // Show all or by category, restore original order
                allTiles.sort(function(a, b) {
                    return (parseInt(a.getAttribute('data-original-index')) || 0) - (parseInt(b.getAttribute('data-original-index')) || 0);
                });
                allTiles.forEach(function(tile) {
                    if (cat === 'all' || tile.getAttribute('data-category') === cat) {
                        tile.style.display = '';
                    } else {
                        tile.style.display = 'none';
                    }
                    wrapper.appendChild(tile);
                });
                // Sort by order count after switching group
                sortDrinkTiles();
            }
        });
    });
    // Optionally, trigger click on 'All' to initialize
    var allBtn = document.querySelector('.category-btn[data-category="all"]');
    if (allBtn) allBtn.click();
});
