/* global qpTemplateData, jQuery */
(function ($) {
    'use strict';

    var D = window.qpTemplateData || {};

    /* ── Render a sortable list ─────────────────────────────────── */

    function renderList(type, sections) {
        var ul = document.getElementById('qptb-list-' + type);
        if (!ul) return;

        ul.innerHTML = '';

        sections.forEach(function (sec, idx) {
            var li = document.createElement('li');
            li.className = 'qptb-item' + (sec.hidden ? ' hidden-sec' : '');
            li.setAttribute('data-key',    sec.key);
            li.setAttribute('data-hidden', sec.hidden  ? '1' : '0');
            li.setAttribute('data-locked', sec.locked  ? '1' : '0');

            // Drag handle
            var handle = document.createElement('div');
            handle.className   = 'qptb-handle';
            handle.textContent = '≡';
            handle.title       = 'Sürükle';

            // Order number badge
            var num = document.createElement('span');
            num.className   = 'qptb-num';
            num.textContent = String(idx + 1);

            // Label
            var label = document.createElement('span');
            label.className   = 'qptb-label';
            label.textContent = sec.label;

            li.appendChild(handle);
            li.appendChild(num);
            li.appendChild(label);

            if (sec.locked) {
                var badge = document.createElement('span');
                badge.className   = 'qptb-locked';
                badge.textContent = 'Kilitli';
                li.appendChild(badge);
            } else {
                var visBtn = document.createElement('button');
                visBtn.type      = 'button';
                visBtn.className = 'qptb-vis-btn';
                visBtn.title     = sec.hidden ? 'Göster' : 'Gizle';
                visBtn.textContent = sec.hidden ? '🙈' : '👁';
                visBtn.setAttribute('onclick', 'qptbToggleVisibility(this)');
                li.appendChild(visBtn);
            }

            ul.appendChild(li);
        });

        initSortable(type);
    }

    /* ── jQuery UI Sortable init ────────────────────────────────── */

    function initSortable(type) {
        var $ul = $('#qptb-list-' + type);
        if (!$ul.length) return;

        // Destroy existing instance if reinitializing
        if ($ul.hasClass('ui-sortable')) {
            $ul.sortable('destroy');
        }

        $ul.sortable({
            handle:      '.qptb-handle',
            placeholder: 'qptb-item ui-sortable-placeholder',
            tolerance:   'pointer',
            axis:        'y',
            stop: function () {
                updateNumbers(type);
            }
        });
        $ul.disableSelection();
    }

    /* ── Update order numbers after sort ───────────────────────── */

    function updateNumbers(type) {
        var ul = document.getElementById('qptb-list-' + type);
        if (!ul) return;
        var items = ul.querySelectorAll('.qptb-item');
        items.forEach(function (li, idx) {
            var num = li.querySelector('.qptb-num');
            if (num) num.textContent = String(idx + 1);
        });
    }

    /* ── Toggle visibility ──────────────────────────────────────── */

    window.qptbToggleVisibility = function (btn) {
        var li = btn.closest('li.qptb-item');
        if (!li) return;

        var isHidden = li.getAttribute('data-hidden') === '1';
        var nowHidden = !isHidden;

        li.setAttribute('data-hidden', nowHidden ? '1' : '0');
        li.classList.toggle('hidden-sec', nowHidden);
        btn.textContent = nowHidden ? '🙈' : '👁';
        btn.title       = nowHidden ? 'Göster' : 'Gizle';
    };

    /* ── Save ───────────────────────────────────────────────────── */

    window.qptbSave = function (type) {
        var ul = document.getElementById('qptb-list-' + type);
        if (!ul) return;

        var sections = [];
        var items    = ul.querySelectorAll('.qptb-item');
        items.forEach(function (li) {
            sections.push({
                key:    li.getAttribute('data-key'),
                label:  li.querySelector('.qptb-label') ? li.querySelector('.qptb-label').textContent : '',
                hidden: li.getAttribute('data-hidden') === '1',
                locked: li.getAttribute('data-locked') === '1'
            });
        });

        $.ajax({
            url:    D.ajax_url,
            type:   'POST',
            data:   {
                action:        'qp_save_template',
                template_type: type,
                sections:      JSON.stringify(sections),
                _wpnonce:      D.nonce
            },
            success: function (response) {
                if (response && response.success) {
                    var msgEl = document.getElementById('qptb-msg-' + type);
                    if (msgEl) {
                        msgEl.style.display = 'inline';
                        setTimeout(function () {
                            msgEl.style.display = 'none';
                        }, 2000);
                    }
                } else {
                    var errMsg = (response && response.data && response.data.message)
                        ? response.data.message
                        : 'Kaydetme başarısız.';
                    alert(errMsg);
                }
            },
            error: function () {
                alert('Sunucu hatası. Lütfen tekrar deneyin.');
            }
        });
    };

    /* ── Reset ──────────────────────────────────────────────────── */

    window.qptbReset = function (type) {
        if (!confirm('Bu şablonu varsayılana sıfırlamak istiyor musunuz?')) {
            return;
        }
        $.ajax({
            url:    D.ajax_url,
            type:   'POST',
            data:   {
                action:        'qp_save_template',
                template_type: type,
                sections:      JSON.stringify([]),   // empty → server reverts to defaults
                _wpnonce:      D.nonce
            },
            success: function (response) {
                if (response && response.success) {
                    location.reload();
                } else {
                    alert('Sıfırlama başarısız.');
                }
            },
            error: function () {
                alert('Sunucu hatası. Lütfen tekrar deneyin.');
            }
        });
    };

    /* ── Tab switching ──────────────────────────────────────────── */

    window.qptbTab = function (id, btn) {
        document.querySelectorAll('.qptb-panel').forEach(function (p) {
            p.classList.remove('active');
        });
        document.querySelectorAll('.qptb-tab').forEach(function (b) {
            b.classList.remove('active');
        });
        var panel = document.getElementById('qptb-' + id);
        if (panel) panel.classList.add('active');
        if (btn)   btn.classList.add('active');
    };

    /* ── Init on DOM ready ──────────────────────────────────────── */

    document.addEventListener('DOMContentLoaded', function () {
        if (D.ig  && Array.isArray(D.ig))  renderList('ig',  D.ig);
        if (D.std && Array.isArray(D.std)) renderList('std', D.std);
    });

}(jQuery));
