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

    function renderRelevantMemberLabels(relevantMembers, activeMemberIds) {
        var list = Array.isArray(relevantMembers) ? relevantMembers : [];
        var ids = [];
        for (var i = 0; i < list.length; i++) {
            var uid = parseInt((list[i] || {}).uid || 0, 10);
            if (uid > 0) ids.push(uid);
        }
        ids = Array.from(new Set(ids));

        var allSelected = Array.isArray(activeMemberIds) && activeMemberIds.length > 0 && ids.length === activeMemberIds.length;
        var noneSelected = ids.length === 0;
        var labelsHtml = '';

        if (allSelected) {
            labelsHtml = '<span style="display:inline-flex; align-items:center; padding:2px 8px; border-radius:999px; background:#e3f2fd; color:#0d47a1; font-size:12px; font-weight:700;">ALLE</span>';
        } else if (noneSelected) {
            labelsHtml = '<span style="display:inline-flex; align-items:center; padding:2px 8px; border-radius:999px; background:#f3f4f6; color:#5f6368; font-size:12px; font-weight:700;">NIEMAND</span>';
        } else {
            var chips = [];
            for (var ci = 0; ci < list.length; ci++) {
                var chipMember = list[ci] || {};
                var chipName = String(chipMember.name || '').trim();
                if (!chipName) continue;
                chips.push('<span title="' + escapeHtml(chipName) + '" style="display:inline-flex; align-items:center; justify-content:center; width:24px; height:24px; border-radius:50%; background:#d7ecff; color:#0d47a1; font-size:11px; font-weight:700;">' + escapeHtml(getInitials(chipName)) + '</span>');
            }
            labelsHtml = chips.join(' ');
        }

        return {
            ids: ids,
            allSelected: allSelected,
            noneSelected: noneSelected,
            html: labelsHtml
        };
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
            var extraCosts = Array.isArray(data.extra_costs) ? data.extra_costs : [];
            var guestDonations = Array.isArray(data.guest_donations) ? data.guest_donations : [];
            var orderRows = rows.filter(function(row) {
                return String((row || {}).row_type || 'order') !== 'extra_cost';
            });
            var html = '';
            var memberSharesByUid = {};
            var canEditRelevance = canManageMembers && typeof config.buildOrderRelevanceUpdateRequest === 'function';
            var canEditExtraCosts = canManageMembers
                && typeof config.buildExtraCostCreateRequest === 'function'
                && typeof config.buildExtraCostUpdateRequest === 'function'
                && typeof config.buildExtraCostDeleteRequest === 'function';
            var canEditGuestDonations = canManageMembers
                && typeof config.buildGuestDonationCreateRequest === 'function'
                && typeof config.buildGuestDonationUpdateRequest === 'function'
                && typeof config.buildGuestDonationDeleteRequest === 'function';
            var relevanceTriggerClass = config.orderRelevanceTriggerClass || 'team-stats-order-relevance-trigger';
            var extraCostAddClass = config.extraCostAddClass || 'team-stats-extra-cost-add';
            var extraCostEditClass = config.extraCostEditClass || 'team-stats-extra-cost-edit';
            var extraCostDeleteClass = config.extraCostDeleteClass || 'team-stats-extra-cost-delete';
            var extraCostRelevanceTriggerClass = config.extraCostRelevanceTriggerClass || 'team-stats-extra-cost-relevance-trigger';
            var guestDonationAddClass = config.guestDonationAddClass || 'team-stats-guest-donation-add';
            var guestDonationEditClass = config.guestDonationEditClass || 'team-stats-guest-donation-edit';
            var guestDonationDeleteClass = config.guestDonationDeleteClass || 'team-stats-guest-donation-delete';

            var activeMembers = members.filter(function(member) {
                return !!(member && member.is_member);
            });
            var activeMemberIds = activeMembers.map(function(member) {
                return parseInt(member.uid || 0, 10);
            }).filter(function(uid) {
                return uid > 0;
            });
            state.activeMembersForCurrentEvent = activeMembers.map(function(member) {
                return {
                    uid: parseInt(member.uid || 0, 10),
                    name: String(member.name || '')
                };
            });

            state.relevanceEditorRows = {};
            state.extraCostRows = {};
            state.guestDonationRows = {};

            if (orderRows.length === 0) {
                html += '<div style="color:#555;">Keine Eintraege fuer diesen Spieltag.</div>';
            } else {
                html += '<table class="default-table" style="width:100%; margin:0;">';
                html += '<tr style="background:#e3f0fa;">';
                html += '<th style="text-align:left; padding:4px 8px;">Artikel</th>';
                html += '<th style="text-align:left; padding:4px 8px;">Relevant für</th>';
                html += '<th style="text-align:right; padding:4px 8px;">Menge</th>';
                html += '<th style="text-align:right; padding:4px 8px;">Einzelpreis</th>';
                html += '<th style="text-align:right; padding:4px 8px;">Anteil p.P.</th>';
                html += '<th style="text-align:right; padding:4px 8px;">Gesamtpreis</th>';
                html += '</tr>';

                var lastCategory = null;
                for (var i = 0; i < orderRows.length; i++) {
                    var row = orderRows[i] || {};
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
                    var relevanceDisplay = renderRelevantMemberLabels(relevantMembers, activeMemberIds);
                    for (var ri = 0; ri < relevanceDisplay.ids.length; ri++) {
                        var relId = relevanceDisplay.ids[ri];
                        memberSharesByUid[relId] = (memberSharesByUid[relId] || 0) + parseFloat(row.share_per_member || 0);
                    }

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
                        selectedIds: relevanceDisplay.ids
                    };

                    html += '<tr>';
                    html += '<td style="padding:4px 8px;">' + escapeHtml(row.article || '') + '</td>';
                    html += '<td style="padding:4px 8px;">';
                    if (canEditRelevance && parseInt(row.drink_id || 0, 10) > 0 && parseFloat(row.unit_price || 0) > 0) {
                        html += '<button type="button" class="' + relevanceTriggerClass + '" data-row-key="' + escapeHtml(rowKey) + '" style="border:none; background:transparent; cursor:pointer; padding:2px; text-align:left; display:inline-flex; align-items:center; gap:6px; flex-wrap:wrap;">' + relevanceDisplay.html + '</button>';
                    } else {
                        html += relevanceDisplay.html || '-';
                    }
                    html += '</td>';
                    html += '<td style="text-align:right; padding:4px 8px;">' + escapeHtml(row.quantity || 0) + '</td>';
                    html += '<td style="text-align:right; padding:4px 8px; color:' + amountColor(row.single_price) + ';">' + formatCurrency(row.single_price) + '</td>';
                    html += '<td style="text-align:right; padding:4px 8px; color:' + amountColor(row.share_per_member) + ';">' + formatCurrency(row.share_per_member) + '</td>';
                    html += '<td style="text-align:right; padding:4px 8px; color:' + amountColor(row.total_price) + ';">' + formatCurrency(row.total_price) + '</td>';
                    html += '</tr>';
                }
                html += '</table>';
            }

            html += '<div style="margin-top:14px;">';
            html += '<div style="display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:8px; flex-wrap:wrap;">';
            html += '<h3 style="margin:0; color:#1769aa;">Extrakosten</h3>';
            html += '</div>';
            html += '<table class="default-table" style="width:100%; margin:0;">';
            html += '<tr style="background:#e3f0fa;">';
            html += '<th style="text-align:left; padding:4px 8px;">Bezahler</th>';
            html += '<th style="text-align:left; padding:4px 8px;">Kommentar</th>';
            html += '<th style="text-align:left; padding:4px 8px;">Relevant für</th>';
            html += '<th style="text-align:right; padding:4px 8px;">Betrag</th>';
            if (canEditExtraCosts) {
                html += '<th style="text-align:center; padding:4px 8px; width:170px;">Aktion</th>';
            }
            html += '</tr>';

            if (extraCosts.length === 0) {
                html += '<tr><td colspan="' + (canEditExtraCosts ? '5' : '4') + '" style="padding:8px; color:#666;">Keine Extrakosten hinterlegt.</td></tr>';
            } else {
                for (var ex = 0; ex < extraCosts.length; ex++) {
                    var extraCost = extraCosts[ex] || {};
                    var extraCostId = parseInt(extraCost.id || 0, 10);
                    if (!extraCostId) continue;

                    var extraRelevantMembers = Array.isArray(extraCost.relevant_members) ? extraCost.relevant_members : [];
                    var extraRelevanceDisplay = renderRelevantMemberLabels(extraRelevantMembers, activeMemberIds);
                    for (var eri = 0; eri < extraRelevanceDisplay.ids.length; eri++) {
                        var extraRelId = extraRelevanceDisplay.ids[eri];
                        memberSharesByUid[extraRelId] = (memberSharesByUid[extraRelId] || 0) + parseFloat(extraCost.share_per_member || 0);
                    }

                    state.extraCostRows[extraCostId] = {
                        id: extraCostId,
                        payerUserId: parseInt(extraCost.payer_user_id || 0, 10),
                        payerName: String(extraCost.payer_name || ''),
                        comment: String(extraCost.comment || ''),
                        amount: parseFloat(extraCost.amount || 0),
                        selectedIds: extraRelevanceDisplay.ids,
                        activeMembers: activeMembers.map(function(member) {
                            return {
                                uid: parseInt(member.uid || 0, 10),
                                name: String(member.name || '')
                            };
                        })
                    };

                    html += '<tr>';
                    html += '<td style="padding:4px 8px;">' + escapeHtml(extraCost.payer_name || '') + '</td>';
                    html += '<td style="padding:4px 8px;">' + escapeHtml(extraCost.comment || '-') + '</td>';
                    if (canEditExtraCosts) {
                        html += '<td style="padding:4px 8px;"><button type="button" class="' + extraCostRelevanceTriggerClass + '" data-extra-cost-id="' + escapeHtml(extraCostId) + '" style="border:none; background:transparent; cursor:pointer; padding:2px; text-align:left; display:inline-flex; align-items:center; gap:6px; flex-wrap:wrap;">' + (extraRelevanceDisplay.html || '-') + '</button></td>';
                    } else {
                        html += '<td style="padding:4px 8px;">' + (extraRelevanceDisplay.html || '-') + '</td>';
                    }
                    var extraCostDisplayAmount = (typeof extraCost.total_price !== 'undefined')
                        ? parseFloat(extraCost.total_price || 0)
                        : (0 - Math.abs(parseFloat(extraCost.amount || 0)));
                    html += '<td style="text-align:right; padding:4px 8px; color:' + amountColor(extraCostDisplayAmount) + ';">' + formatCurrency(extraCostDisplayAmount) + '</td>';
                    if (canEditExtraCosts) {
                        html += '<td style="text-align:center; padding:4px 8px; white-space:nowrap;">';
                        html += '<button type="button" class="mini-button ' + extraCostEditClass + '" data-extra-cost-id="' + escapeHtml(extraCostId) + '" style="margin-right:6px;">Bearbeiten</button>';
                        html += '<button type="button" class="mini-button ' + extraCostDeleteClass + '" data-extra-cost-id="' + escapeHtml(extraCostId) + '">Löschen</button>';
                        html += '</td>';
                    }
                    html += '</tr>';
                }
            }
            html += '</table>';
            html += '<div style="margin-top:8px; padding:8px 6px; border-top:2px solid #1769aa; font-weight:bold; color:#1769aa; display:flex; justify-content:space-between; align-items:center; gap:20px;">';
            if (canEditExtraCosts) {
                html += '<button type="button" class="mini-button ' + extraCostAddClass + '">Kosten hinzufügen</button>';
            } else {
                html += '<div></div>';
            }
            html += '<div style="display:flex; gap:20px; align-items:center;">';
            html += '<span>Gesamtsumme Ausgaben:</span>';
            html += '<span style="color:' + amountColor(data.total_sum) + ';">' + formatCurrency(data.total_sum) + '</span>';
            html += '</div>';
            html += '</div>';
            html += '</div>';

            html += '<div style="margin-top:14px;">';
            html += '<div style="display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:8px; flex-wrap:wrap;">';
            html += '<h3 style="margin:0; color:#1769aa;">Spenden von Gästen</h3>';
            html += '</div>';
            html += '<table class="default-table" style="width:100%; margin:0;">';
            html += '<tr style="background:#e3f0fa;">';
            html += '<th style="text-align:left; padding:4px 8px;">Empfänger</th>';
            html += '<th style="text-align:left; padding:4px 8px;">Kommentar</th>';
            html += '<th style="text-align:right; padding:4px 8px;">Betrag</th>';
            if (canEditGuestDonations) {
                html += '<th style="text-align:center; padding:4px 8px; width:170px;">Aktion</th>';
            }
            html += '</tr>';

            var guestDonationTotal = 0;
            if (guestDonations.length === 0) {
                html += '<tr><td colspan="' + (canEditGuestDonations ? '4' : '3') + '" style="padding:8px; color:#666;">Keine Gastspenden hinterlegt.</td></tr>';
            } else {
                for (var gd = 0; gd < guestDonations.length; gd++) {
                    var guestDonation = guestDonations[gd] || {};
                    var guestDonationId = parseInt(guestDonation.id || 0, 10);
                    if (!guestDonationId) continue;

                    var receiverUserId = parseInt(guestDonation.receiver_user_id || 0, 10);
                    var guestAmount = Math.abs(parseFloat(guestDonation.amount || 0));
                    // Note: Guest donations reduce the pool, they are NOT added to memberSharesByUid here.
                    // Receivers have additional payment obligations (they must transfer the donation cash),
                    // but this is handled separately - they pay their regular share PLUS the donation amount.

                    guestDonationTotal += guestAmount;
                    state.guestDonationRows[guestDonationId] = {
                        id: guestDonationId,
                        receiverUserId: receiverUserId,
                        receiverName: String(guestDonation.receiver_name || ''),
                        comment: String(guestDonation.comment || ''),
                        amount: guestAmount,
                        activeMembers: activeMembers.map(function(member) {
                            return {
                                uid: parseInt(member.uid || 0, 10),
                                name: String(member.name || '')
                            };
                        })
                    };

                    html += '<tr>';
                    html += '<td style="padding:4px 8px;">' + escapeHtml(guestDonation.receiver_name || '') + '</td>';
                    html += '<td style="padding:4px 8px;">' + escapeHtml(guestDonation.comment || '-') + '</td>';
                    html += '<td style="text-align:right; padding:4px 8px; color:' + amountColor(guestAmount) + ';">' + formatCurrency(guestAmount) + '</td>';
                    if (canEditGuestDonations) {
                        html += '<td style="text-align:center; padding:4px 8px; white-space:nowrap;">';
                        html += '<button type="button" class="mini-button ' + guestDonationEditClass + '" data-guest-donation-id="' + escapeHtml(guestDonationId) + '" style="margin-right:6px;">Bearbeiten</button>';
                        html += '<button type="button" class="mini-button ' + guestDonationDeleteClass + '" data-guest-donation-id="' + escapeHtml(guestDonationId) + '">Löschen</button>';
                        html += '</td>';
                    }
                    html += '</tr>';
                }
            }
            html += '</table>';
            html += '<div style="margin-top:8px; padding:8px 6px; border-top:2px solid #1769aa; font-weight:bold; color:#1769aa; display:flex; justify-content:space-between; align-items:center; gap:20px;">';
            if (canEditGuestDonations) {
                html += '<button type="button" class="mini-button ' + guestDonationAddClass + '">Spende hinzufügen</button>';
            } else {
                html += '<div></div>';
            }
            html += '<div style="display:flex; gap:20px; align-items:center;">';
            html += '<span>Gesamtsumme Gastspenden:</span>';
            html += '<span style="color:' + amountColor(guestDonationTotal) + ';">' + formatCurrency(guestDonationTotal) + '</span>';
            html += '</div>';
            html += '</div>';
            html += '</div>';

            // Adjust member shares based on guest donations
            // Guest donations REDUCE the pool to be split among members
            // Pool = total expenses - guest donations
            // Each member's base share is reduced proportionally
            // Then, each receiver's obligation is increased by their guest donation amount
            if (guestDonationTotal > 0 && activeMembers.length > 0) {
                var totalSum = Math.abs(Number(data.total_sum || 0));
                var poolAfterDonations = totalSum - guestDonationTotal;
                var reductionFactor = poolAfterDonations / totalSum;  // e.g., 15/25 = 0.6

                // Reduce all member shares proportionally
                for (var memberIdx = 0; memberIdx < activeMemberIds.length; memberIdx++) {
                    var mId = activeMemberIds[memberIdx];
                    if (typeof memberSharesByUid[mId] !== 'undefined') {
                        memberSharesByUid[mId] = memberSharesByUid[mId] * reductionFactor;
                    }
                }

                // Add guest donation obligations to receivers
                for (var gdIdx = 0; gdIdx < guestDonations.length; gdIdx++) {
                    var gd = guestDonations[gdIdx] || {};
                    var gdReceiverId = parseInt(gd.receiver_user_id || 0, 10);
                    var gdAmount = Math.abs(parseFloat(gd.amount || 0));
                    if (gdReceiverId > 0 && gdAmount > 0) {
                        memberSharesByUid[gdReceiverId] = (memberSharesByUid[gdReceiverId] || 0) - gdAmount;
                    }
                }
            }

            html += '<div style="margin-top:14px;">';
            html += '<h3 style="margin:0 0 8px 0; color:#1769aa;">Mitglieder und Beiträge</h3>';
            html += '<table class="default-table" style="width:100%; margin:0; margin-bottom:10px;">';
            html += '<tr style="background:#e3f0fa;">';
            html += '<th style="text-align:left; padding:4px 8px;">Mitglied</th>';
            html += '<th style="text-align:right; padding:4px 8px;">Bereits gezahlt</th>';
            html += '<th style="text-align:right; padding:4px 8px;">Zu zahlen</th>';
            html += '<th style="text-align:right; padding:4px 8px;">Rest</th>';
            if (canManageMembers) {
                html += '<th style="text-align:center; padding:4px 8px; width:170px;">Aktion</th>';
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
                    html += '<td style="padding:4px 8px;">' + memberDisplay + '</td>';
                    html += '<td style="text-align:right; padding:4px 8px; color:' + amountColor(memberTotalPaid) + ';">' + formatCurrency(memberTotalPaid) + '</td>';
                    if (isMember) {
                        var memberDue = memberSharesByUid[memberUid] || 0;
                        var restAmount = memberDue + memberTotalPaid;
                        html += '<td style="text-align:right; padding:4px 8px; color:' + amountColor(memberDue) + ';">' + formatCurrency(memberDue) + '</td>';
                        html += '<td style="text-align:right; padding:4px 8px; color:' + amountColor(restAmount) + ';">' + formatCurrency(restAmount) + '</td>';
                    } else {
                        html += '<td style="text-align:right; padding:4px 8px; color:#999;">-</td>';
                        html += '<td style="text-align:right; padding:4px 8px; color:#999;">-</td>';
                    }
                    if (canManageMembers) {
                        if (isMember) {
                            html += '<td style="text-align:center; padding:4px 8px;"><button type="button" class="mini-button ' + config.removeButtonClass + '" data-member-uid="' + memberUid + '">Entfernen</button></td>';
                        } else {
                            html += '<td style="text-align:center; padding:4px 8px;"><button type="button" class="mini-button ' + config.addDirectButtonClass + '" data-member-uid="' + memberUid + '">Mitglied +</button></td>';
                        }
                    }
                    html += '</tr>';
                }
                html += '<tr style="font-weight:bold; background:#f5faff;">';
                html += '<td colspan="' + (canManageMembers ? '4' : '3') + '" style="text-align:right; padding:4px 8px;">Gesamtsumme Einzahlungen</td>';
                html += '<td style="text-align:right; padding:4px 8px; color:' + amountColor(membersTotalSum) + ';">' + formatCurrency(membersTotalSum) + '</td>';
                html += '</tr>';
            }
            html += '</table>';

            var settlementBaseTotal = (typeof data.settlement_total_sum !== 'undefined')
                ? Number(data.settlement_total_sum || 0)
                : Number((data.total_sum || 0) + (data.guest_donation_due_total || 0));
            var grandTotal = settlementBaseTotal + membersTotalSum;
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
                    var disableClose = (grandTotal < 0);
                    var closeTitle = disableClose ? 'Abrechnung kann nur beendet werden, wenn die Gesamtsumme (Ausgaben + Einzahlungen) mindestens 0 € ist.' : '';
                    html += '<button type="button" id="' + (config.closeEventBtnId || 'team-stats-close-event-btn') + '" class="default-button mini-button" style="background:#d32f2f; border-color:#d32f2f; color:#fff;"' + (disableClose ? ' disabled title="' + closeTitle + '"' : '') + '>Abrechnung Beenden</button>';
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

        async function createExtraCost(payload) {
            if (typeof config.buildExtraCostCreateRequest !== 'function') {
                throw new Error('Extrakosten-Create ist nicht konfiguriert.');
            }
            var requestData = config.buildExtraCostCreateRequest(payload, state);
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
                throw new Error((data && data.error) ? data.error : 'Fehler beim Speichern der Extrakosten.');
            }
            await loadCurrentSelection();
        }

        async function updateExtraCost(payload) {
            if (typeof config.buildExtraCostUpdateRequest !== 'function') {
                throw new Error('Extrakosten-Update ist nicht konfiguriert.');
            }
            var requestData = config.buildExtraCostUpdateRequest(payload, state);
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
                throw new Error((data && data.error) ? data.error : 'Fehler beim Aktualisieren der Extrakosten.');
            }
            await loadCurrentSelection();
        }

        async function deleteExtraCost(extraCostId) {
            if (typeof config.buildExtraCostDeleteRequest !== 'function') {
                throw new Error('Extrakosten-Delete ist nicht konfiguriert.');
            }
            var requestData = config.buildExtraCostDeleteRequest(extraCostId, state);
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
                throw new Error((data && data.error) ? data.error : 'Fehler beim Löschen der Extrakosten.');
            }
            await loadCurrentSelection();
        }

        async function createGuestDonation(payload) {
            if (typeof config.buildGuestDonationCreateRequest !== 'function') {
                throw new Error('Gastspenden-Create ist nicht konfiguriert.');
            }
            var requestData = config.buildGuestDonationCreateRequest(payload, state);
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
                throw new Error((data && data.error) ? data.error : 'Fehler beim Speichern der Gastspende.');
            }
            await loadCurrentSelection();
        }

        async function updateGuestDonation(payload) {
            if (typeof config.buildGuestDonationUpdateRequest !== 'function') {
                throw new Error('Gastspenden-Update ist nicht konfiguriert.');
            }
            var requestData = config.buildGuestDonationUpdateRequest(payload, state);
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
                throw new Error((data && data.error) ? data.error : 'Fehler beim Aktualisieren der Gastspende.');
            }
            await loadCurrentSelection();
        }

        async function deleteGuestDonation(guestDonationId) {
            if (typeof config.buildGuestDonationDeleteRequest !== 'function') {
                throw new Error('Gastspenden-Delete ist nicht konfiguriert.');
            }
            var requestData = config.buildGuestDonationDeleteRequest(guestDonationId, state);
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
                throw new Error((data && data.error) ? data.error : 'Fehler beim Löschen der Gastspende.');
            }
            await loadCurrentSelection();
        }

        function ensureGuestDonationPopup() {
            var popupId = config.guestDonationPopupId || 'team-stats-guest-donation-popup';
            var overlay = byId(popupId);
            if (overlay) {
                return {
                    overlay: overlay,
                    title: byId(popupId + '-title'),
                    receiver: byId(popupId + '-receiver'),
                    amount: byId(popupId + '-amount'),
                    comment: byId(popupId + '-comment'),
                    saveBtn: byId(popupId + '-save'),
                    cancelBtn: byId(popupId + '-cancel')
                };
            }

            overlay = document.createElement('div');
            overlay.id = popupId;
            overlay.style.cssText = 'display:none; position:fixed; inset:0; background:rgba(0,0,0,0.38); z-index:100003; align-items:center; justify-content:center; padding:16px;';
            overlay.innerHTML = '' +
                '<div style="width:min(520px, 94vw); background:#fff; border-radius:12px; box-shadow:0 12px 32px rgba(0,0,0,0.22); overflow:hidden;">' +
                '  <div style="padding:14px 16px; border-bottom:1px solid #e6edf5; font-weight:700; color:#1769aa;" id="' + popupId + '-title"></div>' +
                '  <div style="padding:12px 16px; display:grid; gap:10px;">' +
                '    <label style="display:grid; gap:4px;"><span>Empfänger</span><select id="' + popupId + '-receiver" style="padding:4px 8px; border:1px solid #c8d6e5; border-radius:6px;"></select></label>' +
                '    <label style="display:grid; gap:4px;"><span>Kommentar</span><input id="' + popupId + '-comment" type="text" maxlength="255" style="padding:4px 8px; border:1px solid #c8d6e5; border-radius:6px;"></label>' +
                '    <label style="display:grid; gap:4px;"><span>Betrag</span><input id="' + popupId + '-amount" type="number" step="0.01" min="0.01" style="padding:4px 8px; border:1px solid #c8d6e5; border-radius:6px;"></label>' +
                '  </div>' +
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
                receiver: byId(popupId + '-receiver'),
                amount: byId(popupId + '-amount'),
                comment: byId(popupId + '-comment'),
                saveBtn: byId(popupId + '-save'),
                cancelBtn: byId(popupId + '-cancel')
            };
        }

        function openGuestDonationPopup(guestDonationId) {
            var popup = ensureGuestDonationPopup();
            var payload = guestDonationId ? (state.guestDonationRows ? state.guestDonationRows[guestDonationId] : null) : null;

            state.guestDonationEditorId = payload ? parseInt(payload.id || 0, 10) : 0;
            popup.title.textContent = payload ? 'Gastspende bearbeiten' : 'Gastspende hinzufügen';

            popup.receiver.innerHTML = '';
            var receiverMembers = payload && payload.activeMembers
                ? payload.activeMembers
                : (Array.isArray(state.activeMembersForCurrentEvent) ? state.activeMembersForCurrentEvent : []);
            var receiverLookup = {};
            receiverMembers.forEach(function(member) {
                var uid = parseInt(member.uid || 0, 10);
                if (!uid || receiverLookup[uid]) return;
                receiverLookup[uid] = true;
                var option = document.createElement('option');
                option.value = String(uid);
                option.textContent = String(member.name || ('User ' + uid));
                popup.receiver.appendChild(option);
            });

            popup.comment.value = payload ? String(payload.comment || '') : '';
            popup.amount.value = payload ? String(Number(payload.amount || 0).toFixed(2)) : '';
            if (payload && payload.receiverUserId) {
                popup.receiver.value = String(payload.receiverUserId);
            }

            popup.overlay.style.display = 'flex';
        }

        function ensureExtraCostPopup() {
            var popupId = config.extraCostPopupId || 'team-stats-extra-cost-popup';
            var overlay = byId(popupId);
            if (overlay) {
                return {
                    overlay: overlay,
                    title: byId(popupId + '-title'),
                    payer: byId(popupId + '-payer'),
                    amount: byId(popupId + '-amount'),
                    comment: byId(popupId + '-comment'),
                    members: byId(popupId + '-members'),
                    saveBtn: byId(popupId + '-save'),
                    cancelBtn: byId(popupId + '-cancel'),
                    allBtn: byId(popupId + '-all'),
                    noneBtn: byId(popupId + '-none')
                };
            }

            overlay = document.createElement('div');
            overlay.id = popupId;
            overlay.style.cssText = 'display:none; position:fixed; inset:0; background:rgba(0,0,0,0.38); z-index:100002; align-items:center; justify-content:center; padding:16px;';
            overlay.innerHTML = '' +
                '<div style="width:min(520px, 94vw); background:#fff; border-radius:12px; box-shadow:0 12px 32px rgba(0,0,0,0.22); overflow:hidden;">' +
                '  <div style="padding:14px 16px; border-bottom:1px solid #e6edf5; font-weight:700; color:#1769aa;" id="' + popupId + '-title"></div>' +
                '  <div style="padding:12px 16px; display:grid; gap:10px;">' +
                '    <label style="display:grid; gap:4px;"><span>Bezahler</span><select id="' + popupId + '-payer" style="padding:4px 8px; border:1px solid #c8d6e5; border-radius:6px;"></select></label>' +
                '    <label style="display:grid; gap:4px;"><span>Kommentar</span><input id="' + popupId + '-comment" type="text" maxlength="255" style="padding:4px 8px; border:1px solid #c8d6e5; border-radius:6px;"></label>' +
                '    <label style="display:grid; gap:4px;"><span>Betrag</span><input id="' + popupId + '-amount" type="number" step="0.01" min="0.01" style="padding:4px 8px; border:1px solid #c8d6e5; border-radius:6px;"></label>' +
                '    <div style="font-weight:600; color:#1769aa; margin-top:2px;">Relevant für</div>' +
                '    <div style="display:flex; gap:8px; margin-top:-4px;">' +
                '      <button type="button" class="mini-button" id="' + popupId + '-all">Alle</button>' +
                '      <button type="button" class="mini-button" id="' + popupId + '-none">Niemand</button>' +
                '    </div>' +
                '    <div id="' + popupId + '-members" style="max-height:180px; overflow:auto; border:1px solid #e6edf5; border-radius:8px; padding:8px;"></div>' +
                '  </div>' +
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
                payer: byId(popupId + '-payer'),
                amount: byId(popupId + '-amount'),
                comment: byId(popupId + '-comment'),
                members: byId(popupId + '-members'),
                saveBtn: byId(popupId + '-save'),
                cancelBtn: byId(popupId + '-cancel'),
                allBtn: byId(popupId + '-all'),
                noneBtn: byId(popupId + '-none')
            };
        }

        function openExtraCostPopup(extraCostId) {
            var popup = ensureExtraCostPopup();
            var payload = extraCostId ? (state.extraCostRows ? state.extraCostRows[extraCostId] : null) : null;

            state.extraCostEditorId = payload ? parseInt(payload.id || 0, 10) : 0;
            popup.title.textContent = payload ? 'Extrakosten bearbeiten' : 'Extrakosten hinzufügen';

            popup.payer.innerHTML = '';
            var payerMembers = payload && payload.activeMembers
                ? payload.activeMembers
                : (Array.isArray(state.activeMembersForCurrentEvent) ? state.activeMembersForCurrentEvent : []);

            var payerLookup = {};
            payerMembers.forEach(function(member) {
                var uid = parseInt(member.uid || 0, 10);
                if (!uid || payerLookup[uid]) return;
                payerLookup[uid] = true;
                var option = document.createElement('option');
                option.value = String(uid);
                option.textContent = String(member.name || ('User ' + uid));
                popup.payer.appendChild(option);
            });

            popup.comment.value = payload ? String(payload.comment || '') : '';
            popup.amount.value = payload ? String(Number(payload.amount || 0).toFixed(2)) : '';
            if (payload && payload.payerUserId) {
                popup.payer.value = String(payload.payerUserId);
            }

            var selectedLookup = {};
            (payload ? payload.selectedIds : []).forEach(function(uid) {
                selectedLookup[parseInt(uid, 10)] = true;
            });

            popup.members.innerHTML = '';
            var activeMembers = payload ? (payload.activeMembers || []) : payerMembers;
            activeMembers.forEach(function(member) {
                var uid = parseInt(member.uid || 0, 10);
                if (!uid) return;
                var name = String(member.name || ('User ' + uid));
                var checked = payload ? !!selectedLookup[uid] : true;
                var row = document.createElement('label');
                row.style.cssText = 'display:flex; align-items:center; gap:10px; padding:6px 4px; cursor:pointer; border-radius:6px;';
                row.innerHTML = '<input type="checkbox" data-member-uid="' + escapeHtml(uid) + '"' + (checked ? ' checked' : '') + '>' +
                    '<span style="display:inline-flex; align-items:center; justify-content:center; width:24px; height:24px; border-radius:50%; background:#d7ecff; color:#0d47a1; font-size:11px; font-weight:700;">' + escapeHtml(getInitials(name)) + '</span>' +
                    '<span>' + escapeHtml(name) + '</span>';
                popup.members.appendChild(row);
            });

            popup.overlay.style.display = 'flex';
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

                var extraCostAddClass = config.extraCostAddClass || 'team-stats-extra-cost-add';
                var extraCostEditClass = config.extraCostEditClass || 'team-stats-extra-cost-edit';
                var extraCostDeleteClass = config.extraCostDeleteClass || 'team-stats-extra-cost-delete';
                var extraCostRelevanceTriggerClass = config.extraCostRelevanceTriggerClass || 'team-stats-extra-cost-relevance-trigger';
                var guestDonationAddClass = config.guestDonationAddClass || 'team-stats-guest-donation-add';
                var guestDonationEditClass = config.guestDonationEditClass || 'team-stats-guest-donation-edit';
                var guestDonationDeleteClass = config.guestDonationDeleteClass || 'team-stats-guest-donation-delete';

                document.querySelectorAll('.' + extraCostAddClass).forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        openExtraCostPopup(0);
                    });
                });

                document.querySelectorAll('.' + extraCostEditClass).forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        var extraCostId = parseInt(btn.getAttribute('data-extra-cost-id') || '0', 10);
                        if (!extraCostId) return;
                        openExtraCostPopup(extraCostId);
                    });
                });

                document.querySelectorAll('.' + extraCostRelevanceTriggerClass).forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        var extraCostId = parseInt(btn.getAttribute('data-extra-cost-id') || '0', 10);
                        if (!extraCostId) return;
                        openExtraCostPopup(extraCostId);
                    });
                });

                document.querySelectorAll('.' + extraCostDeleteClass).forEach(function(btn) {
                    btn.addEventListener('click', async function() {
                        var extraCostId = parseInt(btn.getAttribute('data-extra-cost-id') || '0', 10);
                        if (!extraCostId) return;
                        if (!window.confirm('Extrakosten-Eintrag wirklich löschen?')) {
                            return;
                        }
                        btn.disabled = true;
                        try {
                            await deleteExtraCost(extraCostId);
                        } catch (e) {
                            btn.disabled = false;
                            alert(e && e.message ? e.message : 'Fehler beim Löschen der Extrakosten.');
                        }
                    });
                });

                var extraPopup = ensureExtraCostPopup();
                if (extraPopup.cancelBtn) {
                    extraPopup.cancelBtn.onclick = function() {
                        extraPopup.overlay.style.display = 'none';
                    };
                }
                if (extraPopup.allBtn) {
                    extraPopup.allBtn.onclick = function() {
                        extraPopup.members.querySelectorAll('input[type="checkbox"][data-member-uid]').forEach(function(cb) {
                            cb.checked = true;
                        });
                    };
                }
                if (extraPopup.noneBtn) {
                    extraPopup.noneBtn.onclick = function() {
                        extraPopup.members.querySelectorAll('input[type="checkbox"][data-member-uid]').forEach(function(cb) {
                            cb.checked = false;
                        });
                    };
                }
                if (extraPopup.saveBtn) {
                    extraPopup.saveBtn.onclick = async function() {
                        var payerUserId = parseInt(extraPopup.payer.value || '0', 10);
                        var amount = parseFloat(extraPopup.amount.value || '0');
                        var comment = String(extraPopup.comment.value || '').trim();
                        var relevantMemberIds = [];
                        extraPopup.members.querySelectorAll('input[type="checkbox"][data-member-uid]').forEach(function(cb) {
                            if (cb.checked) {
                                var uid = parseInt(cb.getAttribute('data-member-uid') || '0', 10);
                                if (uid > 0) relevantMemberIds.push(uid);
                            }
                        });

                        if (!payerUserId) {
                            alert('Bitte einen Bezahler auswählen.');
                            return;
                        }
                        if (!amount || amount <= 0) {
                            alert('Bitte einen gültigen Betrag eingeben.');
                            return;
                        }

                        var payload = {
                            extraCostId: parseInt(state.extraCostEditorId || 0, 10),
                            payerUserId: payerUserId,
                            amount: amount,
                            comment: comment,
                            relevantMemberIds: relevantMemberIds
                        };

                        extraPopup.saveBtn.disabled = true;
                        try {
                            if (payload.extraCostId > 0) {
                                await updateExtraCost(payload);
                            } else {
                                await createExtraCost(payload);
                            }
                            extraPopup.overlay.style.display = 'none';
                        } catch (e) {
                            alert(e && e.message ? e.message : 'Fehler beim Speichern der Extrakosten.');
                        } finally {
                            extraPopup.saveBtn.disabled = false;
                        }
                    };
                }

                document.querySelectorAll('.' + guestDonationAddClass).forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        openGuestDonationPopup(0);
                    });
                });

                document.querySelectorAll('.' + guestDonationEditClass).forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        var guestDonationId = parseInt(btn.getAttribute('data-guest-donation-id') || '0', 10);
                        if (!guestDonationId) return;
                        openGuestDonationPopup(guestDonationId);
                    });
                });

                document.querySelectorAll('.' + guestDonationDeleteClass).forEach(function(btn) {
                    btn.addEventListener('click', async function() {
                        var guestDonationId = parseInt(btn.getAttribute('data-guest-donation-id') || '0', 10);
                        if (!guestDonationId) return;
                        if (!window.confirm('Gastspenden-Eintrag wirklich löschen?')) {
                            return;
                        }
                        btn.disabled = true;
                        try {
                            await deleteGuestDonation(guestDonationId);
                        } catch (e) {
                            btn.disabled = false;
                            alert(e && e.message ? e.message : 'Fehler beim Löschen der Gastspende.');
                        }
                    });
                });

                var guestPopup = ensureGuestDonationPopup();
                if (guestPopup.cancelBtn) {
                    guestPopup.cancelBtn.onclick = function() {
                        guestPopup.overlay.style.display = 'none';
                    };
                }
                if (guestPopup.saveBtn) {
                    guestPopup.saveBtn.onclick = async function() {
                        var receiverUserId = parseInt(guestPopup.receiver.value || '0', 10);
                        var amount = parseFloat(guestPopup.amount.value || '0');
                        var comment = String(guestPopup.comment.value || '').trim();

                        if (!receiverUserId) {
                            alert('Bitte einen Empfänger auswählen.');
                            return;
                        }
                        if (!amount || amount <= 0) {
                            alert('Bitte einen gültigen Betrag eingeben.');
                            return;
                        }

                        var payload = {
                            guestDonationId: parseInt(state.guestDonationEditorId || 0, 10),
                            receiverUserId: receiverUserId,
                            amount: amount,
                            comment: comment
                        };

                        guestPopup.saveBtn.disabled = true;
                        try {
                            if (payload.guestDonationId > 0) {
                                await updateGuestDonation(payload);
                            } else {
                                await createGuestDonation(payload);
                            }
                            guestPopup.overlay.style.display = 'none';
                        } catch (e) {
                            alert(e && e.message ? e.message : 'Fehler beim Speichern der Gastspende.');
                        } finally {
                            guestPopup.saveBtn.disabled = false;
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
