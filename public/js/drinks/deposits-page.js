/**
 * Einzahlungen (admin): deposits, bookings and settings of a user; data from deposits.phtml
 * via window.DEPOSITS_PAGE = { urls, users, drinks, customPriceDrinkIds }.
 */
var PAGE = window.DEPOSITS_PAGE;

function escapeHtml(value) {
    return String(value == null ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

// "Sonstiges" and money transfers are priced per entry (DrinkManager::CUSTOM_PRICE_DRINK_IDS)
function isCustomPriceDrink(drinkId) {
    return PAGE.customPriceDrinkIds.indexOf(parseInt(drinkId, 10)) !== -1;
}

function formatCurrency(amount) {
    return amount != null ? amount.toLocaleString('de-DE', { style: 'currency', currency: 'EUR' }) : '';
}

document.addEventListener('DOMContentLoaded', function() {
    var clearUserSearchBtn = document.getElementById('clear-user-search-btn');
    var userSearch = document.getElementById('deposit_user_search');
    var userIdInput = document.getElementById('deposit_user_id');
    if (clearUserSearchBtn && userSearch && userIdInput) {
        clearUserSearchBtn.addEventListener('click', function() {
            userSearch.value = '';
            userIdInput.value = '';
            userSearch.focus();
            if (typeof updateDrinksFields === 'function') updateDrinksFields();
            if (typeof updateUserInfo === 'function') updateUserInfo();
        });
    }
    var users = PAGE.users;

    var drinks = PAGE.drinks;
    var userSearch = document.getElementById('deposit_user_search');
    var userIdInput = document.getElementById('deposit_user_id');
    var userSearchList = document.getElementById('user-search-list');
    var drinksEnabledCheckbox = document.getElementById('deposit_drinks_enabled');
    var orderEmailOptionSelect = document.getElementById('deposit_order_email_option');
    var isTeamCheckbox = document.getElementById('deposit_is_team');
    var teamSpieltagRow = document.getElementById('team-spieltag-row');
    var teamSpieltagSelect = document.getElementById('deposit_teamevent_id');
    var teamNewSpieltagInput = document.getElementById('deposit_new_spieltag');
    var pendingNewSpieltagName = '';
    var currentUserContextUid = '';
    var selectedTeamEventIdInContext = '';
    var aliasRow = document.getElementById('alias-row');
    var aliasInput = document.getElementById('deposit_alias');
    var teamleadRowsContainer = document.getElementById('teamlead-rows');
    var teamleadRow = document.getElementById('teamlead-row');
    var teamleadLabel = document.getElementById('teamlead-label');
    var balanceBox = document.getElementById('current-balance-box');
    var historySection = document.getElementById('user-history-section');
    // Multi-drink selection logic
    var drinkRowsContainer = document.getElementById('drink-rows');
    var addDrinkRowBtn = document.getElementById('add-drink-row-btn');
    var addDrinksBtn = document.getElementById('add-drinks-btn');
    var multiDrinkTotalPrice = document.getElementById('multi-drink-total-price');
    var drinkRowId = 0;
    var tabButtons = {
        options: document.getElementById('deposit-tab-options'),
        einzahlung: document.getElementById('deposit-tab-einzahlung'),
        buchungen: document.getElementById('deposit-tab-buchungen'),
        spieltage: document.getElementById('deposit-tab-spieltage')
    };
    var tabPanels = {
        options: document.getElementById('tab-panel-options'),
        einzahlung: document.getElementById('tab-panel-einzahlung'),
        buchungen: document.getElementById('tab-panel-buchungen'),
        spieltage: document.getElementById('tab-panel-spieltage')
    };
    var teamEventsList = document.getElementById('team-events-list');
    var depositsTeamStatsModalController = null;
    var currentTeamEventsForPanel = [];
    var currentSelectedTeamEventIdForPanel = '';
    var historyTeamEventEditState = { entryId: '', entryType: '', currentTeamEventId: '' };
    var depositTabStorageKey = 'deposits_active_tab';
    var activeDepositTab = 'buchungen';
    var historyTeamEventEditModal = document.getElementById('depositsHistoryTeamEventEditModal');
    var historyTeamEventEditTitle = document.getElementById('deposits-history-team-event-edit-title');
    var historyTeamEventEditSelect = document.getElementById('deposits-history-team-event-edit-select');
    var historyTeamEventEditMessage = document.getElementById('deposits-history-team-event-edit-message');
    var historyTeamEventEditSaveBtn = document.getElementById('deposits-history-team-event-edit-save');
    var historyTeamEventEditCancelBtn = document.getElementById('deposits-history-team-event-edit-cancel');
    var historyTeamEventEditCloseXBtn = document.getElementById('deposits-history-team-event-edit-close-x');

    function updateTeamSpieltagVisibility() {
        if (!teamSpieltagRow) return;
        var hasUser = !!(userIdInput && String(userIdInput.value || '').trim() !== '');
        var isTeam = !!(isTeamCheckbox && isTeamCheckbox.checked);
        var show = hasUser && isTeam && (activeDepositTab === 'buchungen' || activeDepositTab === 'einzahlung');
        teamSpieltagRow.style.display = show ? '' : 'none';

        if (!show && teamNewSpieltagInput) {
            teamNewSpieltagInput.value = '';
        }
    }

    function setActiveDepositTab(tabName) {
        if (!tabPanels[tabName] || !tabButtons[tabName]) {
            return;
        }
        activeDepositTab = tabName;
        if (typeof localStorage !== 'undefined') {
            localStorage.setItem(depositTabStorageKey, tabName);
        }

        ['einzahlung', 'buchungen', 'options', 'spieltage'].forEach(function(name) {
            var panel = tabPanels[name];
            var button = tabButtons[name];
            if (panel) {
                if (name === tabName) {
                    panel.style.display = (name === 'buchungen' || name === 'spieltage') ? 'block' : 'grid';
                } else {
                    panel.style.display = 'none';
                }
            }
            if (button) {
                if (name === tabName) {
                    button.style.background = '#1769aa';
                    button.style.color = '#fff';
                } else {
                    button.style.background = '#fff';
                    button.style.color = '#1769aa';
                }
            }
        });

        updateTeamSpieltagVisibility();
    }

    if (tabButtons.options) {
        tabButtons.options.addEventListener('click', function() { setActiveDepositTab('options'); });
    }
    if (tabButtons.einzahlung) {
        tabButtons.einzahlung.addEventListener('click', function() { setActiveDepositTab('einzahlung'); });
    }
    if (tabButtons.buchungen) {
        tabButtons.buchungen.addEventListener('click', function() { setActiveDepositTab('buchungen'); });
    }
    if (tabButtons.spieltage) {
        tabButtons.spieltage.addEventListener('click', function() { setActiveDepositTab('spieltage'); });
    }
    var initialDepositTab = 'einzahlung';
    if (typeof localStorage !== 'undefined') {
        var storedDepositTab = localStorage.getItem(depositTabStorageKey);
        if (storedDepositTab && tabPanels[storedDepositTab] && tabButtons[storedDepositTab]) {
            initialDepositTab = storedDepositTab;
        }
    }
    setActiveDepositTab(initialDepositTab);

    depositsTeamStatsModalController = createAdminTeamStatsModal('deposits-team-stats');

    function clearActiveTeamEventContext() {
        pendingNewSpieltagName = '';
        selectedTeamEventIdInContext = '';
        if (teamNewSpieltagInput) teamNewSpieltagInput.value = '';
        if (teamSpieltagSelect) {
            teamSpieltagSelect.innerHTML = '';
            teamSpieltagSelect.setAttribute('data-last-real-value', '');
        }
    }

    function switchUserContext(uid) {
        var nextUid = String(uid || '').trim();
        if (currentUserContextUid !== nextUid) {
            clearActiveTeamEventContext();
            currentUserContextUid = nextUid;
        }
    }

    function getDefaultSpieltagName() {
        var today = new Date();
        var year = today.getFullYear();
        var month = String(today.getMonth() + 1).padStart(2, '0');
        var day = String(today.getDate()).padStart(2, '0');
        return 'Spieltag ' + year + '-' + month + '-' + day;
    }

    function fillTeamSpieltagOptions(events, selectedId) {
        if (!teamSpieltagSelect) return;
        teamSpieltagSelect.innerHTML = '';
        pendingNewSpieltagName = '';
        if (teamNewSpieltagInput) teamNewSpieltagInput.value = '';

        var hasAny = Array.isArray(events) && events.length > 0;
        if (hasAny) {
            events.forEach(function(evt) {
                var id = evt && evt.id ? String(evt.id) : '';
                var label = evt && evt.label ? String(evt.label) : '';
                if (!id || !label) return;
                var displayLabel = label;
                if (evt && evt.closed) {
                    displayLabel += ' (abgeschlossen)';
                } else {
                    displayLabel += ' (offen)';
                }
                if (typeof evt.balance !== 'undefined' && evt.balance !== null) {
                    displayLabel += ' | ' + formatCurrency(evt.balance);
                }
                var option = document.createElement('option');
                option.value = id;
                option.textContent = displayLabel;
                if (String(selectedId || '') === id) option.selected = true;
                teamSpieltagSelect.appendChild(option);
            });
        }

        var newOption = document.createElement('option');
        newOption.value = '__new__';
        newOption.textContent = 'Neuen Spieltag anlegen';
        teamSpieltagSelect.appendChild(newOption);

        if (!hasAny) {
            teamSpieltagSelect.value = '__new__';
        } else if (selectedId && !teamSpieltagSelect.value) {
            teamSpieltagSelect.value = String(selectedId);
        } else if (!selectedId && hasAny) {
            teamSpieltagSelect.value = String(events[0].id || '');
        }
        teamSpieltagSelect.setAttribute('data-last-real-value', teamSpieltagSelect.value !== '__new__' ? teamSpieltagSelect.value : '');
    }

    function promptForNewTeamSpieltag() {
        var newName = window.prompt('Neuen Spieltag eingeben:', getDefaultSpieltagName());
        if (!newName) {
            return null;
        }
        newName = newName.trim();
        if (!newName) {
            return null;
        }
        pendingNewSpieltagName = newName;
        if (teamNewSpieltagInput) teamNewSpieltagInput.value = newName;
        return newName;
    }

    /**
     * Create (or find) the Spieltag "label" of a team account; resolves to { id, label }.
     */
    async function requestCreateTeamEvent(uid, label) {
        var resp = await fetch(PAGE.urls['user/create-team-event'], {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: new URLSearchParams({ uid: uid, label: label })
        });
        var data = await resp.json();
        var event = { id: String((data && data.team_event_id) || ''), label: String((data && data.label) || label).trim() };
        if (!resp.ok || !data || !data.success || !event.id || !event.label) {
            throw new Error((data && data.error) ? data.error : 'Spieltag konnte nicht angelegt werden.');
        }
        return event;
    }

    async function createAndSelectTeamSpieltag(uid, label) {
        if (!uid || !label) return null;
        var created = await requestCreateTeamEvent(uid, label);
        var createdId = created.id;
        var createdLabel = created.label;
        if (!teamSpieltagSelect) {
            throw new Error('Spieltag konnte nicht angelegt werden.');
        }

        var existingOption = teamSpieltagSelect.querySelector('option[value="' + createdId.replace(/"/g, '\\"') + '"]');
        if (!existingOption) {
            existingOption = document.createElement('option');
            existingOption.value = createdId;
            existingOption.textContent = createdLabel;
            var newOption = teamSpieltagSelect.querySelector('option[value="__new__"]');
            teamSpieltagSelect.insertBefore(existingOption, newOption || null);
        } else {
            existingOption.textContent = createdLabel;
        }

        teamSpieltagSelect.value = createdId;
        teamSpieltagSelect.setAttribute('data-last-real-value', createdId);
        pendingNewSpieltagName = '';
        if (teamNewSpieltagInput) teamNewSpieltagInput.value = '';
        selectedTeamEventIdInContext = createdId;
        return { teamEventId: createdId, label: createdLabel };
    }

    function resolveTeamSpieltagSelection() {
        var isTeam = !!(isTeamCheckbox && isTeamCheckbox.checked);
        if (!isTeam || !teamSpieltagSelect) {
            if (teamNewSpieltagInput) teamNewSpieltagInput.value = '';
            pendingNewSpieltagName = '';
            return { teamEventId: '', newSpieltag: '' };
        }

        var value = teamSpieltagSelect.value || '';
        if (value === '__new__') {
            var newName = pendingNewSpieltagName || promptForNewTeamSpieltag();
            if (!newName) {
                return null;
            }
            if (teamNewSpieltagInput) teamNewSpieltagInput.value = newName;
            return { teamEventId: '', newSpieltag: newName };
        }

        pendingNewSpieltagName = '';
        if (teamNewSpieltagInput) teamNewSpieltagInput.value = '';
        teamSpieltagSelect.setAttribute('data-last-real-value', value);
        selectedTeamEventIdInContext = value;
        return { teamEventId: value, newSpieltag: '' };
    }

    if (teamSpieltagSelect) {
        teamSpieltagSelect.addEventListener('change', async function() {
            var value = teamSpieltagSelect.value || '';
            if (value === '__new__') {
                var newName = promptForNewTeamSpieltag();
                if (!newName) {
                    var fallbackValue = teamSpieltagSelect.getAttribute('data-last-real-value') || '';
                    if (fallbackValue) {
                        teamSpieltagSelect.value = fallbackValue;
                    }
                    return;
                }
                try {
                    var uid = userIdInput.value;
                    var created = await createAndSelectTeamSpieltag(uid, newName);
                    if (!created) {
                        throw new Error('Spieltag konnte nicht angelegt werden.');
                    }
                } catch (e) {
                    alert((e && e.message) ? e.message : 'Spieltag konnte nicht angelegt werden.');
                    var fallbackValue = teamSpieltagSelect.getAttribute('data-last-real-value') || '';
                    if (fallbackValue) {
                        teamSpieltagSelect.value = fallbackValue;
                    }
                }
                return;
            }

            pendingNewSpieltagName = '';
            if (teamNewSpieltagInput) teamNewSpieltagInput.value = '';
            teamSpieltagSelect.setAttribute('data-last-real-value', value);
            selectedTeamEventIdInContext = value;
        });
    }

    function createDrinkRow(selectedId, countValue, commentValue) {
        var row = document.createElement('div');
        row.style.display = 'flex';
        row.style.alignItems = 'center';
        row.style.gap = '10px';
        row.style.marginBottom = '2px';
        row.setAttribute('data-row-id', ++drinkRowId);

        var select = document.createElement('select');
        select.style.width = '220px';
        var opt = document.createElement('option');
        opt.value = '';
        opt.textContent = 'Bitte wählen...';
        select.appendChild(opt);
        if (!Array.isArray(drinks) || drinks.length === 0) {
            var emptyOpt = document.createElement('option');
            emptyOpt.value = '';
            emptyOpt.textContent = '[Keine Getränke verfügbar]';
            emptyOpt.disabled = true;
            select.appendChild(emptyOpt);
        } else {
            drinks.forEach(function(drink) {
                var option = document.createElement('option');
                option.value = drink.id;
                option.textContent = drink.name + ' (' + formatCurrency(drink.price) + ')';
                option.setAttribute('data-price', drink.price);
                select.appendChild(option);
            });
        }
        if (selectedId) select.value = selectedId;

        var input = document.createElement('input');
        input.type = 'number';
        input.min = '1';
        input.value = countValue || 1;
        input.style.width = '80px';

        // Custom price articles ("Sonstiges"): the input takes an EUR amount
        function updateInputType() {
            if (isCustomPriceDrink(select.value)) {
                input.type = 'text';
                input.placeholder = 'EUR';
                input.min = '';
                input.step = '0.01';
                input.value = '';
                input.pattern = '^[0-9]+([.,][0-9]{1,2})?$';
                input.style.width = '80px';
            } else {
                input.type = 'number';
                input.min = '1';
                input.step = '';
                input.placeholder = '';
                if (!input.value) input.value = 1;
                input.pattern = '';
            }
        }
        select.addEventListener('change', updateInputType);
        // Initialize input type on row creation
        setTimeout(updateInputType, 0);

        // Add comment field for this drink row
        var commentInput = document.createElement('input');
        commentInput.type = 'text';
        commentInput.placeholder = 'Kommentar (optional)';
        commentInput.maxLength = 255;
        commentInput.style.width = '180px';
        if (commentValue) commentInput.value = commentValue;

        var removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.textContent = '×';
        removeBtn.className = 'mini-button';
        removeBtn.title = 'Entfernen';
        removeBtn.style.marginLeft = '4px';

        removeBtn.addEventListener('click', function() {
            row.remove();
            updateMultiDrinkTotal();
            ensureAtLeastOneRow();
        });

        select.addEventListener('change', function() {
            updateMultiDrinkTotal();
            // If this is the last row and a drink is selected, add a new row
            var rows = Array.from(drinkRowsContainer.children);
            if (rows[rows.length - 1] === row && select.value) {
                addDrinkRow();
            }
        });
        input.addEventListener('input', updateMultiDrinkTotal);

        row.appendChild(select);
        row.appendChild(input);
        row.appendChild(commentInput);
        row.appendChild(removeBtn);
        return row;
    }

    function addDrinkRow(selectedId, countValue, commentValue) {
        var row = createDrinkRow(selectedId, countValue, commentValue);
        drinkRowsContainer.appendChild(row);
        updateMultiDrinkTotal();
    }

    function ensureAtLeastOneRow() {
        if (drinkRowsContainer.children.length === 0) {
            addDrinkRow();
        }
    }

    function updateMultiDrinkTotal() {
        var total = 0;
        var rows = Array.from(drinkRowsContainer.children);
        rows.forEach(function(row) {
            var select = row.querySelector('select');
            var input = row.querySelector('input');
            var price = 0;
            var count = 1;
            if (select && isCustomPriceDrink(select.value)) {
                // Custom price: the amount is entered in the input
                price = input && input.value ? parseFloat(input.value.replace(',', '.')) : 0;
                count = 1;
            } else {
                price = select && select.selectedOptions[0] && select.selectedOptions[0].getAttribute('data-price') ? parseFloat(select.selectedOptions[0].getAttribute('data-price')) : 0;
                count = input ? parseInt(input.value, 10) || 1 : 1;
            }
            if (select && select.value) {
                total += price * count;
            }
        });
        multiDrinkTotalPrice.textContent = formatCurrency(total);
    }

    addDrinkRowBtn.addEventListener('click', function() {
        addDrinkRow();
    });

    // Always start with one row
    ensureAtLeastOneRow();

    addDrinksBtn.addEventListener('click', async function() {
        var uid = userIdInput.value;
        if (!uid) {
            alert('Bitte zuerst einen Benutzer auswählen.');
            return;
        }
        var teamSelection = resolveTeamSpieltagSelection();
        if (teamSelection === null) {
            return;
        }
        var rows = Array.from(drinkRowsContainer.children);
        var orders = [];
        var globalComment = document.getElementById('multi-drink-comment') ? document.getElementById('multi-drink-comment').value : '';
        for (var row of rows) {
            var select = row.querySelector('select');
            var input = row.querySelector('input');
            var commentInput = row.querySelectorAll('input[type="text"]');
            var drinkId = select.value;
            var localComment = '';
            if (commentInput.length > 0) {
                // The comment field is always the last input[type=text] in the row
                localComment = commentInput[commentInput.length - 1].value;
            }
            var comment = '';
            if (globalComment && localComment) {
                comment = globalComment + ' ' + localComment;
            } else if (globalComment) {
                comment = globalComment;
            } else {
                comment = localComment;
            }
            if (isCustomPriceDrink(drinkId)) {
                // EUR field: the amount goes into price, quantity is always 1
                var price = parseFloat(input.value.replace(',', '.')) || 0;
                if (drinkId && price > 0) {
                    orders.push({ drink_id: drinkId, count: 1, price: price, comment: comment });
                }
            } else {
                var count = parseInt(input.value, 10) || 1;
                if (drinkId && count > 0) {
                    orders.push({ drink_id: drinkId, count: count, comment: comment });
                }
            }
        }
        if (orders.length === 0) {
            alert('Bitte mindestens ein Getränk auswählen.');
            return;
        }
        addDrinksBtn.disabled = true;
        addDrinksBtn.textContent = '...';
        try {
            const resp = await fetch(PAGE.urls['user/add-drink-booking'], {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({
                    uid: uid,
                    orders: orders,
                    team_event_id: teamSelection.teamEventId,
                    new_spieltag: teamSelection.newSpieltag
                })
            });
            const data = await resp.json();
            if (!data.success) {
                alert('Fehler beim Buchen: ' + (data.error || 'Unbekannter Fehler'));
            } else {
                updateUserInfo();
                drinkRowsContainer.innerHTML = '';
                ensureAtLeastOneRow();
                var globalCommentInput = document.getElementById('multi-drink-comment');
                if (globalCommentInput) globalCommentInput.value = '';
                if (teamSelection.newSpieltag && teamNewSpieltagInput) {
                    teamNewSpieltagInput.value = '';
                }
            }
        } catch (e) {
            alert('Netzwerkfehler beim Buchen der Getränke.');
        } finally {
            addDrinksBtn.disabled = false;
            addDrinksBtn.textContent = 'Getränke buchen';
        }
    });

    var depositForm = document.getElementById('deposit-form');
    if (depositForm) {
        depositForm.addEventListener('submit', function(e) {
            var teamSelection = resolveTeamSpieltagSelection();
            if (teamSelection === null) {
                e.preventDefault();
                return;
            }
            if (teamSpieltagSelect) {
                if (teamSelection.teamEventId) {
                    teamSpieltagSelect.value = teamSelection.teamEventId;
                }
            }
        });
    }
    // Persist showStorno state in localStorage
    var showStorno = localStorage.getItem('deposits_show_storno') === '1';

    // Helper: find user by uid
    /**
     * Save drink account settings of a user (only the given fields); alerts on failure.
     * Resolves to true on success.
     */
    async function saveUserDrinksSettings(uid, fields, what) {
        try {
            const resp = await fetch(PAGE.urls['user/set-user-drinks-settings'], {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                credentials: 'same-origin',
                body: new URLSearchParams(Object.assign({ uid: uid }, fields))
            });
            const data = await resp.json();
            if (!data.success) {
                alert('Fehler beim Speichern: ' + (data.error || 'Unbekannter Fehler'));
                return false;
            }
            return true;
        } catch (e) {
            alert('Netzwerkfehler beim Speichern ' + what + '.');
            return false;
        }
    }

    function findUserByUid(uid) {
        return users.find(u => u.uid === uid);
    }
    // Helper: find user by alias/email string
    function findUserByString(str) {
        str = str.trim().toLowerCase();
        return users.find(u => (u.alias + ' (' + u.email + ')').toLowerCase() === str);
    }
    // Autocomplete logic
    var userSearchMatches = [];
    var userSearchSelectedIndex = -1;

    function highlightUserSearchSelection() {
        var items = userSearchList ? userSearchList.querySelectorAll('[data-user-index]') : [];
        items.forEach(function(item, idx) {
            if (idx === userSearchSelectedIndex) {
                item.style.background = '#e3f0fa';
                item.scrollIntoView({ block: 'nearest' });
            } else {
                item.style.background = '';
            }
        });
    }

    function selectUserSearchMatch(match) {
        if (!match) return;
        userSearch.value = match.alias + ' (' + match.email + ')';
        userIdInput.value = match.uid;
        switchUserContext(match.uid);
        userSearchList.innerHTML = '';
        userSearchMatches = [];
        userSearchSelectedIndex = -1;
        updateDrinksFields();
        updateUserInfo();
    }

    function renderUserList(filter) {
        userSearchList.innerHTML = '';
        userSearchMatches = [];
        userSearchSelectedIndex = -1;
        if (!filter) return;
        var matches = users.filter(u =>
            u.alias.toLowerCase().includes(filter) ||
            u.email.toLowerCase().includes(filter)
        );
        userSearchMatches = matches;
        if (matches.length === 0) return;
        var list = document.createElement('div');
        list.style.position = 'absolute';
        list.style.background = '#fff';
        list.style.border = '1px solid #ccc';
        list.style.width = '100%';
        list.style.maxHeight = '180px';
        list.style.overflowY = 'auto';
        matches.forEach(function(u, idx) {
            var item = document.createElement('div');
            item.setAttribute('data-user-index', String(idx));
            item.textContent = u.alias + ' (' + u.email + ')';
            item.style.padding = '4px 8px';
            item.style.cursor = 'pointer';
            item.addEventListener('mousedown', function(e) {
                e.preventDefault();
                selectUserSearchMatch(u);
            });
            item.addEventListener('mouseenter', function() {
                userSearchSelectedIndex = idx;
                highlightUserSearchSelection();
            });
            list.appendChild(item);
        });
        userSearchList.appendChild(list);
    }
    userSearch.addEventListener('input', function() {
        var val = userSearch.value.trim().toLowerCase();
        renderUserList(val);
        // If input matches exactly, set userIdInput
        var user = findUserByString(userSearch.value);
        if (user) {
            userIdInput.value = user.uid;
            switchUserContext(user.uid);
            updateDrinksFields();
            updateUserInfo();
        } else {
            userIdInput.value = '';
            switchUserContext('');
        }
    });
    userSearch.addEventListener('blur', function() {
        setTimeout(function() { userSearchList.innerHTML = ''; }, 150);
    });
    userSearch.addEventListener('keydown', function(e) {
        if (e.key === 'ArrowDown') {
            if (userSearchMatches.length === 0) return;
            e.preventDefault();
            userSearchSelectedIndex = Math.min(userSearchSelectedIndex + 1, userSearchMatches.length - 1);
            highlightUserSearchSelection();
        } else if (e.key === 'ArrowUp') {
            if (userSearchMatches.length === 0) return;
            e.preventDefault();
            userSearchSelectedIndex = Math.max(userSearchSelectedIndex - 1, -1);
            highlightUserSearchSelection();
        } else if (e.key === 'Enter') {
            if (userSearchMatches.length === 0) return;
            e.preventDefault();
            var idx = userSearchSelectedIndex >= 0 ? userSearchSelectedIndex : 0;
            selectUserSearchMatch(userSearchMatches[idx]);
        } else if (e.key === 'Escape') {
            userSearchList.innerHTML = '';
            userSearchMatches = [];
            userSearchSelectedIndex = -1;
        }
    });
    // Check for uid in URL, else restore last selected user from localStorage
    function getUidFromUrl() {
        var params = new URLSearchParams(window.location.search);
        var uid = params.get('uid');
        return uid && /^\d+$/.test(uid) ? uid : null;
    }
    var urlUid = getUidFromUrl();
    if (urlUid) {
        var user = findUserByUid(urlUid);
        if (user) {
            userSearch.value = user.alias + ' (' + user.email + ')';
            userIdInput.value = user.uid;
            switchUserContext(user.uid);
            updateDrinksFields();
            updateUserInfo();
            // Remove uid from URL after preselecting
            if (window.history && window.history.replaceState) {
                var url = new URL(window.location.href);
                url.searchParams.delete('uid');
                window.history.replaceState({}, document.title, url.pathname + url.search);
            }
        }
    } else {
        var lastUid = localStorage.getItem('deposits_selected_uid');
        if (lastUid) {
            var user = findUserByUid(lastUid);
            if (user) {
                userSearch.value = user.alias + ' (' + user.email + ')';
                userIdInput.value = user.uid;
                switchUserContext(user.uid);
                updateDrinksFields();
                updateUserInfo();
            }
        }
    }
    function updateDrinksFields(drinksEnabledFromApi, drinksAliasFromApi, isTeamFromApi, orderEmailOptionFromApi, teamleadEmailFromApi) {
        var uid = userIdInput.value;
        var user = findUserByUid(uid);
        var drinksEnabled = typeof drinksEnabledFromApi === 'boolean'
            ? drinksEnabledFromApi
            : (user ? user.drinks_enabled === 1 : false);
        var alias = typeof drinksAliasFromApi === 'string'
            ? drinksAliasFromApi
            : (user ? user.drinks_alias : '');
        var isTeam = typeof isTeamFromApi === 'boolean'
            ? isTeamFromApi
            : (user ? user.is_team === 1 : false);
        var orderEmailOption = typeof orderEmailOptionFromApi === 'string' && orderEmailOptionFromApi !== ''
            ? orderEmailOptionFromApi
            : 'order';
        var teamleadEmail = typeof teamleadEmailFromApi === 'string'
            ? teamleadEmailFromApi
            : (user ? (user.teamlead_email || '') : '');
        drinksEnabledCheckbox.checked = drinksEnabled;
        drinksEnabledCheckbox.disabled = !user;
        if (orderEmailOptionSelect) {
            orderEmailOptionSelect.value = orderEmailOption;
            orderEmailOptionSelect.disabled = !user || !drinksEnabled;
        }
        isTeamCheckbox.checked = isTeam;
        isTeamCheckbox.disabled = !user;
        if (teamleadRow) {
            teamleadRow.style.display = isTeam ? 'flex' : 'none';
        }
        if (teamleadLabel) {
            teamleadLabel.style.display = isTeam ? '' : 'none';
        }
        if (tabButtons.spieltage) {
            tabButtons.spieltage.style.display = isTeam ? '' : 'none';
        }
        if (!isTeam && activeDepositTab === 'spieltage') {
            setActiveDepositTab('buchungen');
        }
        renderTeamleadRows(teamleadEmail);
        setTeamleadControlsDisabled(!user || !drinksEnabled || !isTeam);
        updateTeamSpieltagVisibility();
        if (!isTeam && teamNewSpieltagInput) {
            teamNewSpieltagInput.value = '';
        }
        if (!isTeam) {
            pendingNewSpieltagName = '';
            selectedTeamEventIdInContext = '';
        }
        if (typeof drinksAliasFromApi === 'string') {
            aliasInput.value = drinksAliasFromApi;
        } else if (user) {
            aliasInput.value = user.drinks_alias || '';
        } else {
            aliasInput.value = '';
        }
        if (drinksEnabled) {
            aliasRow.style.display = '';
        } else {
            aliasRow.style.display = 'none';
        }
    }
    drinksEnabledCheckbox.addEventListener('change', async function() {
        var uid = userIdInput.value;
        var user = findUserByUid(uid);
        var drinksEnabled = drinksEnabledCheckbox.checked;
        if (user) {
            user.drinks_enabled = drinksEnabled ? 1 : 0;
        }
        if (drinksEnabled) {
            aliasRow.style.display = '';
        } else {
            aliasRow.style.display = 'none';
        }
        if (orderEmailOptionSelect) {
            orderEmailOptionSelect.disabled = !uid || !drinksEnabled;
        }
        setTeamleadControlsDisabled(!uid || !drinksEnabled || !isTeamCheckbox.checked);
        if (uid) {
            await saveUserDrinksSettings(uid, { drinks_enabled: drinksEnabled ? '1' : '0' }, 'der Drinks-Einstellung');
        }
    });
    if (orderEmailOptionSelect) {
        orderEmailOptionSelect.addEventListener('change', async function() {
            var uid = userIdInput.value;
            var orderEmailOption = orderEmailOptionSelect.value || 'order';
            if (!uid) {
                return;
            }
            await saveUserDrinksSettings(uid, { order_email_option: orderEmailOption }, 'der Bestell-Emails-Einstellung');
        });
    }
    isTeamCheckbox.addEventListener('change', async function() {
        var uid = userIdInput.value;
        var user = findUserByUid(uid);
        var isTeam = isTeamCheckbox.checked;
        if (user) {
            user.is_team = isTeam ? 1 : 0;
        }
        if (teamleadRow) {
            teamleadRow.style.display = isTeam ? 'flex' : 'none';
        }
        if (teamleadLabel) {
            teamleadLabel.style.display = isTeam ? '' : 'none';
        }
        setTeamleadControlsDisabled(!uid || !drinksEnabledCheckbox.checked || !isTeam);
        if (uid) {
            if (await saveUserDrinksSettings(uid, { is_team: isTeam ? '1' : '0' }, 'der Mannschafts-Account-Einstellung')) {
                updateUserInfo();
            }
        }
    });
    var setAliasBtn = document.getElementById('set-alias-btn');

    function splitTeamleadEmails(rawValue) {
        var raw = String(rawValue || '').trim();
        if (!raw) return [];
        var parts = raw.split(/[;,]+/);
        var unique = [];
        for (var i = 0; i < parts.length; i++) {
            var email = String(parts[i] || '').trim().toLowerCase();
            if (!email) continue;
            if (unique.indexOf(email) === -1) unique.push(email);
        }
        return unique;
    }

    function isValidEmail(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(email || '').trim());
    }

    function extractEmailFromInputValue(value) {
        var text = String(value || '').trim();
        if (!text) return '';
        var bracketMatch = text.match(/\(([^()]+@[^()]+)\)\s*$/);
        if (bracketMatch && bracketMatch[1]) {
            return String(bracketMatch[1]).trim().toLowerCase();
        }
        return text.toLowerCase();
    }

    function formatTeamleadInputValueByEmail(email) {
        var normalized = String(email || '').trim().toLowerCase();
        if (!normalized) return '';
        var matchedUser = users.find(function(u) {
            return String(u.email || '').trim().toLowerCase() === normalized;
        });
        return matchedUser ? (matchedUser.alias + ' (' + matchedUser.email + ')') : normalized;
    }

    function getTeamleadEmailsFromRows() {
        if (!teamleadRowsContainer) return [];
        var inputs = teamleadRowsContainer.querySelectorAll('.teamlead-input');
        var unique = [];
        inputs.forEach(function(input) {
            var email = extractEmailFromInputValue(input.value);
            if (!email || !isValidEmail(email)) return;
            if (unique.indexOf(email) === -1) unique.push(email);
        });
        return unique;
    }

    async function saveTeamleadEmailList(emails) {
        var uid = userIdInput.value;
        var user = findUserByUid(uid);
        if (!uid) return;
        var normalizedEmails = Array.isArray(emails) ? emails : [];
        var storedValue = normalizedEmails.join(', ');
        if (user) user.teamlead_email = storedValue;
        await saveUserDrinksSettings(uid, { teamlead_email: storedValue }, 'des Mannschaftführers');
    }

    function setTeamleadControlsDisabled(disabled) {
        if (!teamleadRowsContainer) return;
        var inputs = teamleadRowsContainer.querySelectorAll('.teamlead-input');
        var removeButtons = teamleadRowsContainer.querySelectorAll('.teamlead-remove-btn');
        inputs.forEach(function(input) { input.disabled = !!disabled; });
        removeButtons.forEach(function(btn) { btn.disabled = !!disabled; });
    }

    function ensureTrailingEmptyTeamleadRow() {
        if (!teamleadRowsContainer) return;
        var inputs = teamleadRowsContainer.querySelectorAll('.teamlead-input');
        var hasEmpty = false;
        inputs.forEach(function(input) {
            if (String(input.value || '').trim() === '') {
                hasEmpty = true;
            }
        });
        if (!hasEmpty) {
            createTeamleadRow('');
        }
    }

    function renderTeamleadPopupForInput(input, popup, filter) {
        var term = String(filter || '').trim().toLowerCase();
        popup.innerHTML = '';
        input._teamleadMatches = [];
        input._teamleadSelectedIndex = -1;
        if (!term) return;

        var matches = users.filter(function(u) {
            var alias = String(u.alias || '').toLowerCase();
            var email = String(u.email || '').toLowerCase();
            return alias.indexOf(term) !== -1 || email.indexOf(term) !== -1;
        });
        input._teamleadMatches = matches;
        if (matches.length === 0) return;

        var list = document.createElement('div');
        list.style.cssText = 'background:#fff; border:1px solid #ccc; width:100%; max-height:180px; overflow-y:auto;';
        matches.forEach(function(u, idx) {
            var item = document.createElement('div');
            item.setAttribute('data-teamlead-index', String(idx));
            item.textContent = u.alias + ' (' + u.email + ')';
            item.style.cssText = 'padding:4px 8px; cursor:pointer;';
            item.addEventListener('mouseenter', function() {
                input._teamleadSelectedIndex = idx;
                highlightTeamleadPopupSelection(input, popup);
            });
            item.addEventListener('mousedown', function(e) {
                e.preventDefault();
                applyTeamleadMatchSelection(input, popup, u);
            });
            list.appendChild(item);
        });
        popup.appendChild(list);
    }

    function highlightTeamleadPopupSelection(input, popup) {
        var items = popup.querySelectorAll('[data-teamlead-index]');
        items.forEach(function(item, idx) {
            item.style.background = (idx === input._teamleadSelectedIndex) ? '#e3f0fa' : '';
            if (idx === input._teamleadSelectedIndex) {
                item.scrollIntoView({ block: 'nearest' });
            }
        });
    }

    function applyTeamleadMatchSelection(input, popup, userMatch) {
        if (!input || !userMatch) return;
        input.value = userMatch.alias + ' (' + userMatch.email + ')';
        popup.innerHTML = '';
        input._teamleadMatches = [];
        input._teamleadSelectedIndex = -1;
        persistTeamleadRows();
    }

    function createTeamleadRow(initialEmail) {
        if (!teamleadRowsContainer) return;
        var row = document.createElement('div');
        row.style.cssText = 'display:flex; align-items:center; gap:6px; position:relative;';

        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'teamlead-input';
        input.autocomplete = 'off';
        input.placeholder = 'Benutzer suchen...';
        input.style.cssText = 'width:280px;';
        input.value = formatTeamleadInputValueByEmail(initialEmail);
        input._teamleadMatches = [];
        input._teamleadSelectedIndex = -1;

        var removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'teamlead-remove-btn';
        removeBtn.title = 'Entfernen';
        removeBtn.style.cssText = 'background:none; border:none; padding:0 6px; font-size:18px; color:#888; cursor:pointer; line-height:1;';
        removeBtn.textContent = '×';

        var popup = document.createElement('div');
        popup.className = 'teamlead-search-list';
        popup.style.cssText = 'position:absolute; top:100%; left:0; z-index:20; min-width:280px;';

        input.addEventListener('input', function() {
            renderTeamleadPopupForInput(input, popup, input.value);
        });

        input.addEventListener('keydown', function(e) {
            if (e.key === 'ArrowDown') {
                if (!input._teamleadMatches || input._teamleadMatches.length === 0) return;
                e.preventDefault();
                input._teamleadSelectedIndex = Math.min((input._teamleadSelectedIndex || -1) + 1, input._teamleadMatches.length - 1);
                highlightTeamleadPopupSelection(input, popup);
            } else if (e.key === 'ArrowUp') {
                if (!input._teamleadMatches || input._teamleadMatches.length === 0) return;
                e.preventDefault();
                input._teamleadSelectedIndex = Math.max((input._teamleadSelectedIndex || -1) - 1, -1);
                highlightTeamleadPopupSelection(input, popup);
            } else if (e.key === 'Enter') {
                if (!input._teamleadMatches || input._teamleadMatches.length === 0) {
                    persistTeamleadRows();
                    return;
                }
                e.preventDefault();
                var idx = input._teamleadSelectedIndex >= 0 ? input._teamleadSelectedIndex : 0;
                applyTeamleadMatchSelection(input, popup, input._teamleadMatches[idx]);
            } else if (e.key === 'Escape') {
                popup.innerHTML = '';
                input._teamleadMatches = [];
                input._teamleadSelectedIndex = -1;
            }
        });

        input.addEventListener('blur', function() {
            setTimeout(function() {
                popup.innerHTML = '';
                persistTeamleadRows();
            }, 150);
        });

        removeBtn.addEventListener('click', function() {
            row.remove();
            ensureTrailingEmptyTeamleadRow();
            persistTeamleadRows();
        });

        row.appendChild(input);
        row.appendChild(removeBtn);
        row.appendChild(popup);
        teamleadRowsContainer.appendChild(row);
        return row;
    }

    function renderTeamleadRows(teamleadEmailValue) {
        if (!teamleadRowsContainer) return;
        teamleadRowsContainer.innerHTML = '';
        var emails = splitTeamleadEmails(teamleadEmailValue);
        if (emails.length === 0) {
            createTeamleadRow('');
            return;
        }
        emails.forEach(function(email) { createTeamleadRow(email); });
        ensureTrailingEmptyTeamleadRow();
    }

    function persistTeamleadRows() {
        var emails = getTeamleadEmailsFromRows();
        var invalidInputs = [];
        if (teamleadRowsContainer) {
            var inputs = teamleadRowsContainer.querySelectorAll('.teamlead-input');
            inputs.forEach(function(input) {
                var rawValue = String(input.value || '').trim();
                if (!rawValue) return;
                var email = extractEmailFromInputValue(rawValue);
                if (!isValidEmail(email)) {
                    invalidInputs.push(input);
                } else {
                    input.value = formatTeamleadInputValueByEmail(email);
                }
            });
        }
        if (invalidInputs.length > 0) {
            alert('Bitte gültige E-Mail-Adresse auswählen oder eingeben.');
            invalidInputs[0].focus();
            return;
        }
        ensureTrailingEmptyTeamleadRow();
        saveTeamleadEmailList(emails);
    }

    setAliasBtn.addEventListener('click', async function() {
        var uid = userIdInput.value;
        var user = findUserByUid(uid);
        var drinksEnabled = drinksEnabledCheckbox.checked;
        var alias = aliasInput.value;
        if (!drinksEnabled) {
            alert('Alias kann nur geändert werden, wenn Drinks aktiviert ist.');
            return;
        }
        if (user) {
            user.drinks_alias = alias;
        }
        if (uid) {
            setAliasBtn.disabled = true;
            setAliasBtn.textContent = '...';
            await saveUserDrinksSettings(uid, { drinks_alias: alias }, 'der Theken-ID');
            setAliasBtn.disabled = false;
            setAliasBtn.textContent = 'Theken-ID speichern';
        }
    });

    async function updateUserInfo(scrollToEntry) {
        var uid = userIdInput.value;
        switchUserContext(uid);
        localStorage.setItem('deposits_selected_uid', uid);
        balanceBox.style.display = '';
        balanceBox.textContent = 'Aktueller Kontostand: —';
        historySection.innerHTML = '<h2>Buchungs- und Einzahlungsverlauf</h2><div style="color:#666;">Bitte zuerst einen Benutzer auswählen.</div>';
        if (uid) {
            try {
                // Always send showStorno state to backend
                var url = PAGE.urls['user/get-user-deposits-data'] + '?uid=' + encodeURIComponent(uid) + '&showStorno=' + (showStorno ? '1' : '0');
                if (selectedTeamEventIdInContext) {
                    url += '&selected_teamevent_id=' + encodeURIComponent(selectedTeamEventIdInContext);
                }
                const resp = await fetch(url, { credentials: 'same-origin' });
                if (!resp.ok) throw new Error('HTTP ' + resp.status);
                const data = await resp.json();
                if (typeof data.drinks_enabled !== 'undefined' || typeof data.drinks_alias !== 'undefined' || typeof data.is_team !== 'undefined' || typeof data.order_email_option !== 'undefined' || typeof data.teamlead_email !== 'undefined') {
                    updateDrinksFields(
                        data.drinks_enabled,
                        data.drinks_alias,
                        typeof data.is_team !== 'undefined' ? data.is_team : undefined,
                        typeof data.order_email_option !== 'undefined' ? data.order_email_option : undefined,
                        typeof data.teamlead_email !== 'undefined' ? data.teamlead_email : undefined
                    );
                    var user = findUserByUid(uid);
                    if (user) {
                        if (typeof data.drinks_enabled !== 'undefined') user.drinks_enabled = data.drinks_enabled ? 1 : 0;
                        if (typeof data.drinks_alias !== 'undefined') user.drinks_alias = data.drinks_alias || '';
                        if (typeof data.teamlead_email !== 'undefined') user.teamlead_email = data.teamlead_email || '';
                        if (typeof data.is_team !== 'undefined') user.is_team = data.is_team ? 1 : 0;
                    }
                } else {
                    updateDrinksFields();
                }
                if (data && data.is_team && Array.isArray(data.team_events)) {
                    var selectedTeamEventId = selectedTeamEventIdInContext || (data.current_teamevent_id ? String(data.current_teamevent_id) : '');
                    fillTeamSpieltagOptions(data.team_events, selectedTeamEventId);
                    renderTeamEventsPanelList(data.team_events, selectedTeamEventId);
                    if (teamSpieltagSelect && teamSpieltagSelect.value && teamSpieltagSelect.value !== '__new__') {
                        selectedTeamEventIdInContext = teamSpieltagSelect.value;
                    }
                } else {
                    selectedTeamEventIdInContext = '';
                    fillTeamSpieltagOptions([], null);
                    renderTeamEventsPanelList([], '');
                }
                if (typeof data.balance !== 'undefined' && data.balance !== null) {
                    balanceBox.style.display = '';
                    balanceBox.textContent = 'Aktueller Kontostand: ' + formatCurrency(data.balance);
                }
                function renderHistory(history, showStorno) {
                    function hashLabelColor(label) {
                        var s = String(label || '');
                        var hash = 2166136261;
                        for (var idx = 0; idx < s.length; idx++) {
                            hash ^= s.charCodeAt(idx);
                            hash = Math.imul(hash, 16777619);
                        }
                        hash += hash << 13;
                        hash ^= hash >>> 7;
                        hash += hash << 3;
                        hash ^= hash >>> 17;
                        hash += hash << 5;
                        return hash >>> 0;
                    }

                    function renderSpieltagBadge(entry) {
                        var label = entry && entry.spieltag_label ? String(entry.spieltag_label).trim() : '';
                        var entryId = entry && typeof entry.id !== 'undefined' ? String(entry.id) : '';
                        var entryType = entry && entry.type === 'Einzahlung' ? 'deposit' : 'order';
                        var teamEventId = entry && entry.teamevent_id ? String(entry.teamevent_id) : '';
                        var isTeamAccount = !!(isTeamCheckbox && isTeamCheckbox.checked);
                        if (!label && isTeamAccount && entryId) {
                            return ' <button type="button" class="history-team-event-badge" data-entry-id="' + escapeHtml(entryId) + '" data-entry-type="' + escapeHtml(entryType) + '" data-team-event-id="" data-team-event-label="" title="Spieltag zuweisen" aria-label="Spieltag zuweisen" style="display:inline-block; width:18px; height:16px; margin-left:6px; padding:0; border:1px solid #90a4ae; border-radius:5px; background:#fff; color:#607d8b; font-size:11px; line-height:1.2; text-decoration:none; vertical-align:middle; cursor:pointer;"></button>';
                        }
                        if (!label) return '';
                        var hash = hashLabelColor(label);
                        var hue = (hash % 360 + ((hash >>> 11) % 37)) % 360;
                        var saturation = 52 + ((hash >>> 5) % 24);
                        var backgroundLightness = 88 + ((hash >>> 9) % 7);
                        var borderLightness = 52 + ((hash >>> 15) % 12);
                        var textLightness = 20 + ((hash >>> 21) % 10);
                        var bg = 'hsl(' + hue + ', ' + saturation + '%, ' + backgroundLightness + '%)';
                        var border = 'hsl(' + hue + ', ' + Math.max(36, saturation - 18) + '%, ' + borderLightness + '%)';
                        var text = 'hsl(' + hue + ', ' + Math.max(34, saturation - 14) + '%, ' + textLightness + '%)';
                        if (!isTeamAccount || !entryId || !teamEventId) {
                            return ' <span style="display:inline-block; margin-left:6px; padding:1px 6px; border:1px solid ' + border + '; border-radius:5px; background:' + bg + '; color:' + text + '; font-size:11px; font-weight:600; line-height:1.2; text-decoration:none; vertical-align:middle;">' + escapeHtml(label) + '</span>';
                        }
                        return ' <button type="button" class="history-team-event-badge" data-entry-id="' + escapeHtml(entryId) + '" data-entry-type="' + escapeHtml(entryType) + '" data-team-event-id="' + escapeHtml(teamEventId) + '" data-team-event-label="' + escapeHtml(label) + '" style="display:inline-block; margin-left:6px; padding:1px 6px; border:1px solid ' + border + '; border-radius:5px; background:' + bg + '; color:' + text + '; font-size:11px; font-weight:600; line-height:1.2; text-decoration:none; vertical-align:middle; cursor:pointer;">' + escapeHtml(label) + '</button>';
                    }

                    var html = '<h2>Buchungs- und Einzahlungsverlauf</h2>' +
                        '<div style="margin-bottom:10px;"><label><input type="checkbox" id="show-storno-checkbox"' + (showStorno ? ' checked' : '') + '> Zeige Storno</label></div>';
                    // Calculate running balance in chronological order and attach to each entry
                    var allEntries = [];
                    for (var day of history) {
                        for (var entry of day.entries) {
                            allEntries.push(entry);
                        }
                    }
                    // Sort all entries by date+time ascending (oldest first)
                    allEntries.sort(function(a, b) {
                        var ad = (a.datetime || a.date || '');
                        var bd = (b.datetime || b.date || '');
                        return ad.localeCompare(bd);
                    });
                    var runningBalance = 0;
                    for (const entry of allEntries) {
                        var amount = parseFloat(entry.amount);
                        if (isNaN(amount)) amount = 0;
                        if (entry.type === 'Einzahlung') {
                            if (!entry.deleted) {
                                runningBalance += amount;
                            }
                        } else {
                            if (!entry.deleted || showStorno) {
                                runningBalance += amount;
                            }
                        }
                        entry.displayBalance = runningBalance;
                    }
                    // Reverse days for display
                    var reversedDays = history.slice().reverse();
                    for (var day of reversedDays) {
                        // Only render if there are any non-deleted entries (or deleted if showStorno)
                        var hasEntries = day.entries.some(function(e) {
                            return (!e.deleted) || (showStorno && !!e.deleted);
                        });
                        if (!hasEntries) continue;
                        var daySum = day.entries
                            .filter(e => !e.deleted)
                            .reduce((sum, e) => sum + (parseFloat(e.amount) || 0), 0);
                        html += '<div style="margin-top:18px; margin-bottom:8px; font-weight:bold; color:#1769aa;">' + escapeHtml(day.date) + '</div>';
                        html += '<div style="margin-left:32px;">';
                        // Calculate per-day Kontostand: sum all non-deleted entries up to and including this day
                        var dayEnd = day.date + ' 23:59:59';
                        var dayBalance = 0;
                        for (var j = 0; j < allEntries.length; j++) {
                            var entry = allEntries[j];
                            if (entry.deleted) continue;
                            if ((entry.datetime && entry.datetime <= dayEnd) || (entry.date && entry.date <= day.date)) {
                                var amount = parseFloat(entry.amount);
                                if (isNaN(amount)) amount = 0;
                                dayBalance += amount;
                            }
                        }
                        html += '<table class="default-table" style="margin-bottom:4px; text-align:left; margin-left:0; padding-left:16px;">';
                        // Show deposits (Einzahlung) first, oldest first
                        for (var i = 0; i < day.entries.length; i++) {
                            var entry = day.entries[i];
                            if (entry.type !== 'Einzahlung') continue;
                            // Show deleted deposits only when explicitly requested.
                            if (entry.deleted && !showStorno) continue;
                            var isDeleted = entry.deleted;
                            var style = 'color:#1769aa;';
                            if (isDeleted) style += ' text-decoration:line-through;color:#b0b0b0;';
                            style += ' padding-top:1px; padding-bottom:1px; line-height:1; text-align:left;';

                            var timeStr = '';
                            if (entry.datetime) {
                                var match = entry.datetime.match(/\b(\d{2}:\d{2})/);
                                if (match) timeStr = match[1];
                            }

                            var xBtn = '';
                            if (isDeleted) {
                                xBtn = '<button class="entry-cancel-btn" data-entry-id="' + escapeHtml(entry.id) + '" data-entry-type="deposit" data-reactivate="1" title="Reaktivieren" style="margin-right:6px; color:#388e3c; background:none; border:none; font-size:15px; font-weight:bold; cursor:pointer; line-height:1;">&times;</button>';
                            } else {
                                xBtn = '<button class="entry-cancel-btn" data-entry-id="' + escapeHtml(entry.id) + '" data-entry-type="deposit" title="Stornieren" style="margin-right:6px; color:#d32f2f; background:none; border:none; font-size:15px; font-weight:bold; cursor:pointer; line-height:1;">&times;</button>';
                            }

                            html += '<tr style="' + style + '">';
                            html += '<td style="text-align:left;padding-top:1px;padding-bottom:1px;line-height:1;">' + xBtn + escapeHtml(entry.type) + '</td>';
                            html += '<td style="text-align:left;padding-top:1px;padding-bottom:1px;line-height:1;">' + formatCurrency(entry.amount) + '</td>';
                            html += '<td style="text-align:left;padding-top:1px;padding-bottom:1px;line-height:1;">' + escapeHtml(entry.desc || '') + (timeStr ? ' <span style=\"color:#888;font-size:90%;margin-left:8px;\">' + timeStr + '</span>' : '') + renderSpieltagBadge(entry) + '</td>';
                            html += '</tr>';
                        }
                        // Show orders (reverse for most recent first)
                        for (var i = day.entries.length - 1; i >= 0; i--) {
                            var entry = day.entries[i];
                            if (entry.type === 'Einzahlung') continue;
                            if (entry.deleted && !showStorno) continue;
                            var isDeleted = entry.deleted;
                            var color = entry.type === 'Buchung' ? '#1769aa' : '#b71c1c';
                            var style = 'color:' + color + ';';
                            if (isDeleted) style += ' text-decoration:line-through;color:#b0b0b0;';
                            style += ' padding-top:1px; padding-bottom:1px; line-height:1; text-align:left;';
                            var timeStr = '';
                            if (entry.datetime) {
                                var match = entry.datetime.match(/\b(\d{2}:\d{2})/);
                                if (match) timeStr = match[1];
                            }
                            var xBtn = '';
                            var orderId = (typeof entry.id !== 'undefined') ? entry.id : (typeof entry.order_id !== 'undefined' ? entry.order_id : '');
                            if (isDeleted) {
                                xBtn = '<button class="entry-cancel-btn" data-entry-id="' + escapeHtml(orderId) + '" data-entry-type="order" data-reactivate="1" title="Reaktivieren" style="margin-right:6px; color:#388e3c; background:none; border:none; font-size:15px; font-weight:bold; cursor:pointer; line-height:1;">&times;</button>';
                            } else {
                                xBtn = '<button class="entry-cancel-btn" data-entry-id="' + escapeHtml(orderId) + '" data-entry-type="order" title="Stornieren" style="margin-right:6px; color:#d32f2f; background:none; border:none; font-size:15px; font-weight:bold; cursor:pointer; line-height:1;">&times;</button>';
                            }
                            // For Sonstiges (1) and money transfers (-1), show only the comment
                            var descWithComment = '';
                            if (isCustomPriceDrink(entry.drink_id)) {
                                // Only show comment for special items, no drink name, no qty
                                if (entry.comment && entry.comment.trim() !== '') {
                                    descWithComment = '<span style="color:#888;">' + escapeHtml(entry.comment) + '</span>';
                                } else {
                                    descWithComment = '';
                                }
                            } else {
                                // Other drinks: "1 x Bier" is shown as "Bier"
                                descWithComment = escapeHtml(String(entry.desc || '').replace(/^\s*1\s*x\s+/, ''));
                                if (entry.comment && entry.comment.trim() !== '') {
                                    descWithComment += ' <span style="color:#888;">(' + escapeHtml(entry.comment) + ')</span>';
                                }
                            }
                            html += '<tr style="' + style + '">';
                            html += '<td style="text-align:left;padding-top:1px;padding-bottom:1px;line-height:1;">' + xBtn + escapeHtml(entry.type) + '</td>';
                            html += '<td style="text-align:left;padding-top:1px;padding-bottom:1px;line-height:1;">' + formatCurrency(entry.amount) + '</td>';
                            html += '<td style="text-align:left;padding-top:1px;padding-bottom:1px;line-height:1;">' + descWithComment + (timeStr ? ' <span style="color:#888;font-size:90%;margin-left:8px;">' + timeStr + '</span>' : '') + renderSpieltagBadge(entry) + '</td>';
                            html += '</tr>';
                        }
                        html += '<tr style="font-weight:bold;background:#fafdff;">' +
                            '<td style="text-align:left;">Tagessumme</td>' +
                            '<td style="text-align:left;">' + formatCurrency(daySum) + '</td>' +
                            '<td style="text-align:left;">';
                        html += '<span style="color:#2196f3;">Kontostand: ' + formatCurrency(dayBalance) + '</span>';
                        html += '</td></tr>';
                        html += '</table>';
                        html += '</div>';
                    }
                    return html;
                }
                function renderPendingPaypalDeposits(pendingDeposits) {
                    if (!Array.isArray(pendingDeposits) || pendingDeposits.length === 0) {
                        return '';
                    }
                    var html = '<div style="margin:14px 0 18px; padding:10px 12px; border:1px solid #f0c36d; border-radius:6px; background:#fff8e6; color:#72500b;">';
                    html += '<h3 style="margin:0 0 6px; color:#72500b;">Vorgemerkte PayPal-Einzahlungen</h3>';
                    pendingDeposits.forEach(function(pendingDeposit) {
                        var amount = parseFloat(pendingDeposit.amount);
                        var receivedAt = pendingDeposit.received_at ? String(pendingDeposit.received_at) : '';
                        var receivedAtLabel = receivedAt ? receivedAt.replace(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}:\d{2}).*$/, '$3.$2.$1 $4 Uhr') : '';
                        html += '<div style="font-weight:bold;">' + formatCurrency(isNaN(amount) ? 0 : amount) + ' vorgemerkt';
                        if (receivedAtLabel) {
                            html += ' <span style="font-weight:normal;">(' + escapeHtml(receivedAtLabel);
                        }
                        if (pendingDeposit.payer_name) {
                            html += (receivedAtLabel ? ' ' : ' <span style="font-weight:normal;">(') + 'PayPal: ' + escapeHtml(pendingDeposit.payer_name) + ')';
                        } else if (receivedAtLabel) {
                            html += ')';
                        }
                        if (receivedAtLabel || pendingDeposit.payer_name) {
                            html += '</span>';
                        }
                        html += '</div>';
                    });
                    html += '<div style="margin-top:4px; font-size:0.9em;">Wird nach der Verbuchung durch PayPal gutgeschrieben (in der Regel innerhalb von 1-4 Stunden).</div>';
                    html += '</div>';
                    return html;
                }
                if (Array.isArray(data.history)) {
                    var history = data.history;
                    function attachStornoHandler() {
                        var stornoCheckbox = document.getElementById('show-storno-checkbox');
                        if (stornoCheckbox) {
                            stornoCheckbox.addEventListener('change', function() {
                                showStorno = stornoCheckbox.checked;
                                localStorage.setItem('deposits_show_storno', showStorno ? '1' : '0');
                                historySection.innerHTML = renderHistory(history, showStorno);
                                attachStornoHandler();
                                attachEntryCancelHandlers();
                                attachHistoryTeamEventBadgeHandlers();
                            });
                        }
                    }
                    historySection.innerHTML = renderPendingPaypalDeposits(data.pending_paypal_deposits) + renderHistory(history, showStorno);
                    attachStornoHandler();
                    attachEntryCancelHandlers();
                        attachHistoryTeamEventBadgeHandlers();
                    // Scroll to entry if requested
                    if (scrollToEntry && scrollToEntry.entryId && scrollToEntry.entryType) {
                        setTimeout(function() {
                            var newBtn = document.querySelector('.entry-cancel-btn[data-entry-id="' + scrollToEntry.entryId + '"][data-entry-type="' + scrollToEntry.entryType + '"]');
                            if (newBtn) {
                                newBtn.scrollIntoView({ block: 'center', behavior: 'auto' });
                            }
                        }, 0);
                    }
                }
            } catch (e) {
                historySection.innerHTML = '<div style="color:red;">Fehler beim Laden der Daten.</div>';
                if (window.console && console.error) {
                    console.error('Fehler beim Laden der Daten:', e);
                }
            }
        } else {
            updateDrinksFields();
        }
    }
    // Only update if a user is preselected
    if (userIdInput.value) updateUserInfo();

    function renderTeamEventsPanelList(teamEvents, selectedTeamEventId) {
        currentTeamEventsForPanel = Array.isArray(teamEvents) ? teamEvents : [];
        currentSelectedTeamEventIdForPanel = String(selectedTeamEventId || '');
        if (!teamEventsList) return;

        if (!currentTeamEventsForPanel.length) {
            teamEventsList.innerHTML = '<div style="color:#666;">Keine Spieltage vorhanden.</div>';
            return;
        }

        var html = '<div style="display:grid; gap:6px;">';
        for (var i = 0; i < currentTeamEventsForPanel.length; i++) {
            var evt = currentTeamEventsForPanel[i] || {};
            var eventId = String(evt.id || '');
            if (!eventId) continue;
            var isSelected = currentSelectedTeamEventIdForPanel !== '' && eventId === currentSelectedTeamEventIdForPanel;
            var statusLabel = evt.closed ? 'abgeschlossen' : 'offen';
            var lineStyle = isSelected
                ? 'border:1px solid #1769aa; background:#eaf4ff;'
                : 'border:1px solid #d9e7f7; background:#fff;';
            html += '<button type="button" class="team-event-panel-item" data-team-event-id="' + escapeHtml(eventId) + '" style="display:flex; justify-content:space-between; align-items:center; gap:8px; text-align:left; padding:8px 10px; border-radius:8px; ' + lineStyle + '">';
            html += '<span><strong>' + escapeHtml(evt.label || ('Spieltag ' + eventId)) + '</strong> <span style="color:#607d8b; font-size:12px;">(' + statusLabel + ')</span></span>';
            html += '<span style="font-weight:700; color:' + (Number(evt.balance || 0) >= 0 ? '#2e7d32' : '#c62828') + ';">' + formatCurrency(Number(evt.balance || 0)) + '</span>';
            html += '</button>';
        }
        html += '</div>';
        teamEventsList.innerHTML = html;

        teamEventsList.querySelectorAll('.team-event-panel-item').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var selectedId = btn.getAttribute('data-team-event-id') || '';
                if (!selectedId) return;
                currentSelectedTeamEventIdForPanel = selectedId;
                renderTeamEventsPanelList(currentTeamEventsForPanel, selectedId);
                if (depositsTeamStatsModalController && typeof depositsTeamStatsModalController.open === 'function') {
                    depositsTeamStatsModalController.open(userIdInput.value, selectedId);
                }
            });
        });
    }

    function closeHistoryTeamEventEditModal() {
        if (!historyTeamEventEditModal) return;
        historyTeamEventEditModal.style.display = 'none';
        historyTeamEventEditState = { entryId: '', entryType: '', currentTeamEventId: '' };
        if (historyTeamEventEditMessage) {
            historyTeamEventEditMessage.textContent = '';
            historyTeamEventEditMessage.style.color = '#666';
        }
    }

    function openHistoryTeamEventEditModal(entryId, entryType, currentTeamEventId, currentLabel) {
        if (!historyTeamEventEditModal || !historyTeamEventEditSelect) return;
        if (!currentTeamEventsForPanel || currentTeamEventsForPanel.length === 0) {
            alert('Keine Spieltage für diese Mannschaft vorhanden.');
            return;
        }

        historyTeamEventEditState = {
            entryId: String(entryId || ''),
            entryType: String(entryType || ''),
            currentTeamEventId: String(currentTeamEventId || '')
        };

        historyTeamEventEditSelect.innerHTML = '';
        currentTeamEventsForPanel.forEach(function(evt) {
            var eventId = String((evt && evt.id) || '');
            var label = String((evt && evt.label) || '').trim();
            if (!eventId || !label || (evt && evt.closed)) return;
            var option = document.createElement('option');
            option.value = eventId;
            option.textContent = label + ' (offen)';
            historyTeamEventEditSelect.appendChild(option);
        });
        var newOption = document.createElement('option');
        newOption.value = '__new__';
        newOption.textContent = 'Neuen Spieltag anlegen';
        historyTeamEventEditSelect.appendChild(newOption);

        if (historyTeamEventEditState.currentTeamEventId) {
            historyTeamEventEditSelect.value = historyTeamEventEditState.currentTeamEventId;
        }
        if (!historyTeamEventEditSelect.value && historyTeamEventEditSelect.options.length > 0) {
            historyTeamEventEditSelect.selectedIndex = 0;
        }
        if (historyTeamEventEditSelect.options.length === 0) {
            alert('Keine offenen Spieltage für diese Mannschaft vorhanden.');
            closeHistoryTeamEventEditModal();
            return;
        }

        if (historyTeamEventEditTitle) {
            historyTeamEventEditTitle.textContent = entryType === 'deposit' ? 'Spieltag der Einzahlung bearbeiten' : 'Spieltag der Buchung bearbeiten';
        }
        if (historyTeamEventEditMessage) {
            historyTeamEventEditMessage.textContent = currentLabel ? ('Aktuell: ' + currentLabel) : '';
            historyTeamEventEditMessage.style.color = '#666';
        }
        historyTeamEventEditModal.style.display = 'flex';
    }

    async function createHistoryTeamSpieltag(label) {
        var uid = String(userIdInput && userIdInput.value ? userIdInput.value : '').trim();
        if (!uid || !label) return null;

        var created = await requestCreateTeamEvent(uid, label);
        var eventId = created.id;
        var eventLabel = created.label;

        var eventExists = currentTeamEventsForPanel.some(function(evt) {
            return String(evt && evt.id || '') === eventId;
        });
        if (!eventExists) {
            currentTeamEventsForPanel.push({ id: eventId, label: eventLabel, balance: 0, closed: 0 });
            renderTeamEventsPanelList(currentTeamEventsForPanel, currentSelectedTeamEventIdForPanel);
        }
        return eventId;
    }

    if (historyTeamEventEditSelect) {
        historyTeamEventEditSelect.addEventListener('change', async function() {
            if (historyTeamEventEditSelect.value !== '__new__') return;
            var newName = window.prompt('Neuen Spieltag eingeben:', getDefaultSpieltagName());
            if (!newName || !newName.trim()) {
                historyTeamEventEditSelect.selectedIndex = 0;
                return;
            }
            try {
                var createdId = await createHistoryTeamSpieltag(newName.trim());
                if (createdId) {
                    var option = document.createElement('option');
                    option.value = createdId;
                    option.textContent = newName.trim() + ' (offen)';
                    historyTeamEventEditSelect.insertBefore(option, historyTeamEventEditSelect.lastElementChild);
                    historyTeamEventEditSelect.value = createdId;
                }
            } catch (e) {
                alert((e && e.message) ? e.message : 'Spieltag konnte nicht angelegt werden.');
                historyTeamEventEditSelect.selectedIndex = 0;
            }
        });
    }

    async function saveHistoryTeamEventAssignment() {
        var uid = String(userIdInput && userIdInput.value ? userIdInput.value : '').trim();
        var entryId = historyTeamEventEditState.entryId;
        var entryType = historyTeamEventEditState.entryType;
        var teamEventId = String(historyTeamEventEditSelect && historyTeamEventEditSelect.value ? historyTeamEventEditSelect.value : '').trim();
        if (!uid || !entryId || !entryType || !teamEventId) return;

        var currentTeamEvent = currentTeamEventsForPanel.find(function(evt) {
            return String(evt && evt.id || '') === String(historyTeamEventEditState.currentTeamEventId || '');
        });
        if (currentTeamEvent && currentTeamEvent.closed && teamEventId !== historyTeamEventEditState.currentTeamEventId) {
            var confirmed = window.confirm('Der aktuelle Spieltag ist abgeschlossen. Möchten Sie den Eintrag wirklich einem anderen Spieltag zuordnen?');
            if (!confirmed) return;
        }

        historyTeamEventEditSaveBtn.disabled = true;
        if (historyTeamEventEditMessage) {
            historyTeamEventEditMessage.textContent = 'Speichere...';
            historyTeamEventEditMessage.style.color = '#666';
        }
        try {
            var resp = await fetch(PAGE.urls['user/update-user-history-team-event'], {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: new URLSearchParams({
                    uid: uid,
                    entry_id: entryId,
                    entry_type: entryType,
                    team_event_id: teamEventId
                })
            });
            var data = await resp.json();
            if (!resp.ok || !data || !data.success) {
                throw new Error((data && data.error) ? data.error : 'Spieltag konnte nicht aktualisiert werden.');
            }
            closeHistoryTeamEventEditModal();
            await updateUserInfo({ entryId: entryId, entryType: entryType });
        } catch (e) {
            if (historyTeamEventEditMessage) {
                historyTeamEventEditMessage.textContent = (e && e.message) ? e.message : 'Spieltag konnte nicht aktualisiert werden.';
                historyTeamEventEditMessage.style.color = '#d32f2f';
            }
        } finally {
            historyTeamEventEditSaveBtn.disabled = false;
        }
    }

    function attachHistoryTeamEventBadgeHandlers() {
        var buttons = document.querySelectorAll('.history-team-event-badge');
        buttons.forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                var entryId = btn.getAttribute('data-entry-id') || '';
                var entryType = btn.getAttribute('data-entry-type') || '';
                var teamEventId = btn.getAttribute('data-team-event-id') || '';
                var label = btn.getAttribute('data-team-event-label') || '';
                if (!entryId || !entryType) return;
                openHistoryTeamEventEditModal(entryId, entryType, teamEventId, label);
            });
        });
    }

    if (historyTeamEventEditModal) {
        historyTeamEventEditModal.addEventListener('click', function(e) {
            if (e.target === historyTeamEventEditModal) {
                closeHistoryTeamEventEditModal();
            }
        });
    }
    if (historyTeamEventEditCancelBtn) {
        historyTeamEventEditCancelBtn.addEventListener('click', closeHistoryTeamEventEditModal);
    }
    if (historyTeamEventEditCloseXBtn) {
        historyTeamEventEditCloseXBtn.addEventListener('click', closeHistoryTeamEventEditModal);
    }
    if (historyTeamEventEditSaveBtn) {
        historyTeamEventEditSaveBtn.addEventListener('click', saveHistoryTeamEventAssignment);
    }

