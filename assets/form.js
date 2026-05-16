/* global qpData */
(function () {
    'use strict';

    var D = window.qpData || {};
    var categories   = D.categories    || [];
    var extraLabel   = D.extraLabel    || '';
    var extraChoices = D.extraChoices  || [];
    var extraTriggers= D.extraTriggers || [];
    var i18n         = D.i18n          || {};
    var catalog      = D.catalog       || [];   // [{id, label, group_id, group_name, template, unit, price, desc}, ...]
    var catalogMap   = {};
    catalog.forEach(function(c){ catalogMap[c.label] = c; });

    /* ── Build one product row ──────────────────────────────── */
    function buildRow() {
        var tr = document.createElement('tr');

        // Product select
        var td1 = document.createElement('td');
        td1.style.width = '32%';
        var sel = document.createElement('select');
        sel.name = 'item_name[]';
        var opt0 = document.createElement('option');
        opt0.value = '';
        opt0.textContent = i18n.selectProduct || '-- Seçiniz --';
        sel.appendChild(opt0);

        if (catalog.length > 0) {
            // Kataloğu gruba göre grupla
            var groups = {};
            catalog.forEach(function(c){
                if (!groups[c.group_name]) groups[c.group_name] = [];
                groups[c.group_name].push(c);
            });
            Object.keys(groups).forEach(function(gname){
                var og = document.createElement('optgroup');
                og.label = gname;
                groups[gname].forEach(function(c){
                    var opt = document.createElement('option');
                    opt.value = c.label;
                    opt.textContent = c.label + (c.unit ? ' ('+c.unit+')' : '');
                    opt.dataset.price = c.price || '';
                    opt.dataset.unit  = c.unit  || '';
                    opt.dataset.template = c.template || 'default';
                    og.appendChild(opt);
                });
                sel.appendChild(og);
            });
        } else {
            // Eski düz liste
            categories.forEach(function (cat) {
                var opt = document.createElement('option');
                opt.value = cat;
                opt.textContent = cat;
                sel.appendChild(opt);
            });
        }

        sel.addEventListener('change', function(){
            checkExtraOption();
            // Varsayılan fiyatı birim alanına yaz (bilgi notu)
            var selected = sel.options[sel.selectedIndex];
            var note = tr.querySelector('.qp-item-unit-note');
            if (note && selected && selected.dataset.unit) {
                note.textContent = 'Birim: ' + selected.dataset.unit;
            } else if (note) {
                note.textContent = '';
            }
        });
        td1.appendChild(sel);

        // Birim notu
        var unitNote = document.createElement('div');
        unitNote.className = 'qp-item-unit-note';
        unitNote.style.cssText = 'font-size:11px;color:#aaa;margin-top:3px;';
        td1.appendChild(unitNote);

        // Model input
        var td2 = document.createElement('td');
        td2.style.width = '26%';
        var inp2 = document.createElement('input');
        inp2.type = 'text';
        inp2.name = 'item_model[]';
        inp2.placeholder = i18n.modelPlaceholder || 'Model, özellik...';
        td2.appendChild(inp2);

        // Qty input — DÜZENLENEBİLİR
        var td3 = document.createElement('td');
        td3.style.width = '10%';
        var inp3 = document.createElement('input');
        inp3.type  = 'number';
        inp3.name  = 'item_qty[]';
        inp3.value = '1';
        inp3.min   = '1';
        inp3.style.cssText = 'text-align:center;';
        td3.appendChild(inp3);

        // Note input
        var td4 = document.createElement('td');
        td4.style.width = '26%';
        var inp4 = document.createElement('input');
        inp4.type = 'text';
        inp4.name = 'item_note[]';
        inp4.placeholder = i18n.optional || 'Opsiyonel';
        td4.appendChild(inp4);

        // Remove button
        var td5 = document.createElement('td');
        td5.style.width = '6%';
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'qp-row-remove';
        btn.textContent = '×';
        btn.title = 'Kaldır';
        btn.addEventListener('click', function () {
            var tbody = document.getElementById('qp-items-body');
            if (tbody && tbody.rows.length > 1) {
                tr.parentNode.removeChild(tr);
                checkExtraOption();
            }
        });
        td5.appendChild(btn);

        tr.appendChild(td1);
        tr.appendChild(td2);
        tr.appendChild(td3);
        tr.appendChild(td4);
        tr.appendChild(td5);
        return tr;
    }

    /* ── Show / hide extra option ───────────────────────────── */
    function checkExtraOption() {
        if (!extraLabel || !extraChoices.length || !extraTriggers.length) return;
        var wrap = document.getElementById('qp-extra-wrap');
        if (!wrap) return;

        var selects = document.querySelectorAll('#qp-items-body select[name="item_name[]"]');
        var show = false;
        selects.forEach(function (s) {
            if (extraTriggers.indexOf(s.value) > -1) show = true;
        });
        wrap.style.display = show ? 'block' : 'none';
    }

    /* ── Build extra option choices ─────────────────────────── */
    function buildExtraChoices() {
        var wrap   = document.getElementById('qp-extra-wrap');
        var label  = document.getElementById('qp-extra-label');
        var group  = document.getElementById('qp-extra-choices');
        if (!wrap || !label || !group) return;

        label.textContent = extraLabel;
        group.innerHTML   = '';

        extraChoices.forEach(function (choice) {
            var lbl = document.createElement('label');
            var inp = document.createElement('input');
            inp.type  = 'radio';
            inp.name  = 'extra_option';
            inp.value = choice;
            var span  = document.createElement('span');
            span.textContent = choice;
            lbl.appendChild(inp);
            lbl.appendChild(span);
            group.appendChild(lbl);
        });
    }

    /* ── Add product button ─────────────────────────────────── */
    function initAddButton() {
        var btn = document.getElementById('qp-add-item');
        if (btn) {
            btn.addEventListener('click', function () {
                var tbody = document.getElementById('qp-items-body');
                if (tbody) tbody.appendChild(buildRow());
            });
        }
    }

    /* ── Form submit ────────────────────────────────────────── */
    function initForm() {
        var form = document.getElementById('qpForm');
        if (!form) return;

        form.addEventListener('submit', function (e) {
            e.preventDefault();

            var msg = document.getElementById('qp-form-msg');
            var btn = document.getElementById('qp-submit-btn');

            // Extra option required?
            var extraWrap = document.getElementById('qp-extra-wrap');
            if (extraWrap && extraWrap.style.display !== 'none' && extraLabel) {
                var checked = form.querySelector('input[name="extra_option"]:checked');
                if (!checked) {
                    showMsg(msg, 'qp-error', (i18n.selectComm || 'Lütfen seçin: ') + extraLabel);
                    extraWrap.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    return;
                }
            }

            btn.textContent = i18n.sending || 'Gönderiliyor...';
            btn.disabled    = true;
            if (msg) msg.style.display = 'none';

            var data = new FormData(form);
            data.set('action', 'qp_submit_form');

            fetch(D.ajaxUrl || '/wp-admin/admin-ajax.php', {
                method:  'POST',
                body:    data,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success) {
                    showMsg(msg, 'qp-success', i18n.successMsg || 'Talebiniz iletildi!');
                    form.reset();
                    var tbody = document.getElementById('qp-items-body');
                    if (tbody) { tbody.innerHTML = ''; tbody.appendChild(buildRow()); }
                    checkExtraOption();
                } else {
                    showMsg(msg, 'qp-error', i18n.errorMsg || 'Bir sorun oluştu.');
                }
                btn.textContent = i18n.submitBtn || 'Teklif Talebi Gönder ›';
                btn.disabled    = false;
                if (msg) msg.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            })
            .catch(function () {
                showMsg(msg, 'qp-error', i18n.connectionError || 'Bağlantı hatası.');
                btn.textContent = i18n.submitBtn || 'Teklif Talebi Gönder ›';
                btn.disabled    = false;
            });
        });
    }

    function showMsg(el, cls, text) {
        if (!el) return;
        el.className      = cls;
        el.textContent    = text;
        el.style.display  = 'block';
    }

    /* ── Init ───────────────────────────────────────────────── */
    document.addEventListener('DOMContentLoaded', function () {
        buildExtraChoices();
        var tbody = document.getElementById('qp-items-body');
        if (tbody) tbody.appendChild(buildRow());
        initAddButton();
        initForm();
    });

}());
