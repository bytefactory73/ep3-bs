// Drinks selected in the order tiles, as form fields drink_counts[<id>]
function getDrinkOrderData() {
    var data = {};
    document.querySelectorAll('input[id^="input-"]').forEach(function(input) {
        var count = parseInt(input.value, 10) || 0;
        if (count > 0) {
            data['drink_counts[' + input.id.replace('input-', '') + ']'] = count;
        }
    });
    return data;
}

// Theke: the "angemeldet bleiben" checkbox as '1' / '0'
function getKeepLoggedInValue() {
    var checkbox = document.getElementById('keep-logged-in-header');
    return (checkbox && checkbox.checked) ? '1' : '0';
}

// Theke: submit the order form synchronously, so it is saved before the page is left
function submitSimpleOrderSync(fields) {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'simple-order/submit-order', false);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    try {
        xhr.send(new URLSearchParams(fields).toString());
    } catch (e) {}
}

document.addEventListener('DOMContentLoaded', function() {
        // --- Simple order mode: auto-logout after 5 minutes of inactivity, persistent across sleep ---
        if (window.SIMPLE_ORDER_MODE) {
            var LOGOUT_DELAY = 5 * 60 * 1000; // 5 minutes
            var timerElem = document.getElementById('logout-timer');
            var logoutInterval = null;
            var deadlineKey = 'simpleOrderLogoutDeadline';

            function setLogoutDeadline() {
                var deadline = Date.now() + LOGOUT_DELAY;
                localStorage.setItem(deadlineKey, deadline);
                return deadline;
            }
            function getLogoutDeadline() {
                var val = localStorage.getItem(deadlineKey);
                return val ? parseInt(val, 10) : null;
            }

            function clearLogoutDeadline() {
                localStorage.removeItem(deadlineKey);
            }
            function updateTimerDisplay(remaining) {
                if (!timerElem) return;
                var min = Math.floor(remaining / 60);
                var sec = remaining % 60;
                timerElem.textContent = 'Logout in ' + min + ':' + (sec < 10 ? '0' : '') + sec;
            }
            function performLogout() {
                clearLogoutDeadline();
                // --- AUTO-ORDER SUBMISSION ON LOGOUT (simple mode) ---
                // Book the drinks still selected (auto order) and save the keep_logged_in state
                var data = getDrinkOrderData();
                if (Object.keys(data).length > 0) {
                    data.is_auto_order = '1';
                }
                data.keep_logged_in = getKeepLoggedInValue();
                submitSimpleOrderSync(data);
                if (typeof clearSelectionAndLogout === 'function') {
                    // Suppress confirmation dialog for auto-order logout
                    // Mark keep_logged_in as already updated to skip duplicate XHR
                    window.__KEEP_LOGGED_IN_UPDATED__ = true;
                    window.__AUTO_ORDER_LOGOUT__ = true;
                    clearSelectionAndLogout(true);
                    window.__AUTO_ORDER_LOGOUT__ = false;
                    window.__KEEP_LOGGED_IN_UPDATED__ = false;
                } else {
                    window.location.replace(DRINKS_PAGE.urls.simpleLogin);
                }
            }
            function startLogoutTimer() {
                if (logoutInterval) clearInterval(logoutInterval);
                var deadline = getLogoutDeadline();
                if (!deadline) deadline = setLogoutDeadline();
                function tick() {
                    var now = Date.now();
                    var remainingMs = deadline - now;
                    if (remainingMs <= 0) {
                        performLogout();
                        return;
                    }
                    var remaining = Math.ceil(remainingMs / 1000);
                    updateTimerDisplay(remaining);
                }
                tick();
                logoutInterval = setInterval(function() {
                    var deadline = getLogoutDeadline();
                    var now = Date.now();
                    var remainingMs = deadline - now;
                    if (remainingMs <= 0) {
                        clearInterval(logoutInterval);
                        performLogout();
                    } else {
                        var remaining = Math.ceil(remainingMs / 1000);
                        updateTimerDisplay(remaining);
                    }
                }, 1000);
            }
            function resetLogoutTimer() {
                setLogoutDeadline();
                startLogoutTimer();
            }
            // On any user interaction, reset the timer and deadline
            ['click', 'keydown', 'touchstart'].forEach(function(evt) {
                document.addEventListener(evt, function() {
                    resetLogoutTimer();
                }, true);
            });
            // On page load/resume, check deadline
            window.addEventListener('focus', function() {
                var deadline = getLogoutDeadline();
                if (deadline && Date.now() > deadline) {
                    performLogout();
                } else {
                    startLogoutTimer();
                }
            });
            if (timerElem) {
                timerElem.style.display = '';
                timerElem.addEventListener('click', function(e) {
                    clearLogoutDeadline();
                    clearSelectionAndLogout(true);
                });
            }
            // Initial check
            var deadline = getLogoutDeadline();
            if (deadline && Date.now() > deadline) {
                performLogout();
            } else {
                startLogoutTimer();
            }
        }
    });
