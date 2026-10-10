window.__bookingMoneyRecipients = DRINKS_PAGE.moneyRecipients;

function getBookingRecipientMatches(filter, options) {
    var term = String(filter || '').trim().toLowerCase();
    if (!term) {
        return [];
    }

    options = options || {};
    var excludeUserIds = Array.isArray(options.excludeUserIds) ? options.excludeUserIds : [];
    var allowUserId = parseInt(options.allowUserId || 0, 10);
    var recipients = Array.isArray(window.__bookingMoneyRecipients) ? window.__bookingMoneyRecipients : [];

    return recipients.filter(function(recipient) {
        var uid = parseInt(recipient.uid || 0, 10);
        if (!uid) {
            return false;
        }
        if (excludeUserIds.indexOf(uid) !== -1 && uid !== allowUserId) {
            return false;
        }
        var name = String(recipient.name || '').toLowerCase();
        var email = String(recipient.email || '').toLowerCase();
        return name.indexOf(term) !== -1 || email.indexOf(term) !== -1;
    });
}

function setupBookingRecipientAutocomplete(config) {
    var input = config.input;
    var list = config.list;
    var state = {
        matches: [],
        selectedIndex: -1
    };

    function clearList() {
        if (!list) return;
        list.innerHTML = '';
        state.matches = [];
        state.selectedIndex = -1;
    }

    function highlightSelection() {
        if (!list) return;
        var items = list.querySelectorAll('[data-index]');
        items.forEach(function(item, idx) {
            if (idx === state.selectedIndex) {
                item.style.background = '#e3f2fd';
                item.scrollIntoView({ block: 'nearest' });
            } else {
                item.style.background = 'transparent';
            }
        });
    }

    function selectMatch(index) {
        var idx = typeof index === 'number' ? index : 0;
        if (!state.matches[idx]) {
            return;
        }
        config.onSelect(state.matches[idx]);
        clearList();
    }

    function render(filter) {
        clearList();
        state.matches = config.getMatches(filter) || [];
        if (state.matches.length === 0 || !list) {
            return;
        }

        var wrap = document.createElement('div');
        wrap.style.cssText = config.listStyle || 'background:#fff; border:1px solid #ddd; max-height:150px; overflow-y:auto; box-shadow:0 4px 12px rgba(0,0,0,0.12); border-radius:6px;';

        state.matches.forEach(function(recipient, idx) {
            var item = document.createElement('div');
            item.setAttribute('data-index', String(idx));
            item.textContent = String(recipient.name || '') + ' (' + String(recipient.email || '') + ')';
            item.style.cssText = config.itemStyle || 'padding:10px 12px; cursor:pointer; border-bottom:1px solid #f0f0f0; font-size:14px; min-height:40px; display:flex; align-items:center;';
            item.addEventListener('mousedown', function(e) {
                e.preventDefault();
                selectMatch(idx);
            });
            item.addEventListener('mouseenter', function() {
                state.selectedIndex = idx;
                highlightSelection();
            });
            item.addEventListener('mouseleave', function() {
                if (state.selectedIndex === idx) {
                    state.selectedIndex = -1;
                }
                highlightSelection();
            });
            wrap.appendChild(item);
        });

        list.appendChild(wrap);
    }

    if (input) {
        input.addEventListener('input', function() {
            if (typeof config.onInputReset === 'function') {
                config.onInputReset();
            }
            render(input.value || '');
        });

        input.addEventListener('blur', function() {
            setTimeout(clearList, 150);
        });

        input.addEventListener('keydown', function(e) {
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (state.matches.length === 0) {
                    render(input.value || '');
                }
                if (state.matches.length > 0) {
                    state.selectedIndex = Math.min(state.selectedIndex + 1, state.matches.length - 1);
                    highlightSelection();
                }
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (state.matches.length > 0) {
                    state.selectedIndex = Math.max(state.selectedIndex - 1, -1);
                    highlightSelection();
                }
            } else if (e.key === 'Enter') {
                if (state.matches.length > 0) {
                    e.preventDefault();
                    selectMatch(state.selectedIndex >= 0 ? state.selectedIndex : 0);
                } else if (typeof config.onEnterWithoutMatches === 'function') {
                    config.onEnterWithoutMatches(e);
                }
            } else if (e.key === 'Escape') {
                clearList();
            } else if (e.key === 'Tab' && typeof config.onTab === 'function') {
                config.onTab(e);
            }
        });
    }

    return {
        clear: clearList
    };
}

