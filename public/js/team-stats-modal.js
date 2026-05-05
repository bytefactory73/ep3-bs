(function(global) {
    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/\"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function formatCurrency(value) {
        return Number(value || 0).toLocaleString('de-DE', {
            style: 'currency',
            currency: 'EUR'
        });
    }

    function amountColor(value) {
        var amount = Number(value || 0);
        if (amount > 0) return '#2e7d32';
        if (amount < 0) return '#c62828';
        return '#333';
    }

    function byId(id) {
        return id ? document.getElementById(id) : null;
    }

    function createTeamStatsModal(config) {
        var state = {
            currentEventId: 0,
            memberCandidates: [],
            selectedMemberId: 0,
            selectedIndex: -1,
            teamUid: 0,
            teamAlias: config.initialTeamAlias || ''
        };

        function refs() {
            return {
                modal: byId(config.modalId),
                content: byId(config.contentId),
                balanceHeader: byId(config.balanceHeaderId),
                select: byId(config.selectId),
                title: byId(config.titleId),
                memberInput: byId(config.memberInputId),
                memberList: byId(config.memberListId),
                memberAddBtn: byId(config.memberAddBtnId),
                memberClearBtn: byId(config.memberClearBtnId)
            };
        }

        function setTitle() {
            if (!config.titleId) return;
            var titleEl = byId(config.titleId);
            if (!titleEl) return;
            if (typeof config.getTitle === 'function') {
                titleEl.textContent = config.getTitle(state.teamAlias);
            }
        }

        function resetBalanceHeader() {
            var r = refs();
            if (r.balanceHeader) {
                r.balanceHeader.innerHTML = 'Gesamtsaldo Konto: -';
            }
        }

        function selectMemberCandidate(candidate) {
            var r = refs();
            if (!candidate || !candidate.uid) return;
            if (r.memberInput) {
                r.memberInput.value = String(candidate.name || '') + ' (' + String(candidate.email || '') + ')';
            }
            state.selectedMemberId = parseInt(candidate.uid, 10) || 0;
            state.selectedIndex = -1;
            if (r.memberList) {
                r.memberList.innerHTML = '';
                r.memberList.removeAttribute('data-matches');
            }
        }

        function setHighlightedIndex(list, index) {
            var items = list ? list.querySelectorAll('[data-index]') : [];
            items.forEach(function(item, idx) {
                item.style.background = (idx === index) ? '#e3f2fd' : 'transparent';
            });
        }

        function renderMemberList(filter) {
            var r = refs();
            var list = r.memberList;
            if (!list) return;
            list.innerHTML = '';
            list.removeAttribute('data-matches');
            state.selectedIndex = -1;

            var term = String(filter || '').trim().toLowerCase();
            if (!term) return;

            var usedIds = [];
            var rows = document.querySelectorAll('.' + config.rowClass + '[data-member-uid]');
            rows.forEach(function(row) {
                var uid = parseInt(row.getAttribute('data-member-uid') || '0', 10);
                if (uid > 0 && usedIds.indexOf(uid) === -1) {
                    usedIds.push(uid);
                }
            });

            var matches = state.memberCandidates.filter(function(candidate) {
                var uid = parseInt(candidate.uid || 0, 10);
                if (uid <= 0 || usedIds.indexOf(uid) !== -1) {
                    return false;
                }
                var name = String(candidate.name || '').toLowerCase();
                var email = String(candidate.email || '').toLowerCase();
                return name.indexOf(term) !== -1 || email.indexOf(term) !== -1;
            });
            if (matches.length === 0) return;

            var wrap = document.createElement('div');
            wrap.style.cssText = 'background:#fff; border:1px solid #ddd; max-height:160px; overflow-y:auto; box-shadow:0 4px 12px rgba(0,0,0,0.12); border-radius:6px;';
            matches.forEach(function(candidate, idx) {
                var item = document.createElement('div');
                item.setAttribute('data-index', String(idx));
                item.textContent = String(candidate.name || '') + ' (' + String(candidate.email || '') + ')';
                item.style.cssText = 'padding:10px 12px; cursor:pointer; border-bottom:1px solid #f0f0f0; font-size:14px; min-height:40px; display:flex; align-items:center;';
                item.addEventListener('mousedown', function(e) {
                    e.preventDefault();
                    selectMemberCandidate(candidate);
                });
                wrap.appendChild(item);
            });
            list.appendChild(wrap);
            list.setAttribute('data-matches', JSON.stringify(matches));
        }

        function buildStatsHtml(data, canManageMembers) {
            var rows = Array.isArray(data.rows) ? data.rows : [];
            var members = Array.isArray(data.members) ? data.members : [];
            var html = '';

            if (rows.length === 0) {
                html += '<div style="color:#555;">Keine Eintraege fuer diesen Spieltag.</div>';
            } else {
                html += '<table class="default-table" style="width:100%; margin:0;">';
                html += '<tr style="background:#e3f0fa;">';
                html += '<th style="text-align:left; padding:6px 8px;">Artikel</th>';
                html += '<th style="text-align:right; padding:6px 8px;">Menge</th>';
                html += '<th style="text-align:right; padding:6px 8px;">Einzelpreis</th>';
                html += '<th style="text-align:right; padding:6px 8px;">Gesamtpreis</th>';
                html += '</tr>';

                var lastCategory = null;
                for (var i = 0; i < rows.length; i++) {
                    var row = rows[i] || {};
                    var category = row.category || '';
                    if (category !== lastCategory) {
                        lastCategory = category;
                        if (category !== '') {
                            html += '<tr style="background:#f0f4fa;">';
                            html += '<td colspan="4" style="font-weight:bold; color:#1769aa; padding:6px 8px 4px 8px;">' + escapeHtml(category) + '</td>';
                            html += '</tr>';
                        }
                    }
                    html += '<tr>';
                    html += '<td style="padding:6px 8px;">' + escapeHtml(row.article || '') + '</td>';
                    html += '<td style="text-align:right; padding:6px 8px;">' + escapeHtml(row.quantity || 0) + '</td>';
                    html += '<td style="text-align:right; padding:6px 8px; color:' + amountColor(row.single_price) + ';">' + formatCurrency(row.single_price) + '</td>';
                    html += '<td style="text-align:right; padding:6px 8px; color:' + amountColor(row.total_price) + ';">' + formatCurrency(row.total_price) + '</td>';
                    html += '</tr>';
                }

                html += '<tr style="font-weight:bold; background:#f5faff;">';
                html += '<td colspan="3" style="text-align:right; padding:6px 8px;">Gesamtsumme Ausgaben</td>';
                html += '<td style="text-align:right; padding:6px 8px; color:' + amountColor(data.total_sum) + ';">' + formatCurrency(data.total_sum) + '</td>';
                html += '</tr>';
                html += '</table>';
            }

            html += '<div style="margin-top:14px;">';
            html += '<h3 style="margin:0 0 8px 0; color:#1769aa;">Mitglieder und Beiträge</h3>';
            html += '<table class="default-table" style="width:100%; margin:0; margin-bottom:10px;">';
            html += '<tr style="background:#e3f0fa;">';
            html += '<th style="text-align:left; padding:6px 8px;">Mitglied</th>';
            html += '<th style="text-align:right; padding:6px 8px;">Beiträge gesamt</th>';
            html += '<th style="text-align:right; padding:6px 8px;">Zu zahlen</th>';
            html += '<th style="text-align:right; padding:6px 8px;">Rest</th>';
            if (canManageMembers) {
                html += '<th style="text-align:center; padding:6px 8px; width:170px;">Aktion</th>';
            }
            html += '</tr>';

            var membersTotalSum = 0;
            var activeMembersCount = 0;
            for (var mi = 0; mi < members.length; mi++) {
                if (members[mi] && members[mi].is_member) {
                    activeMembersCount++;
                }
            }
            var totalExpensesAbs = Math.abs(parseFloat(data.total_sum || 0));
            var amountPerMember = activeMembersCount > 0 ? (totalExpensesAbs / activeMembersCount) : 0;
            if (members.length === 0) {
                html += '<tr><td colspan="' + (canManageMembers ? '5' : '4') + '" style="padding:8px; color:#666;">Keine Mitglieder hinterlegt.</td></tr>';
            } else {
                for (var m = 0; m < members.length; m++) {
                    var member = members[m] || {};
                    var memberUid = parseInt(member.uid || 0, 10);
                    var isMember = !!member.is_member;
                    var memberTotalPaid = parseFloat(member.total_paid || 0);
                    membersTotalSum += memberTotalPaid;
                    html += '<tr class="' + config.rowClass + '" data-member-uid="' + memberUid + '">';
                    html += '<td style="padding:6px 8px;">' + escapeHtml(member.name || '') + ' (' + escapeHtml(member.email || '') + ')</td>';
                    html += '<td style="text-align:right; padding:6px 8px; color:' + amountColor(memberTotalPaid) + ';">' + formatCurrency(memberTotalPaid) + '</td>';
                    if (isMember) {
                        var restAmount = amountPerMember - memberTotalPaid;
                        html += '<td style="text-align:right; padding:6px 8px; color:' + amountColor(amountPerMember) + ';">' + formatCurrency(amountPerMember) + '</td>';
                        html += '<td style="text-align:right; padding:6px 8px; color:' + amountColor(restAmount) + ';">' + formatCurrency(restAmount) + '</td>';
                    } else {
                        html += '<td style="text-align:right; padding:6px 8px; color:#999;">-</td>';
                        html += '<td style="text-align:right; padding:6px 8px; color:#999;">-</td>';
                    }
                    if (canManageMembers) {
                        if (isMember) {
                            html += '<td style="text-align:center; padding:6px 8px;"><button type="button" class="mini-button ' + config.removeButtonClass + '" data-member-uid="' + memberUid + '">Entfernen</button></td>';
                        } else {
                            html += '<td style="text-align:center; padding:6px 8px;"><button type="button" class="mini-button ' + config.addDirectButtonClass + '" data-member-uid="' + memberUid + '">Mitglied +</button></td>';
                        }
                    }
                    html += '</tr>';
                }
                html += '<tr style="font-weight:bold; background:#f5faff;">';
                html += '<td colspan="' + (canManageMembers ? '4' : '3') + '" style="text-align:right; padding:6px 8px;">Gesamtsumme Einzahlungen</td>';
                html += '<td style="text-align:right; padding:6px 8px; color:' + amountColor(membersTotalSum) + ';">' + formatCurrency(membersTotalSum) + '</td>';
                html += '</tr>';
            }
            html += '</table>';

            var grandTotal = (data.total_sum || 0) + membersTotalSum;
            html += '<div style="margin-top:8px; padding:8px 6px; border-top:2px solid #1769aa; font-weight:bold; color:#1769aa; display:flex; justify-content:flex-end; gap:20px;">';
            html += '<span>Gesamtsumme (Ausgaben + Einzahlungen):</span>';
            html += '<span style="color:' + amountColor(grandTotal) + ';">' + formatCurrency(grandTotal) + '</span>';
            html += '</div>';
            if (canManageMembers) {
                var manageContainerStyle = config.manageContainerStyle || 'position:relative; display:flex; align-items:center; gap:6px; max-width:560px;';
                html += '<div style="' + manageContainerStyle + '">';
                html += '<input type="text" id="' + config.memberInputId + '" autocomplete="off" placeholder="Teilnehmer suchen..." style="flex:1; min-width:0; padding:6px 10px; border:1px solid #ccc; border-radius:12px; font-size:13px; min-height:28px;">';
                html += '<button type="button" id="' + config.memberClearBtnId + '" title="Feld leeren" style="background:none; border:none; padding:4px; font-size:18px; color:#aaa; cursor:pointer; line-height:1; min-height:28px; min-width:28px;">&times;</button>';
                html += '<button type="button" id="' + config.memberAddBtnId + '" class="mini-button" style="min-height:28px; padding:6px 12px; font-size:13px;">Hinzufügen</button>';
                html += '<div id="' + config.memberListId + '" style="position:absolute; top:100%; left:0; right:94px; z-index:20; margin-top:4px;"></div>';
                html += '</div>';
            }
            html += '</div>';

            return html;
        }

        async function updateMember(operation, memberUserId) {
            var requestData = config.buildMemberUpdateRequest(operation, memberUserId, state);
            var resp = await fetch(requestData.url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: requestData.body
            });
            var data = await resp.json();
            if (!resp.ok || !data || !data.success) {
                throw new Error((data && data.error) ? data.error : 'Fehler beim Speichern der Mitglieder.');
            }
            await loadCurrentSelection();
        }

        function bindMemberControls(canManageMembers) {
            if (!canManageMembers) return;
            var r = refs();

            if (r.memberInput) {
                r.memberInput.addEventListener('input', function() {
                    state.selectedMemberId = 0;
                    renderMemberList(r.memberInput.value || '');
                });
                r.memberInput.addEventListener('keydown', function(e) {
                    var raw = r.memberList ? r.memberList.getAttribute('data-matches') : '';
                    var matches = raw ? JSON.parse(raw) : [];
                    if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        if (matches.length > 0) {
                            state.selectedIndex = Math.min(state.selectedIndex + 1, matches.length - 1);
                            setHighlightedIndex(r.memberList, state.selectedIndex);
                        }
                    } else if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        if (matches.length > 0) {
                            state.selectedIndex = Math.max(state.selectedIndex - 1, -1);
                            setHighlightedIndex(r.memberList, state.selectedIndex);
                        }
                    } else if (e.key === 'Enter') {
                        e.preventDefault();
                        if (matches.length > 0) {
                            var idx = state.selectedIndex >= 0 ? state.selectedIndex : 0;
                            selectMemberCandidate(matches[idx]);
                        }
                    }
                });
                r.memberInput.addEventListener('blur', function() {
                    setTimeout(function() {
                        if (r.memberList) {
                            r.memberList.innerHTML = '';
                            r.memberList.removeAttribute('data-matches');
                        }
                    }, 150);
                });
            }

            if (r.memberClearBtn && r.memberInput) {
                r.memberClearBtn.addEventListener('click', function() {
                    r.memberInput.value = '';
                    state.selectedMemberId = 0;
                    state.selectedIndex = -1;
                    if (r.memberList) {
                        r.memberList.innerHTML = '';
                        r.memberList.removeAttribute('data-matches');
                    }
                    r.memberInput.focus();
                });
            }

            if (r.memberAddBtn) {
                r.memberAddBtn.addEventListener('click', async function() {
                    var uid = parseInt(state.selectedMemberId || 0, 10);
                    if (!uid) {
                        alert('Bitte zuerst einen Teilnehmer auswählen.');
                        return;
                    }
                    try {
                        await updateMember('add', uid);
                    } catch (e) {
                        alert(e && e.message ? e.message : 'Fehler beim Hinzufügen des Teilnehmers.');
                    }
                });
            }

            document.querySelectorAll('.' + config.removeButtonClass).forEach(function(btn) {
                btn.addEventListener('click', async function() {
                    var uid = parseInt(btn.getAttribute('data-member-uid') || '0', 10);
                    if (!uid) return;
                    try {
                        await updateMember('remove', uid);
                    } catch (e) {
                        alert(e && e.message ? e.message : 'Fehler beim Entfernen des Teilnehmers.');
                    }
                });
            });

            document.querySelectorAll('.' + config.addDirectButtonClass).forEach(function(btn) {
                btn.addEventListener('click', async function() {
                    var uid = parseInt(btn.getAttribute('data-member-uid') || '0', 10);
                    if (!uid) return;
                    try {
                        await updateMember('add', uid);
                    } catch (e) {
                        alert(e && e.message ? e.message : 'Fehler beim Hinzufuegen des Teilnehmers.');
                    }
                });
            });
        }

        async function load(spieltag) {
            var r = refs();
            if (!r.content) return;

            r.content.innerHTML = '<div style="color:#1769aa;">Lade Daten...</div>';

            try {
                if (typeof config.beforeLoad === 'function') {
                    config.beforeLoad(spieltag, state, r);
                }

                var url = config.buildStatsUrl(spieltag, state, r);
                var resp = await fetch(url, {
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                var data = await resp.json();
                if (!resp.ok || !data || !data.success) {
                    throw new Error((data && data.error) ? data.error : 'Fehler beim Laden der Statistik.');
                }

                state.currentEventId = parseInt(data.team_event_id || 0, 10);
                state.memberCandidates = Array.isArray(data.member_candidates) ? data.member_candidates : [];
                state.selectedMemberId = 0;
                state.selectedIndex = -1;

                if (typeof config.afterLoadData === 'function') {
                    config.afterLoadData(data, state, r);
                }

                var accountBalance = Number(data.account_balance || 0);
                if (r.balanceHeader) {
                    r.balanceHeader.innerHTML = 'Gesamtsaldo Konto: <span style="color:' + amountColor(accountBalance) + ';">' + formatCurrency(accountBalance) + '</span>';
                }

                var canManageMembers = !!data.can_manage_members;
                r.content.innerHTML = buildStatsHtml(data, canManageMembers);
                bindMemberControls(canManageMembers);
            } catch (err) {
                r.content.innerHTML = '<div style="color:#d32f2f;">' + escapeHtml(err && err.message ? err.message : 'Fehler beim Laden der Statistik.') + '</div>';
            }
        }

        async function loadCurrentSelection() {
            var r = refs();
            var selectedValue = r.select ? (r.select.value || '') : '';
            await load(selectedValue);
        }

        async function open() {
            var r = refs();
            if (!r.modal || !r.content) return;

            var args = Array.prototype.slice.call(arguments);
            r.modal.style.display = 'flex';
            resetBalanceHeader();
            setTitle();

            if (typeof config.onOpen === 'function') {
                config.onOpen(args, state, r);
            }

            var selectedSpieltag = '';
            if (typeof config.getInitialSpieltag === 'function') {
                selectedSpieltag = config.getInitialSpieltag(args, state, r) || '';
            } else if (r.select) {
                selectedSpieltag = r.select.value || '';
            }
            await load(selectedSpieltag);
        }

        function close() {
            var r = refs();
            if (r.modal) {
                r.modal.style.display = 'none';
            }
        }

        function bind() {
            var r = refs();
            if (r.select) {
                r.select.addEventListener('change', function() {
                    load(r.select.value || '');
                });
            }
        }

        bind();

        return {
            open: open,
            close: close,
            load: load,
            state: state,
            setTitle: setTitle
        };
    }

    global.createTeamStatsModal = createTeamStatsModal;
})(window);
