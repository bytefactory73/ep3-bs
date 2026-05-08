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

    function getInitials(name) {
        var value = String(name || '').trim();
        if (!value) return '?';
        var parts = value.split(/\s+/).filter(function(part) { return part; });
        if (parts.length === 0) return '?';
        if (parts.length === 1) {
            return parts[0].slice(0, 2).toUpperCase();
        }
        return (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
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
            teamAlias: config.initialTeamAlias || '',
            isCurrentEventClosed: false
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
            var memberSharesByUid = {};
            var canEditRelevance = canManageMembers && typeof config.buildOrderRelevanceUpdateRequest === 'function';
            var relevanceTriggerClass = config.orderRelevanceTriggerClass || 'team-stats-order-relevance-trigger';

            state.relevanceEditorRows = {};

            if (rows.length === 0) {
                html += '<div style="color:#555;">Keine Eintraege fuer diesen Spieltag.</div>';
            } else {
                html += '<table class="default-table" style="width:100%; margin:0;">';
                html += '<tr style="background:#e3f0fa;">';
                html += '<th style="text-align:left; padding:6px 8px;">Artikel</th>';
                html += '<th style="text-align:left; padding:6px 8px;">Relevant für</th>';
                html += '<th style="text-align:right; padding:6px 8px;">Menge</th>';
                html += '<th style="text-align:right; padding:6px 8px;">Einzelpreis</th>';
                html += '<th style="text-align:right; padding:6px 8px;">Anteil p.P.</th>';
                html += '<th style="text-align:right; padding:6px 8px;">Gesamtpreis</th>';
                html += '</tr>';

                var activeMembers = members.filter(function(member) {
                    return !!(member && member.is_member);
                });
                var activeMemberIds = activeMembers.map(function(member) {
                    return parseInt(member.uid || 0, 10);
                }).filter(function(uid) {
                    return uid > 0;
                });

                var lastCategory = null;
                for (var i = 0; i < rows.length; i++) {
                    var row = rows[i] || {};
                    var category = row.category || '';
                    if (category !== lastCategory) {
                        lastCategory = category;
                        if (category !== '') {
                            html += '<tr style="background:#f0f4fa;">';
                            html += '<td colspan="6" style="font-weight:bold; color:#1769aa; padding:6px 8px 4px 8px;">' + escapeHtml(category) + '</td>';
                            html += '</tr>';
                        }
                    }

                    var relevantMembers = Array.isArray(row.relevant_members) ? row.relevant_members : [];
                    var relevantIds = [];
                    for (var ri = 0; ri < relevantMembers.length; ri++) {
                        var relevantMember = relevantMembers[ri] || {};
                        var relevantUid = parseInt(relevantMember.uid || 0, 10);
                        if (relevantUid > 0) {
                            relevantIds.push(relevantUid);
                            memberSharesByUid[relevantUid] = (memberSharesByUid[relevantUid] || 0) + parseFloat(row.share_per_member || 0);
                        }
                    }
                    relevantIds = Array.from(new Set(relevantIds));

                    var allSelected = activeMemberIds.length > 0 && relevantIds.length === activeMemberIds.length;
                    var noneSelected = relevantIds.length === 0;
                    var rowKey = String(row.drink_id || '') + '|' + String(row.unit_price || '');
                    state.relevanceEditorRows[rowKey] = {
                        drinkId: parseInt(row.drink_id || 0, 10),
                        unitPrice: parseFloat(row.unit_price || 0),
                        article: String(row.article || ''),
                        activeMembers: activeMembers.map(function(member) {
                            return {
                                uid: parseInt(member.uid || 0, 10),
                                name: String(member.name || '')
                            };
                        }),
                        selectedIds: relevantIds
                    };

                    var labelsHtml = '';
                    if (allSelected) {
                        labelsHtml = '<span style="display:inline-flex; align-items:center; padding:2px 8px; border-radius:999px; background:#e3f2fd; color:#0d47a1; font-size:12px; font-weight:700;">ALLE</span>';
                    } else if (noneSelected) {
                        labelsHtml = '<span style="display:inline-flex; align-items:center; padding:2px 8px; border-radius:999px; background:#f3f4f6; color:#5f6368; font-size:12px; font-weight:700;">NIEMAND</span>';
                    } else {
                        var chips = [];
                        for (var ci = 0; ci < relevantMembers.length; ci++) {
                            var chipMember = relevantMembers[ci] || {};
                            var chipName = String(chipMember.name || '').trim();
                            if (!chipName) continue;
                            chips.push('<span title="' + escapeHtml(chipName) + '" style="display:inline-flex; align-items:center; justify-content:center; width:24px; height:24px; border-radius:50%; background:#d7ecff; color:#0d47a1; font-size:11px; font-weight:700;">' + escapeHtml(getInitials(chipName)) + '</span>');
                        }
                        labelsHtml = chips.join(' ');
                    }

                    html += '<tr>';
                    html += '<td style="padding:6px 8px;">' + escapeHtml(row.article || '') + '</td>';
                    html += '<td style="padding:6px 8px;">';
                    if (canEditRelevance && parseInt(row.drink_id || 0, 10) > 0 && parseFloat(row.unit_price || 0) > 0) {
                        html += '<button type="button" class="' + relevanceTriggerClass + '" data-row-key="' + escapeHtml(rowKey) + '" style="border:none; background:transparent; cursor:pointer; padding:2px; text-align:left; display:inline-flex; align-items:center; gap:6px; flex-wrap:wrap;">' + labelsHtml + '</button>';
                    } else {
                        html += labelsHtml || '-';
                    }
                    html += '</td>';
                    html += '<td style="text-align:right; padding:6px 8px;">' + escapeHtml(row.quantity || 0) + '</td>';
                    html += '<td style="text-align:right; padding:6px 8px; color:' + amountColor(row.single_price) + ';">' + formatCurrency(row.single_price) + '</td>';
                    html += '<td style="text-align:right; padding:6px 8px; color:' + amountColor(row.share_per_member) + ';">' + formatCurrency(row.share_per_member) + '</td>';
                    html += '<td style="text-align:right; padding:6px 8px; color:' + amountColor(row.total_price) + ';">' + formatCurrency(row.total_price) + '</td>';
                    html += '</tr>';
                }

                html += '<tr style="font-weight:bold; background:#f5faff;">';
                html += '<td colspan="5" style="text-align:right; padding:6px 8px;">Gesamtsumme Ausgaben</td>';
                html += '<td style="text-align:right; padding:6px 8px; color:' + amountColor(data.total_sum) + ';">' + formatCurrency(data.total_sum) + '</td>';
                html += '</tr>';
                html += '</table>';
            }

            html += '<div style="margin-top:14px;">';
            html += '<h3 style="margin:0 0 8px 0; color:#1769aa;">Mitglieder und Beiträge</h3>';
            html += '<table class="default-table" style="width:100%; margin:0; margin-bottom:10px;">';
            html += '<tr style="background:#e3f0fa;">';
            html += '<th style="text-align:left; padding:6px 8px;">Mitglied</th>';
            html += '<th style="text-align:right; padding:6px 8px;">Bereits gezahlt</th>';
            html += '<th style="text-align:right; padding:6px 8px;">Zu zahlen</th>';
            html += '<th style="text-align:right; padding:6px 8px;">Rest</th>';
            if (canManageMembers) {
                html += '<th style="text-align:center; padding:6px 8px; width:170px;">Aktion</th>';
            }
            html += '</tr>';

            var membersTotalSum = 0;
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
                    var memberDisplay = escapeHtml(member.name || '');
                    var depositComment = member.deposit_comment ? escapeHtml(member.deposit_comment.trim()) : '';
                    if (depositComment) {
                        memberDisplay += ' - ' + depositComment;
                    }
                    html += '<td style="padding:6px 8px;">' + memberDisplay + '</td>';
                    html += '<td style="text-align:right; padding:6px 8px; color:' + amountColor(memberTotalPaid) + ';">' + formatCurrency(memberTotalPaid) + '</td>';
                    if (isMember) {
                        var memberDue = memberSharesByUid[memberUid] || 0;
                        var restAmount = memberDue - memberTotalPaid;
                        html += '<td style="text-align:right; padding:6px 8px; color:' + amountColor(memberDue) + ';">' + formatCurrency(memberDue) + '</td>';
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

            if (data && data.can_close_team_event) {
                html += '<div style="margin-top:14px; padding-top:10px; border-top:1px solid #e4edf6; display:flex; justify-content:flex-end;">';
                if (data.team_event_closed) {
                    html += '<span style="font-weight:600; color:#607d8b;">Abrechnung ist bereits beendet.</span>';
                } else {
                    html += '<button type="button" id="' + (config.closeEventBtnId || 'team-stats-close-event-btn') + '" class="default-button mini-button" style="background:#d32f2f; border-color:#d32f2f; color:#fff;">Abrechnung Beenden</button>';
                }
                html += '</div>';
            }

            return html;
        }

        async function closeCurrentTeamEvent() {
            if (typeof config.buildCloseEventRequest !== 'function') {
                throw new Error('Close request is not configured.');
            }
            if (!state.currentEventId) {
                throw new Error('Kein Spieltag ausgewählt.');
            }

            var requestData = config.buildCloseEventRequest(state);
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
                throw new Error((data && data.error) ? data.error : 'Fehler beim Schließen der Abrechnung.');
            }

            if (typeof config.onTeamEventClosed === 'function') {
                config.onTeamEventClosed(state);
            }
            await loadCurrentSelection();
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

        async function updateOrderRelevance(drinkId, unitPrice, memberUserIds) {
            if (typeof config.buildOrderRelevanceUpdateRequest !== 'function') {
                throw new Error('Relevanz-Update ist nicht konfiguriert.');
            }
            var requestData = config.buildOrderRelevanceUpdateRequest(drinkId, unitPrice, memberUserIds, state);
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
                throw new Error((data && data.error) ? data.error : 'Fehler beim Speichern der Relevanz.');
            }
            await loadCurrentSelection();
        }

        function ensureOrderRelevancePopup() {
            var popupId = config.orderRelevancePopupId || 'team-stats-order-relevance-popup';
            var overlay = byId(popupId);
            if (overlay) {
                return {
                    overlay: overlay,
                    title: byId(popupId + '-title'),
                    list: byId(popupId + '-list'),
                    saveBtn: byId(popupId + '-save'),
                    cancelBtn: byId(popupId + '-cancel'),
                    allBtn: byId(popupId + '-all'),
                    noneBtn: byId(popupId + '-none')
                };
            }

            overlay = document.createElement('div');
            overlay.id = popupId;
            overlay.style.cssText = 'display:none; position:fixed; inset:0; background:rgba(0,0,0,0.38); z-index:100001; align-items:center; justify-content:center; padding:16px;';
            overlay.innerHTML = '' +
                '<div style="width:min(420px, 94vw); background:#fff; border-radius:12px; box-shadow:0 12px 32px rgba(0,0,0,0.22); overflow:hidden;">' +
                '  <div style="padding:14px 16px; border-bottom:1px solid #e6edf5; font-weight:700; color:#1769aa;" id="' + popupId + '-title"></div>' +
                '  <div style="padding:12px 16px; display:flex; gap:8px;">' +
                '    <button type="button" class="mini-button" id="' + popupId + '-all">Alle</button>' +
                '    <button type="button" class="mini-button" id="' + popupId + '-none">Niemand</button>' +
                '  </div>' +
                '  <div id="' + popupId + '-list" style="padding:0 16px 12px 16px; max-height:280px; overflow:auto;"></div>' +
                '  <div style="padding:12px 16px; border-top:1px solid #e6edf5; display:flex; justify-content:flex-end; gap:8px;">' +
                '    <button type="button" class="mini-button" id="' + popupId + '-cancel">Abbrechen</button>' +
                '    <button type="button" class="default-button mini-button" id="' + popupId + '-save">Speichern</button>' +
                '  </div>' +
                '</div>';
            document.body.appendChild(overlay);
            overlay.addEventListener('click', function(e) {
                if (e.target === overlay) {
                    overlay.style.display = 'none';
                }
            });

            return {
                overlay: overlay,
                title: byId(popupId + '-title'),
                list: byId(popupId + '-list'),
                saveBtn: byId(popupId + '-save'),
                cancelBtn: byId(popupId + '-cancel'),
                allBtn: byId(popupId + '-all'),
                noneBtn: byId(popupId + '-none')
            };
        }

        function openOrderRelevancePopup(rowKey) {
            var payload = state.relevanceEditorRows ? state.relevanceEditorRows[rowKey] : null;
            if (!payload) return;

            state.relevanceEditorRowKey = rowKey;
            var popup = ensureOrderRelevancePopup();
            if (!popup.overlay || !popup.list || !popup.title) return;

            popup.title.textContent = 'Relevant für: ' + String(payload.article || 'Artikel');
            popup.list.innerHTML = '';

            var selectedLookup = {};
            (payload.selectedIds || []).forEach(function(uid) {
                selectedLookup[parseInt(uid, 10)] = true;
            });

            (payload.activeMembers || []).forEach(function(member) {
                var uid = parseInt(member.uid || 0, 10);
                if (!uid) return;
                var name = String(member.name || ('User ' + uid));
                var checked = !!selectedLookup[uid];
                var row = document.createElement('label');
                row.style.cssText = 'display:flex; align-items:center; gap:10px; padding:6px 4px; cursor:pointer; border-radius:6px;';
                row.innerHTML = '<input type="checkbox" data-member-uid="' + escapeHtml(uid) + '"' + (checked ? ' checked' : '') + '>' +
                    '<span style="display:inline-flex; align-items:center; justify-content:center; width:24px; height:24px; border-radius:50%; background:#d7ecff; color:#0d47a1; font-size:11px; font-weight:700;">' + escapeHtml(getInitials(name)) + '</span>' +
                    '<span>' + escapeHtml(name) + '</span>';
                popup.list.appendChild(row);
            });

            popup.overlay.style.display = 'flex';
        }

        function bindMemberControls(canManageMembers) {
            var r = refs();

            if (canManageMembers && r.memberInput) {
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

            if (canManageMembers && r.memberClearBtn && r.memberInput) {
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

            if (canManageMembers && r.memberAddBtn) {
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

            if (canManageMembers) {
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

                var relevanceTriggerClass = config.orderRelevanceTriggerClass || 'team-stats-order-relevance-trigger';
                document.querySelectorAll('.' + relevanceTriggerClass).forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        var rowKey = String(btn.getAttribute('data-row-key') || '');
                        if (!rowKey) return;
                        openOrderRelevancePopup(rowKey);
                    });
                });

                var popup = ensureOrderRelevancePopup();
                if (popup.cancelBtn) {
                    popup.cancelBtn.onclick = function() {
                        popup.overlay.style.display = 'none';
                    };
                }
                if (popup.noneBtn) {
                    popup.noneBtn.onclick = function() {
                        popup.list.querySelectorAll('input[type="checkbox"][data-member-uid]').forEach(function(cb) {
                            cb.checked = false;
                        });
                    };
                }
                if (popup.allBtn) {
                    popup.allBtn.onclick = function() {
                        popup.list.querySelectorAll('input[type="checkbox"][data-member-uid]').forEach(function(cb) {
                            cb.checked = true;
                        });
                    };
                }
                if (popup.saveBtn) {
                    popup.saveBtn.onclick = async function() {
                        var rowKey = String(state.relevanceEditorRowKey || '');
                        var payload = state.relevanceEditorRows ? state.relevanceEditorRows[rowKey] : null;
                        if (!payload) {
                            popup.overlay.style.display = 'none';
                            return;
                        }

                        var memberUserIds = [];
                        popup.list.querySelectorAll('input[type="checkbox"][data-member-uid]').forEach(function(cb) {
                            if (cb.checked) {
                                var uid = parseInt(cb.getAttribute('data-member-uid') || '0', 10);
                                if (uid > 0) memberUserIds.push(uid);
                            }
                        });

                        popup.saveBtn.disabled = true;
                        try {
                            await updateOrderRelevance(payload.drinkId, payload.unitPrice, memberUserIds);
                            popup.overlay.style.display = 'none';
                        } catch (e) {
                            alert(e && e.message ? e.message : 'Fehler beim Speichern der Relevanz.');
                        } finally {
                            popup.saveBtn.disabled = false;
                        }
                    };
                }
            }

            var closeBtn = document.getElementById(config.closeEventBtnId || 'team-stats-close-event-btn');
            if (closeBtn) {
                closeBtn.addEventListener('click', async function() {
                    if (!window.confirm('Abrechnung für diesen Spieltag wirklich beenden?')) {
                        return;
                    }
                    closeBtn.disabled = true;
                    try {
                        await closeCurrentTeamEvent();
                    } catch (e) {
                        closeBtn.disabled = false;
                        alert(e && e.message ? e.message : 'Fehler beim Schließen der Abrechnung.');
                    }
                });
            }
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
                state.isCurrentEventClosed = !!data.team_event_closed;

                if (typeof config.afterLoadData === 'function') {
                    config.afterLoadData(data, state, r);
                }

                var accountBalance = Number(data.account_balance || 0);
                if (r.balanceHeader) {
                    r.balanceHeader.innerHTML = 'Gesamtsaldo Konto: <span style="color:' + amountColor(accountBalance) + ';">' + formatCurrency(accountBalance) + '</span>';
                }

                var canManageMembers = !!data.can_manage_members && !data.team_event_closed;
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
