(function () {
  'use strict';

  var PanelModal = {
    dirty: false,
    opener: null,
    _tt: null,

    open: function (dialogEl) {
      if (!dialogEl || typeof dialogEl.showModal !== 'function') return;
      this.opener = document.activeElement;
      this.dirty = false;
      dialogEl.dataset.pmDirty = '0';
      dialogEl.showModal();
      // Prefer explicit data-pm-focus (confirm dialogs). Do not steal focus to Save
      // on edit forms — operators expect the first field.
      var prefer = dialogEl.querySelector('[data-pm-focus]');
      if (!prefer && dialogEl.getAttribute('data-pm-nodirty') === '1') {
        prefer = dialogEl.querySelector('.pm-btn-danger-solid, .pm-btn-primary');
      }
      var first = prefer || dialogEl.querySelector(
        'input:not([type=hidden]):not([type=checkbox]):not([readonly]), select, textarea'
      );
      if (first) {
        try { first.focus(); } catch (e) {}
      }
    },

    close: function (dialogEl, force) {
      if (!dialogEl) return;
      var skipDirty = dialogEl.getAttribute('data-pm-nodirty') === '1';
      var isDirty = !skipDirty && (dialogEl.dataset.pmDirty === '1' || this.dirty);
      var msg = this._unsaved || 'Есть несохранённые изменения. Закрыть без сохранения?';
      if (!force && isDirty && !window.confirm(msg)) return;
      dialogEl.close();
      this.dirty = false;
      dialogEl.dataset.pmDirty = '0';
      if (this.opener && typeof this.opener.focus === 'function') {
        try { this.opener.focus(); } catch (e) {}
      }
      this.opener = null;
    },

    toast: function (msg, isError) {
      if (!msg) return;
      var t = document.getElementById('pm-toast');
      if (!t) return;
      t.textContent = msg;
      t.classList.toggle('pm-error', !!isError);
      t.classList.add('pm-show');
      clearTimeout(this._tt);
      this._tt = setTimeout(function () { t.classList.remove('pm-show'); }, 2400);
    },

    bindDialog: function (dialogEl) {
      if (!dialogEl || dialogEl.dataset.pmBound === '1') return;
      dialogEl.dataset.pmBound = '1';
      var self = this;
      var nodirty = dialogEl.getAttribute('data-pm-nodirty') === '1';
      if (!nodirty) {
        dialogEl.addEventListener('input', function () {
          self.dirty = true;
          dialogEl.dataset.pmDirty = '1';
        });
        dialogEl.addEventListener('change', function () {
          self.dirty = true;
          dialogEl.dataset.pmDirty = '1';
        });
      }
      dialogEl.addEventListener('cancel', function (e) {
        e.preventDefault();
        self.close(dialogEl);
      });
      dialogEl.addEventListener('click', function (e) {
        if (e.target === dialogEl) self.close(dialogEl);
        if (e.target.closest && e.target.closest('[data-pm-close]')) {
          e.preventDefault();
          self.close(dialogEl);
        }
      });
      dialogEl.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
          e.preventDefault();
          var form = dialogEl.querySelector('form');
          if (form) form.requestSubmit();
        }
      });
    },

    boot: function (cfg) {
      cfg = cfg || {};
      this._unsaved = cfg.unsaved || this._unsaved;
      document.querySelectorAll('dialog.pm-dialog').forEach(function (d) {
        PanelModal.bindDialog(d);
      });
      document.querySelectorAll('[data-pm-open]').forEach(function (btn) {
        if (btn.dataset.pmOpenBound === '1') return;
        btn.dataset.pmOpenBound = '1';
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          var id = btn.getAttribute('data-pm-open');
          var dlg = id ? document.getElementById(id) : null;
          if (dlg) PanelModal.open(dlg);
        });
      });
      document.querySelectorAll('table[data-pm-table]').forEach(function (table) {
        PanelTable.bind(table);
      });
      if (cfg.message) {
        this.toast(cfg.message, !!cfg.error);
      }
    }
  };

  /**
   * Shared ↑/↓ + Enter + header sort for admin tables.
   * Row open: data-href (navigate) or custom onOpen(tr).
   */
  var PanelTable = {
    bind: function (table, opts) {
      if (!table || table.dataset.pmTableBound === '1') return;
      table.dataset.pmTableBound = '1';
      opts = opts || {};
      var tbody = table.tBodies[0] || table.querySelector('tbody');
      if (!tbody) return;
      var rowSel = opts.rowSelector || 'tr[data-href], tr[data-goto], tr[data-open]';

      function rows() {
        return Array.prototype.slice.call(tbody.querySelectorAll(rowSel)).filter(function (r) {
          return r.style.display !== 'none';
        });
      }

      function selectRow(tr) {
        rows().forEach(function (r) { r.classList.remove('pm-sel'); });
        if (tr) {
          tr.classList.add('pm-sel');
          try { tr.focus(); } catch (e) {}
        }
      }

      function openRow(tr) {
        if (!tr) return;
        if (typeof opts.onOpen === 'function') {
          opts.onOpen(tr);
          return;
        }
        var href = tr.getAttribute('data-href') || tr.getAttribute('data-goto') || '';
        if (href) {
          if (tr.hasAttribute('data-goto') && window.DirectoryGoto && typeof window.DirectoryGoto.ask === 'function') {
            window.DirectoryGoto.ask(tr.getAttribute('data-ref-name') || '', href);
          } else {
            window.location = href;
          }
        }
      }

      tbody.addEventListener('click', function (e) {
        if (e.target.closest('a,button,input,label,select,textarea,form')) return;
        var tr = e.target.closest(rowSel);
        if (tr) {
          selectRow(tr);
          if (opts.openOnClick !== false && (tr.hasAttribute('data-goto') || opts.openOnSingleClick)) {
            openRow(tr);
          }
        }
      });

      tbody.addEventListener('dblclick', function (e) {
        var tr = e.target.closest(rowSel);
        if (tr) openRow(tr);
      });

      tbody.addEventListener('keydown', function (e) {
        var tr = e.target.closest(rowSel);
        if (!tr) return;
        var list = rows();
        var i = list.indexOf(tr);
        if (e.key === 'Enter') {
          e.preventDefault();
          openRow(tr);
        }
        if (e.key === 'ArrowDown' && list[i + 1]) {
          e.preventDefault();
          selectRow(list[i + 1]);
        }
        if (e.key === 'ArrowUp' && list[i - 1]) {
          e.preventDefault();
          selectRow(list[i - 1]);
        }
      });

      table.querySelectorAll('th[data-sort]').forEach(function (th) {
        th.addEventListener('click', function () {
          var key = th.getAttribute('data-sort');
          var dir = th.dataset.dir === '1' ? -1 : 1;
          table.querySelectorAll('th[data-sort]').forEach(function (x) {
            x.dataset.dir = '';
            x.removeAttribute('aria-sort');
          });
          th.dataset.dir = String(dir);
          th.setAttribute('aria-sort', dir > 0 ? 'ascending' : 'descending');
          var headers = Array.prototype.slice.call(table.querySelectorAll('thead th'));
          var colIdx = headers.indexOf(th);
          if (colIdx < 0) return;
          var list = rows();
          if (list.length === 0) {
            list = Array.prototype.slice.call(tbody.querySelectorAll('tr')).filter(function (r) {
              return r.style.display !== 'none';
            });
          }
          var sorted = list.slice().sort(function (a, b) {
            var av = (a.children[colIdx] && a.children[colIdx].textContent || '').trim();
            var bv = (b.children[colIdx] && b.children[colIdx].textContent || '').trim();
            var an = parseFloat(av.replace(/[^\d.-]/g, ''));
            var bn = parseFloat(bv.replace(/[^\d.-]/g, ''));
            if (key === 'id' || key === 'rel_count' || (!isNaN(an) && !isNaN(bn) && /^-?\d/.test(av) && /^-?\d/.test(bv))) {
              return (an - bn) * dir;
            }
            return av.localeCompare(bv, undefined, { sensitivity: 'base' }) * dir;
          });
          sorted.forEach(function (r) { tbody.appendChild(r); });
        });
      });
    }
  };

  window.PanelModal = PanelModal;
  window.PanelTable = PanelTable;
})();