document.addEventListener('DOMContentLoaded', function() {
    var moneySendBtn = document.getElementById('money-send-btn');
    var moneySendModal = document.getElementById('money-send-modal');
    var moneySendRecipientInput = document.getElementById('money-send-recipient');
    var moneySendRecipientList = document.getElementById('money-send-recipient-list');
    var moneySendRecipientClear = document.getElementById('money-send-recipient-clear');
    var moneySendAmount = document.getElementById('money-send-amount');
    var moneySendAmountClear = document.getElementById('money-send-amount-clear');
    var moneySendTeamEventWrap = document.getElementById('money-send-team-event-wrap');
    var moneySendTeamEventSelect = document.getElementById('money-send-team-event');
    var moneySendPassword = document.getElementById('money-send-password');
    var moneySendPasswordToggle = document.getElementById('money-send-password-toggle');
    var moneySendPasswordClear = document.getElementById('money-send-password-clear');
    var moneySendCancel = document.getElementById('money-send-cancel');
    var moneySendConfirm = document.getElementById('money-send-confirm');
    var moneySendCloseBtn = document.getElementById('money-send-close-btn');
    var moneySendError = document.getElementById('money-send-error');
    var isSimpleOrderMode = DRINKS_PAGE.simpleOrderMode;
    var modalMouseDownTarget = null;
    var selectedRecipientId = '';
    var selectedRecipientTeamEventId = '';
    var moneyRecipientTeamEventRequestId = 0;
    var spieltagCreateModal = document.getElementById('spieltag-create-modal');
    var spieltagCreateCloseBtn = document.getElementById('spieltag-create-close-btn');
    var spieltagCreateCancelBtn = document.getElementById('spieltag-create-cancel');
    var spieltagCreateConfirmBtn = document.getElementById('spieltag-create-confirm');
    var spieltagNewNameInput = document.getElementById('spieltag-new-name');
    var spieltagMembersRows = document.getElementById('spieltag-members-rows');
    var spieltagCreateError = document.getElementById('spieltag-create-error');
    var spieltagLastRealValue = '';

    function normalizeMoneyAmount(rawValue) {
        var value = String(rawValue || '').trim().replace(/\s+/g, '');
        if (value === '') return '';

        var hasComma = value.indexOf(',') !== -1;
        var hasDot = value.indexOf('.') !== -1;

        if (hasComma && hasDot) {
            if (value.lastIndexOf(',') > value.lastIndexOf('.')) {
                value = value.replace(/\./g, '').replace(',', '.');
            } else {
                value = value.replace(/,/g, '');
            }
        } else if (hasComma) {
            value = value.replace(',', '.');
        }

        return value;
    }

    function resetMoneySendTeamEvents() {
        selectedRecipientTeamEventId = '';
        if (moneySendTeamEventSelect) {
            moneySendTeamEventSelect.innerHTML = '';
        }
        if (moneySendTeamEventWrap) {
            moneySendTeamEventWrap.style.display = 'none';
        }
    }

    async function loadMoneyRecipientTeamEvents(recipientId) {
        resetMoneySendTeamEvents();
        if (!recipientId) {
            return;
        }

        var requestId = ++moneyRecipientTeamEventRequestId;
        try {
            var resp = await fetch(DRINKS_PAGE.urls.moneyRecipientTeamEvents + '?receiver_user_id=' + encodeURIComponent(recipientId), {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            var data = await resp.json();
            if (requestId !== moneyRecipientTeamEventRequestId) {
                return;
            }
            if (!resp.ok || !data || !data.success || !data.is_team) {
                return;
            }
            if (!Array.isArray(data.team_events) || data.team_events.length === 0) {
                if (moneySendError) moneySendError.textContent = 'Für dieses Team-Konto sind keine Spieltage vorhanden.';
                return;
            }

            data.team_events.forEach(function(teamEvent, idx) {
                if (!moneySendTeamEventSelect) return;
                var option = document.createElement('option');
                option.value = String(teamEvent.id || '');
                option.textContent = String(teamEvent.label || '');
                if (idx === 0) {
                    option.selected = true;
                    selectedRecipientTeamEventId = option.value;
                }
                moneySendTeamEventSelect.appendChild(option);
            });
            if (moneySendTeamEventWrap) {
                moneySendTeamEventWrap.style.display = '';
            }
        } catch (e) {
            if (requestId !== moneyRecipientTeamEventRequestId) {
                return;
            }
            if (moneySendError) moneySendError.textContent = 'Spieltage konnten nicht geladen werden.';
        }
    }

    function selectMoneyRecipient(recipient) {
        if (!recipient) return;
        var displayText = recipient.name + ' (' + recipient.email + ')';
        if (moneySendRecipientInput) moneySendRecipientInput.value = displayText;
        selectedRecipientId = String(recipient.uid || '');
        if (moneySendRecipientList) moneySendRecipientList.innerHTML = '';
        loadMoneyRecipientTeamEvents(selectedRecipientId);
    }

    function createMoneySendTransferKey() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }
        var b = new Uint8Array(16);
        if (window.crypto && window.crypto.getRandomValues) {
            window.crypto.getRandomValues(b);
        } else {
            for (var i = 0; i < 16; i++) { b[i] = Math.floor(Math.random() * 256); }
        }
        b[6] = (b[6] & 0x0f) | 0x40;
        b[8] = (b[8] & 0x3f) | 0x80;
        var h = Array.prototype.map.call(b, function(x) { return ('0' + x.toString(16)).slice(-2); }).join('');
        return h.substr(0, 8) + '-' + h.substr(8, 4) + '-' + h.substr(12, 4) + '-' + h.substr(16, 4) + '-' + h.substr(20, 12);
    }

    function closeMoneySendModal() {
        if (!moneySendModal) return;
        moneySendModal.style.display = 'none';
        if (moneySendError) moneySendError.textContent = '';
        if (moneySendAmount) moneySendAmount.value = '';
        if (moneySendPassword) moneySendPassword.value = '';
        if (moneySendRecipientInput) moneySendRecipientInput.value = '';
        selectedRecipientId = '';
        resetMoneySendTeamEvents();
        if (moneySendRecipientList) moneySendRecipientList.innerHTML = '';
    }

    var moneyRecipientAutocomplete = setupBookingRecipientAutocomplete({
        input: moneySendRecipientInput,
        list: moneySendRecipientList,
        getMatches: function(filter) {
            return getBookingRecipientMatches(filter);
        },
        onInputReset: function() {
            selectedRecipientId = '';
            resetMoneySendTeamEvents();
        },
        onSelect: selectMoneyRecipient,
        onEnterWithoutMatches: function(e) {
            e.preventDefault();
            if (moneySendAmount) {
                moneySendAmount.focus();
            }
        },
        onTab: function(e) {
            if (!e.shiftKey && moneySendAmount) {
                e.preventDefault();
                moneySendAmount.focus();
            }
        },
        listStyle: 'position:absolute; background:#fff; border:1px solid #ddd; width:100%; max-height:150px; overflow-y:auto; box-shadow:0 4px 12px rgba(0,0,0,0.12); border-radius:6px; z-index:10;',
        itemStyle: 'padding:10px 12px; cursor:pointer; border-bottom:1px solid #f0f0f0; font-size:14px; min-height:40px; display:flex; align-items:center; touch-action:manipulation;'
    });

    function openMoneySendModal() {
        if (!moneySendModal) return;
        if (moneySendError) moneySendError.textContent = '';
        if (moneySendRecipientInput) {
            moneySendRecipientInput.value = '';
            setTimeout(function() { moneySendRecipientInput.focus(); }, 100);
        }
        selectedRecipientId = '';
        resetMoneySendTeamEvents();
        moneyRecipientAutocomplete.clear();
        if (moneySendAmount) moneySendAmount.value = '';
        if (moneySendPassword) moneySendPassword.value = '';
        moneySendModal.style.display = 'flex';
    }

    if (moneySendRecipientClear) {
        moneySendRecipientClear.addEventListener('click', function(e) {
            e.preventDefault();
            if (moneySendRecipientInput) {
                moneySendRecipientInput.value = '';
                moneySendRecipientInput.focus();
            }
            selectedRecipientId = '';
            resetMoneySendTeamEvents();
            moneyRecipientAutocomplete.clear();
        });
    }

    if (moneySendTeamEventSelect) {
        moneySendTeamEventSelect.addEventListener('change', function() {
            selectedRecipientTeamEventId = moneySendTeamEventSelect.value || '';
        });
    }

    if (moneySendAmount) {
        moneySendAmount.addEventListener('keydown', function(e) {
            if (e.key === 'Tab' && e.shiftKey) {
                e.preventDefault();
                if (moneySendRecipientInput) {
                    moneySendRecipientInput.focus();
                }
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (moneySendConfirm) {
                    moneySendConfirm.click();
                }
            }
        });
    }

    if (moneySendAmountClear) {
        moneySendAmountClear.addEventListener('click', function(e) {
            e.preventDefault();
            if (moneySendAmount) {
                moneySendAmount.value = '';
                moneySendAmount.focus();
            }
        });
    }

    if (moneySendPasswordClear) {
        moneySendPasswordClear.addEventListener('click', function(e) {
            e.preventDefault();
            if (moneySendPassword) {
                moneySendPassword.value = '';
                moneySendPassword.focus();
            }
        });
    }


    if (moneySendPasswordToggle) {
        moneySendPasswordToggle.addEventListener('click', function(e) {
            e.preventDefault();
            if (!moneySendPassword) return;
            var isPassword = moneySendPassword.getAttribute('type') === 'password';
            moneySendPassword.setAttribute('type', isPassword ? 'text' : 'password');
            moneySendPasswordToggle.style.color = isPassword ? '#1769aa' : '#666';
            moneySendPassword.focus();
        });
    }

    if (moneySendBtn) {
        moneySendBtn.addEventListener('click', function(e) {
            e.preventDefault();
            openMoneySendModal();
        });
    }

    if (moneySendCloseBtn) {
        moneySendCloseBtn.addEventListener('click', function(e) {
            e.preventDefault();
            closeMoneySendModal();
        });
    }

    if (moneySendCancel) {
        moneySendCancel.addEventListener('click', function() {
            closeMoneySendModal();
        });
    }

    if (moneySendModal) {
        moneySendModal.addEventListener('mousedown', function(e) {
            modalMouseDownTarget = e.target;
        });

        moneySendModal.addEventListener('click', function(e) {
            if (e.target === moneySendModal && modalMouseDownTarget === moneySendModal) {
                closeMoneySendModal();
            }
            modalMouseDownTarget = null;
        });
    }

    if (moneySendConfirm) {
        moneySendConfirm.addEventListener('click', function() {
            if (!moneySendAmount) return;
            var receiverUserId = selectedRecipientId || '';
            var amountRaw = normalizeMoneyAmount(moneySendAmount.value || '');
            var amount = Number(amountRaw);
            var passwordRaw = moneySendPassword ? (moneySendPassword.value || '') : '';

            if (!receiverUserId) {
                if (moneySendError) moneySendError.textContent = 'Bitte Empfänger auswählen.';
                return;
            }
            if (moneySendTeamEventWrap && moneySendTeamEventWrap.style.display !== 'none' && !selectedRecipientTeamEventId) {
                if (moneySendError) moneySendError.textContent = 'Bitte Spieltag auswählen.';
                return;
            }
            if (!amount || amount <= 0) {
                if (moneySendError) moneySendError.textContent = 'Betrag muss positiv sein.';
                return;
            }
            if (isSimpleOrderMode && !passwordRaw) {
                if (moneySendError) moneySendError.textContent = 'Bitte Passwort eingeben.';
                if (moneySendPassword) moneySendPassword.focus();
                return;
            }

            // One idempotency key per distinct transfer: a retry after an error reuses it
            // (the server then reports "already done" instead of sending twice); changed
            // input gets a new key.
            var moneySendSignature = [receiverUserId, amountRaw, selectedRecipientTeamEventId || ''].join('|');
            if (!window._moneySendTransfer || window._moneySendTransfer.signature !== moneySendSignature) {
                window._moneySendTransfer = { signature: moneySendSignature, key: createMoneySendTransferKey() };
            }

            moneySendConfirm.disabled = true;
            if (moneySendError) moneySendError.textContent = '';
            fetch(DRINKS_PAGE.urls.sendMoney, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin',
                body: 'receiver_user_id=' + encodeURIComponent(receiverUserId)
                    + '&amount=' + encodeURIComponent(amountRaw)
                    + '&team_event_id=' + encodeURIComponent(selectedRecipientTeamEventId || '')
                    + '&transfer_key=' + encodeURIComponent(window._moneySendTransfer.key)
                    + (isSimpleOrderMode ? ('&password=' + encodeURIComponent(passwordRaw)) : '')
            })
            .then(function(resp) {
                return resp.text().then(function(text) {
                    var data = null;
                    try { data = JSON.parse(text); } catch (e) {}
                    return { ok: resp.ok, data: data };
                });
            })
            .then(function(result) {
                moneySendConfirm.disabled = false;
                if (!result.ok || !result.data || !result.data.success) {
                    var msg = (result.data && result.data.error) ? result.data.error : 'Senden fehlgeschlagen.';
                    if (moneySendError) moneySendError.textContent = msg;
                    return;
                }
                window._moneySendTransfer = null;
                closeMoneySendModal();
                window.location.reload();
            })
            .catch(function() {
                moneySendConfirm.disabled = false;
                if (moneySendError) moneySendError.textContent = 'Netzwerkfehler beim Senden.';
            });
        });
    }
});
