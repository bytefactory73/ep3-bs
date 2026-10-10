    // --- AJAX drink order submission ---
    function showOrderFeedback(msg, isError) {
        var box = document.getElementById('drink-order-feedback');
        box.textContent = msg;
        box.style.display = '';
        box.style.color = isError ? '#d32f2f' : '#2196f3';
        setTimeout(function() { box.style.display = 'none'; }, 4000);
    }
    document.addEventListener('DOMContentLoaded', function() {
        var form = document.getElementById('drink-order-form');
        var submitBtn = document.querySelector('button[type="submit"][form="drink-order-form"]');
        var spieltagCreateModal = document.getElementById('spieltag-create-modal');
        var spieltagCreateCloseBtn = document.getElementById('spieltag-create-close-btn');
        var spieltagCreateCancelBtn = document.getElementById('spieltag-create-cancel');
        var spieltagCreateConfirmBtn = document.getElementById('spieltag-create-confirm');
        var spieltagNewNameInput = document.getElementById('spieltag-new-name');
        var spieltagIsMedenspielCheckbox = document.getElementById('spieltag-is-medenspiel');
        var spieltagMembersRows = document.getElementById('spieltag-members-rows');
        var spieltagCreateError = document.getElementById('spieltag-create-error');
        if (form && submitBtn) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                if (submitBtn.disabled) return;
                // In simple order mode for team accounts: require a valid Spieltag BEFORE showing order overview
                if (window.SIMPLE_ORDER_MODE && spieltagSelect) {
                    var currentSpieltag = spieltagSelect.value || '';
                    if (!currentSpieltag || currentSpieltag === '__new__') {
                        if (typeof openSpieltagCreateModal === 'function') {
                            openSpieltagCreateModal();
                        }
                        return;
                    }
                }
                if (typeof confirmDrinkOrder === 'function' && !confirmDrinkOrder()) return;
                var data = getDrinkOrderData();
                if (Object.keys(data).length === 0) {
                    showOrderFeedback('Bitte mindestens ein Getränk auswählen.', true);
                    return;
                }
                submitBtn.disabled = true;
                submitBtn.className += ' loading';
                var url = window.SIMPLE_ORDER_MODE ? 'simple-order/submit-order' : 'bookings/submit-order';
                if (window.SIMPLE_ORDER_MODE && document.getElementById('keep-logged-in-header')) {
                    data.keep_logged_in = getKeepLoggedInValue();
                }
                var xhr = new XMLHttpRequest();
                xhr.open('POST', url, true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                xhr.onreadystatechange = function() {
                    if (xhr.readyState === 4) {
                        try {
                            var res = JSON.parse(xhr.responseText);
                        } catch (e) { res = null; }
                        if (res && res.success) {
                            if (!DRINKS_PAGE.simpleOrderMode) {
                                alert('Bestellung erfolgreich!');
                            }
                            if (window.SIMPLE_ORDER_MODE) {
                                window.location.replace(DRINKS_PAGE.urls.simpleLogin);
                            } else {
                                window.location.reload();
                            }
                        } else {
                            var msg = res && res.error ? res.error : 'Bestellung fehlgeschlagen.';
                            if (window.SIMPLE_ORDER_MODE) {
                                alert(msg);
                            }
                            showOrderFeedback(msg, true);
                            submitBtn.disabled = false;
                            submitBtn.className = submitBtn.className.replace(/\bloading\b/, '');
                        }
                    }
                };
                xhr.onerror = function() {
                    showOrderFeedback('Bestellung fehlgeschlagen.', true);
                    submitBtn.disabled = false;
                    submitBtn.className = submitBtn.className.replace(/\bloading\b/, '');
                };
                xhr.send(new URLSearchParams(data).toString());
            });
        }
        // Drink order tile logic
        var clearBtn = document.getElementById('clear-drink-selection');
        if (clearBtn) {
            clearBtn.addEventListener('click', function() { clearSelectionAndLogout(false); });
        }
            var spieltagSelect = document.getElementById('spieltag-select');
            var spieltagLastRealValue = '';
        if (spieltagSelect) {
            function getDefaultSpieltagName() {
                var today = new Date();
                var year = today.getFullYear();
                var month = String(today.getMonth() + 1).padStart(2, '0');
                var day = String(today.getDate()).padStart(2, '0');
                return 'Spieltag ' + year + '-' + month + '-' + day;
            }

            function getSpieltagSelectedMemberIds() {
                if (!spieltagMembersRows) return [];
                var rows = spieltagMembersRows.querySelectorAll('.spieltag-member-row');
                var result = [];
                rows.forEach(function(row) {
                    var uid = parseInt(row.getAttribute('data-user-id') || '0', 10);
                    if (uid > 0 && result.indexOf(uid) === -1) {
                        result.push(uid);
                    }
                });
                return result;
            }

            function ensureTrailingEmptySpieltagMemberRow() {
                if (!spieltagMembersRows) return;
                var rows = spieltagMembersRows.querySelectorAll('.spieltag-member-row');
                var hasEmpty = false;
                rows.forEach(function(row) {
                    var uid = parseInt(row.getAttribute('data-user-id') || '0', 10);
                    if (!uid) hasEmpty = true;
                });
                if (!hasEmpty) {
                    createSpieltagMemberRow(null);
                }
            }

            function createSpieltagMemberRow(initialRecipient) {
                if (!spieltagMembersRows) return;

                var row = document.createElement('div');
                row.className = 'spieltag-member-row';
                row.setAttribute('data-user-id', initialRecipient && initialRecipient.uid ? String(initialRecipient.uid) : '');
                row.style.cssText = 'display:flex; align-items:center; gap:6px; position:relative;';

                var input = document.createElement('input');
                input.type = 'text';
                input.autocomplete = 'off';
                input.placeholder = 'Teilnehmer suchen...';
                input.style.cssText = 'flex:1; min-width:0; padding:8px 12px; border:1px solid #ccc; border-radius:12px; font-size:14px; min-height:36px;';

                var clearBtn = document.createElement('button');
                clearBtn.type = 'button';
                clearBtn.title = 'Feld leeren';
                clearBtn.textContent = '×';
                clearBtn.style.cssText = 'background:none; border:none; padding:6px; font-size:20px; color:#aaa; cursor:pointer; line-height:1; min-height:36px; display:flex; align-items:center; justify-content:center; min-width:36px;';

                var removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.title = 'Zeile entfernen';
                removeBtn.textContent = '−';
                removeBtn.style.cssText = 'background:none; border:none; padding:6px; font-size:22px; color:#666; cursor:pointer; line-height:1; min-height:36px; display:flex; align-items:center; justify-content:center; min-width:36px;';

                var list = document.createElement('div');
                list.style.cssText = 'position:absolute; top:100%; left:0; right:78px; z-index:20; margin-top:4px;';

                function setRecipient(recipient) {
                    if (!recipient || !recipient.uid) return;
                    var selectedIds = getSpieltagSelectedMemberIds();
                    var currentUid = parseInt(row.getAttribute('data-user-id') || '0', 10);
                    if (selectedIds.indexOf(recipient.uid) !== -1 && currentUid !== recipient.uid) {
                        if (spieltagCreateError) spieltagCreateError.textContent = 'Dieser Teilnehmer wurde bereits hinzugefügt.';
                        return;
                    }
                    row.setAttribute('data-user-id', String(recipient.uid));
                    input.value = String(recipient.name || '') + ' (' + String(recipient.email || '') + ')';
                    rowAutocomplete.clear();
                    if (spieltagCreateError) spieltagCreateError.textContent = '';
                    ensureTrailingEmptySpieltagMemberRow();
                }

                var rowAutocomplete = setupBookingRecipientAutocomplete({
                    input: input,
                    list: list,
                    getMatches: function(filter) {
                        var selectedIds = getSpieltagSelectedMemberIds();
                        var currentUid = parseInt(row.getAttribute('data-user-id') || '0', 10);
                        return getBookingRecipientMatches(filter, {
                            excludeUserIds: selectedIds,
                            allowUserId: currentUid
                        });
                    },
                    onInputReset: function() {
                        row.setAttribute('data-user-id', '');
                    },
                    onSelect: setRecipient
                });

                clearBtn.addEventListener('click', function() {
                    row.setAttribute('data-user-id', '');
                    input.value = '';
                    rowAutocomplete.clear();
                    input.focus();
                });

                removeBtn.addEventListener('click', function() {
                    row.remove();
                    ensureTrailingEmptySpieltagMemberRow();
                });

                row.appendChild(input);
                row.appendChild(clearBtn);
                row.appendChild(removeBtn);
                row.appendChild(list);
                spieltagMembersRows.appendChild(row);

                if (initialRecipient && initialRecipient.uid) {
                    setRecipient(initialRecipient);
                }
            }

            function resetSpieltagCreateModal() {
                if (spieltagNewNameInput) {
                    spieltagNewNameInput.value = getDefaultSpieltagName();
                }
                if (spieltagCreateError) {
                    spieltagCreateError.textContent = '';
                }
                if (spieltagMembersRows) {
                    spieltagMembersRows.innerHTML = '';
                    createSpieltagMemberRow(null);
                }
            }

            function closeSpieltagCreateModal() {
                if (spieltagCreateModal) {
                    spieltagCreateModal.style.display = 'none';
                }
            }

            function openSpieltagCreateModal() {
                if (!spieltagCreateModal) return;
                resetSpieltagCreateModal();
                spieltagCreateModal.style.display = 'flex';
                if (spieltagNewNameInput) {
                    spieltagNewNameInput.focus();
                    spieltagNewNameInput.select();
                }
            }

            if (spieltagCreateCloseBtn) {
                spieltagCreateCloseBtn.addEventListener('click', closeSpieltagCreateModal);
            }
            if (spieltagCreateCancelBtn) {
                spieltagCreateCancelBtn.addEventListener('click', closeSpieltagCreateModal);
            }
            if (spieltagCreateModal) {
                spieltagCreateModal.addEventListener('mousedown', function(e) {
                    if (e.target === spieltagCreateModal) {
                        closeSpieltagCreateModal();
                    }
                });
            }
            if (spieltagCreateConfirmBtn) {
                spieltagCreateConfirmBtn.addEventListener('click', function() {
                    var newName = spieltagNewNameInput ? String(spieltagNewNameInput.value || '').trim() : '';
                    if (!newName) {
                        if (spieltagCreateError) spieltagCreateError.textContent = 'Bitte Spieltag-Namen eingeben.';
                        return;
                    }

                    var memberIds = getSpieltagSelectedMemberIds();
                    var paramsNew = 'spieltag=' + encodeURIComponent('__new__')
                        + '&new_spieltag=' + encodeURIComponent(newName)
                        + '&member_user_ids=' + encodeURIComponent(memberIds.join(','))
                        + '&is_medenspiel=' + encodeURIComponent(spieltagIsMedenspielCheckbox && spieltagIsMedenspielCheckbox.checked ? '1' : '0');

                    if (spieltagCreateError) spieltagCreateError.textContent = '';
                    spieltagCreateConfirmBtn.disabled = true;

                    fetch('simple-order/spieltag', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: paramsNew
                    }).then(function(resp) { return resp.json(); }).then(function(data) {
                        if (!data || !data.success) {
                            if (spieltagCreateError) {
                                spieltagCreateError.textContent = (data && data.error) ? data.error : 'Fehler beim Speichern des Spieltags.';
                            }
                            return;
                        }
                        if (typeof window.teamSpieltagUpdateFromApi === 'function') {
                            window.teamSpieltagUpdateFromApi(data);
                        }
                        closeSpieltagCreateModal();
                        // Auto-submit the booking form with the new Spieltag
                        if (spieltagSelect) {
                            spieltagSelect.value = newName;
                        }
                        if (form) {
                            form.dispatchEvent(new Event('submit'));
                        }
                    }).catch(function() {
                        if (spieltagCreateError) {
                            spieltagCreateError.textContent = 'Netzwerkfehler beim Speichern des Spieltags.';
                        }
                    }).finally(function() {
                        spieltagCreateConfirmBtn.disabled = false;
                    });
                });
            }

            spieltagSelect.addEventListener('change', function() {
                var selected = spieltagSelect.value || '';
                if (selected === '__new__') {
                    openSpieltagCreateModal();
                    if (spieltagLastRealValue) {
                        spieltagSelect.value = spieltagLastRealValue;
                    }
                    return;
                }

                spieltagLastRealValue = selected;

                var params = 'spieltag=' + encodeURIComponent(selected);
                fetch('simple-order/spieltag', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: params
                }).then(function(resp) { return resp.json(); }).then(function(data) {
                    if (!data || !data.success) {
                        alert((data && data.error) ? data.error : 'Fehler beim Setzen des Spieltags.');
                        return;
                    }
                    if (typeof window.teamSpieltagUpdateFromApi === 'function') {
                        window.teamSpieltagUpdateFromApi(data);
                    }
                    window.location.reload();
                }).catch(function() {
                    alert('Netzwerkfehler beim Setzen des Spieltags.');
                });
            });

            fetch('simple-order/spieltag', {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function(resp) { return resp.json(); }).then(function(data) {
                if (!data || !data.success) return;
                if (typeof window.teamSpieltagUpdateFromApi === 'function') {
                    window.teamSpieltagUpdateFromApi(data);
                }
                if (spieltagSelect && spieltagSelect.value && spieltagSelect.value !== '__new__') {
                    spieltagLastRealValue = spieltagSelect.value;
                }
            }).catch(function() {});

            if (spieltagSelect.value && spieltagSelect.value !== '__new__') {
                spieltagLastRealValue = spieltagSelect.value;
            }
        }
        var minusBtns = document.querySelectorAll('.drink-minus');
        for (var i = 0; i < minusBtns.length; i++) {
            minusBtns[i].addEventListener('click', function() {
                var id = this.getAttribute('data-drink-id');
                var input = document.getElementById('input-' + id);
                var badge = document.getElementById('badge-' + id);
                var val = parseInt(input.value, 10) || 0;
                if (val > 0) val--;
                input.value = val;
                badge.textContent = val;
                badge.style.visibility = val > 0 ? 'visible' : 'hidden';
                var minusBtn = this;
                minusBtn.style.visibility = val > 0 ? 'visible' : 'hidden';
                updateDrinkOrderTotal();
            });
        }
        var tiles = document.querySelectorAll('.drink-tile');
        for (var i = 0; i < tiles.length; i++) {
            tiles[i].addEventListener('click', function(e) {
                var minusBtn = this.querySelector('.drink-minus');
                if (minusBtn && e.target === minusBtn) return;
                var id = this.getAttribute('data-drink-id');
                if (!id) return;
                var input = document.getElementById('input-' + id);
                var badge = document.getElementById('badge-' + id);
                if (!input || !badge) return;
                var val = parseInt(input.value, 10) || 0;
                val++;
                input.value = val;
                badge.textContent = val;
                badge.style.visibility = val > 0 ? 'visible' : 'hidden';
                if (minusBtn) minusBtn.style.visibility = val > 0 ? 'visible' : 'hidden';
                updateDrinkOrderTotal();
            });
        }
        // Initialize minus button visibility on page load
        for (var i = 0; i < tiles.length; i++) {
            var input = tiles[i].querySelector('input[type="hidden"]');
            var minusBtn = tiles[i].querySelector('.drink-minus');
            var val = parseInt(input.value, 10) || 0;
            if (minusBtn) minusBtn.style.visibility = val > 0 ? 'visible' : 'hidden';
        }
        // Ensure booking button is disabled on initial load if no drinks selected
        updateDrinkOrderTotal();
    });
    // --- Dynamic sorting of drink tiles by count (JS) ---
    function sortDrinkTiles() {
        var wrapper = document.querySelector('.drink-tiles-wrapper');
        if (!wrapper) return;
        var tiles = [].slice.call(wrapper.querySelectorAll('.drink-tile'));
        tiles.sort(function(a, b) {
            var inputA = a.querySelector('input[type="hidden"]');
            var inputB = b.querySelector('input[type="hidden"]');
            var countA = parseInt(inputA.value, 10) || 0;
            var countB = parseInt(inputB.value, 10) || 0;
            return countB - countA;
        });
        for (var i = 0; i < tiles.length; i++) { wrapper.appendChild(tiles[i]); }
    }
    // Call after every count change
    function updateDrinkOrderTotal() {
        var total = 0;
        var anySelected = false;
        var inputs = document.querySelectorAll('input[id^="input-"]');
        for (var i = 0; i < inputs.length; i++) {
            var input = inputs[i];
            var count = parseInt(input.value, 10) || 0;
            var price = parseFloat(input.getAttribute('data-price')) || 0;
            total += count * price;
            if (count > 0) anySelected = true;
        }
        var formatted = total.toLocaleString('de-DE', { style: 'currency', currency: 'EUR' });
        var totalElem = document.getElementById('drink-order-total');
        totalElem.textContent = 'Buchen: ' + formatted + ' ';
        var bookBtn = document.querySelector('button[type="submit"][form="drink-order-form"]');
        if (bookBtn) bookBtn.disabled = !anySelected;
            var clearBtn = document.getElementById('clear-drink-selection');
            if (clearBtn) {
                if (anySelected) {
                    clearBtn.disabled = false;
                    clearBtn.setAttribute('aria-disabled', 'false');
                    clearBtn.style.opacity = '1';
                    clearBtn.style.cursor = 'pointer';
                } else {
                    clearBtn.disabled = true;
                    clearBtn.setAttribute('aria-disabled', 'true');
                    clearBtn.style.opacity = '0.45';
                    clearBtn.style.cursor = 'not-allowed';
                }
            }
        var initialBalance = DRINKS_PAGE.orderBalance;
        var pendingPaypalAmount = DRINKS_PAGE.pendingPaypalAmount;
        var newBalance = initialBalance - total;
        var availableBalance = newBalance + pendingPaypalAmount;
        var minimumAccountBalance = DRINKS_PAGE.minimumAccountBalance;
        var belowMinimum = minimumAccountBalance !== 0 ? (availableBalance < minimumAccountBalance) : (availableBalance < 0);
        var formattedBalance = "";
        if (window.SIMPLE_ORDER_MODE) {
            formattedBalance = newBalance > 0 ? " > 0 €" : " < 0 €";
        } else {
            formattedBalance = newBalance.toLocaleString('de-DE', { style: 'currency', currency: 'EUR' });
            var balanceElem = document.getElementById('current-balance-value');
            var balanceBox = document.getElementById('current-balance-display');
            if (balanceElem) {
                balanceElem.textContent = formattedBalance;
                balanceElem.style.color = belowMinimum ? '#d32f2f' : '#2196f3';
            }
            if (balanceBox) {
                if (belowMinimum) {
                    balanceBox.classList.add('negative-balance');
                } else {
                    balanceBox.classList.remove('negative-balance');
                }
            }
        }
        // Show/hide Guthaben aufladen button
        var rechargeBtnContainer = document.getElementById('balance-recharge-btn-container');
        if (rechargeBtnContainer) {
            if (newBalance < 0) {
                rechargeBtnContainer.style.display = 'block';
            } else {
                rechargeBtnContainer.style.display = 'none';
            }
            var rechargeLabel = document.getElementById('balance-recharge-ribbon-label');
            var rechargeSubtext = document.getElementById('balance-recharge-ribbon-subtext');
            if (rechargeLabel) {
                rechargeLabel.textContent = belowMinimum ? 'Keine Buchung möglich' : 'Guthaben aufladen';
            }
            if (rechargeSubtext) {
                rechargeSubtext.textContent = belowMinimum ? 'Bitte zuerst Guthaben aufladen' : 'Du kannst die Bestellung trotzdem abschließen';
            }
        }
        if (bookBtn && minimumAccountBalance !== 0) {
            bookBtn.disabled = !anySelected || belowMinimum;
        }
    }
    // --- Shared clear selection and logout logic (global scope) ---