// Ensure attachEntryCancelHandlers is defined to prevent JS errors
function attachEntryCancelHandlers() {
    var buttons = document.querySelectorAll('.entry-cancel-btn');
    buttons.forEach(function(btn) {
        btn.addEventListener('click', async function(e) {
            e.preventDefault();
            var entryId = btn.getAttribute('data-entry-id');
            var entryType = btn.getAttribute('data-entry-type');
            var reactivate = btn.getAttribute('data-reactivate') === '1';
            if (!entryId || !entryType) return;

            // Find the entry details from the rendered table row
            var row = btn.closest('tr');
            var cells = row ? row.querySelectorAll('td') : null;
            var entryTypeText = cells && cells[0] ? cells[0].textContent.replace(/^\s*×\s*/, '').trim() : '';
            var entryAmount = cells && cells[1] ? cells[1].textContent.trim() : '';
            var entryDesc = cells && cells[2] ? cells[2].textContent.trim() : '';
            var actionText = reactivate ? 'Wirklich wiederherstellen?' : 'Wirklich stornieren?';
            var confirmMsg =
                (entryType === 'deposit' ? 'Einzahlung' : 'Buchung') + ':\n' +
                entryTypeText + '\n' +
                entryAmount + (entryDesc ? ('\n' + entryDesc) : '') + '\n\n' +
                actionText;
            if (!window.confirm(confirmMsg)) {
                return;
            }

            btn.disabled = true;
            btn.textContent = '...';
            try {
                const resp = await fetch(PAGE.urls['user/toggle-deposit-order-deleted'], {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    credentials: 'same-origin',
                    body: new URLSearchParams({
                        entry_id: entryId,
                        entry_type: entryType
                    })
                });
                const data = await resp.json();
                if (!data.success) {
                    alert('Fehler beim Ändern des Status: ' + (data.error || 'Unbekannter Fehler'));
                } else {
                    // After update, scroll to the same entry (by id/type)
                    updateUserInfo({ entryId, entryType });
                }
            } catch (e) {
                alert('Netzwerkfehler beim Ändern des Status.');
            } finally {
                btn.disabled = false;
                btn.textContent = '×';
            }
        });
    });
}
});
