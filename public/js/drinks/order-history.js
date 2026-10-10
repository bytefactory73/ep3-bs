document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.order-day-header').forEach(function(header) {
        header.addEventListener('click', function(e) {
            if (!e.target.classList.contains('collapse-toggle')) {
                var group = this.getAttribute('data-group');
                var rows = document.querySelectorAll('.order-day-row[data-group="' + group + '"]');
                var toggle = this.querySelector('.collapse-toggle');
                var isOpen = rows.length > 0 && rows[0].style.display !== 'none';
                rows.forEach(function(row) {
                    row.style.display = isOpen ? 'none' : '';
                });
                if (toggle) toggle.textContent = isOpen ? '+' : '–';
            }
        });
    });
    document.querySelectorAll('.collapse-toggle').forEach(function(toggle) {
        toggle.addEventListener('click', function(e) {
            e.stopPropagation();
            var group = this.getAttribute('data-group');
            var rows = document.querySelectorAll('.order-day-row[data-group="' + group + '"]');
            var isOpen = rows.length > 0 && rows[0].style.display !== 'none';
            rows.forEach(function(row) {
                row.style.display = isOpen ? 'none' : '';
            });
            this.textContent = isOpen ? '+' : '–';
        });
    });
    // Cancel order (X button)
    document.querySelectorAll('.order-cancel-btn').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            var orderId = this.getAttribute('data-order-id');
            if (!orderId) return;
            if (!confirm('Bestellung wirklich stornieren?')) return;
            var button = this;
            button.disabled = true;
            var dropOrderUrl = (window.SIMPLE_ORDER_MODE ? 'simple-order/drop-order' : 'bookings/drop-order');
            fetch(dropOrderUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: 'order_id=' + encodeURIComponent(orderId),
                credentials: 'same-origin'
            })
            .then(function(response) {
                return response.text().then(function(text) {
                    var data = null;
                    try { data = JSON.parse(text); } catch (e) {}
                    return { data: data, raw: text };
                });
            })
            .then(function(result) {
                var data = result.data;
                var raw = result.raw;
                if (data && data.success) {
                    alert('Stornierung erfolgreich.');
                    var row = button.closest('tr');
                    if (row) {
                        var group = row.getAttribute('data-group');
                        row.parentNode.removeChild(row);
                        var groupRows = document.querySelectorAll('.order-day-row[data-group="' + group + '"]');
                        if (groupRows.length === 0) {
                            var header = document.querySelector('.order-day-header[data-group="' + group + '"]');
                            if (header) header.parentNode.removeChild(header);
                        }
                    }
                } else {
                    var msg = 'Stornierung fehlgeschlagen.';
                    if (data && data.error_message) {
                        msg += '\n' + data.error_message;
                    } else if (!data && raw) {
                        msg += '\n' + raw;
                    }
                    alert(msg);
                    button.disabled = false;
                }
            })
            .catch(function(err) {
                var msg = 'Stornierung fehlgeschlagen.';
                if (err && err.message) {
                    msg += '\n' + err.message;
                } else if (err) {
                    msg += '\n' + String(err);
                }
                alert(msg);
                button.disabled = false;
            });
        });
    });
});