function clearSelectionAndLogout(logoutUser) {
    var hasOrder = false;
    var inputs = document.querySelectorAll('input[id^="input-"]');
    for (var i = 0; i < inputs.length; i++) {
        if (parseInt(inputs[i].value, 10) > 0) hasOrder = true;
    }
    // Suppress confirmation dialog if auto-order logout is in progress
    if (hasOrder && !window.__AUTO_ORDER_LOGOUT__) {
        if (!confirm('Bist Du sicher, dass Du Deine Getränkeauswahl löschen möchtest? Dadurch wird Deine aktuelle Bestellung zurückgesetzt.')) {
            return;
        }
    }
    for (var i = 0; i < inputs.length; i++) {
        inputs[i].value = 0;
        var id = inputs[i].id.replace('input-', '');
        var badge = document.getElementById('badge-' + id);
        badge.textContent = 0;
        badge.style.visibility = 'hidden';
        // FIX: Hide minus button for each tile correctly
        var inputElem = document.getElementById('input-' + id);
        if (inputElem) {
            var tileElem = inputElem.closest('.drink-tile');
            if (tileElem) {
                var minusBtn = tileElem.querySelector('.drink-minus');
                if (minusBtn) minusBtn.style.visibility = 'hidden';
            }
        }
    }
    if (typeof updateDrinkOrderTotal === 'function') updateDrinkOrderTotal();
    // Ensure clear button disabled after clearing
    var clearBtn = document.getElementById('clear-drink-selection');
    if (clearBtn) {
        clearBtn.disabled = true;
        clearBtn.setAttribute('aria-disabled', 'true');
        clearBtn.style.opacity = '0.45';
        clearBtn.style.cursor = 'not-allowed';
    }
    if (logoutUser) {
        if (window.SIMPLE_ORDER_MODE && !window.__KEEP_LOGGED_IN_UPDATED__) {
            // Save the keep_logged_in state before leaving the page
            submitSimpleOrderSync({ keep_logged_in: getKeepLoggedInValue() });
        }
        window.location.replace(DRINKS_PAGE.urls.simpleLogin);
    }
}
    function confirmDrinkOrder() {
        var items = [];
        var total = 0;
        var inputs = document.querySelectorAll('input[id^="input-"]');
        for (var i = 0; i < inputs.length; i++) {
            var input = inputs[i];
            var count = parseInt(input.value, 10) || 0;
            if (count > 0) {
                var id = input.id.replace('input-', '');
                var tile = input.closest('.drink-tile');
                var nameDiv = tile ? tile.querySelector('.drink-name') : null;
                var name = nameDiv ? nameDiv.textContent.replace(/^\s+|\s+$/g, '') : id;
                var price = parseFloat(input.getAttribute('data-price')) || 0;
                var itemTotal = count * price;
                total += itemTotal;
                if (count > 1)
                    items.push(count + ' x ' + name + ' á ' + price.toLocaleString('de-DE', { style: 'currency', currency: 'EUR' }) + ' = ' + itemTotal.toLocaleString('de-DE', { style: 'currency', currency: 'EUR' }));
                else
                    items.push(count + ' x ' + name + ' = ' + itemTotal.toLocaleString('de-DE', { style: 'currency', currency: 'EUR' }));
            }
        }
        if (items.length === 0) {
            return confirm('Keine Getränke ausgewählt');
        }
        var msg = 'Buchung bestätigen:\n\n' + items.join('\n') + '\n---------------------\nGesamt: ' + total.toLocaleString('de-DE', { style: 'currency', currency: 'EUR' });
        return confirm(msg);
    }
