// MAIN-world-Bridge: läuft im Seitenkontext und kann window.Ext (ExtJS) ansprechen.
// Das isolierte Content-Script (content.js) sieht window.Ext NICHT und ruft hierüber an.
(function () {
  'use strict';
  window.addEventListener('message', function (ev) {
    var d = ev.data;
    if (!d || d.__lpnBridge !== 'req') return;
    var out = { __lpnBridge: 'resp', id: d.id, action: d.action };
    try {
      if (d.action === 'extReachable') {
        out.ok = !!(window.Ext && window.Ext.ComponentQuery);
      } else if (d.action === 'extSelect') {
        var r = extSelectRow(d.po); out.ok = r.ok; out.why = r.why;
      }
    } catch (e) { out.ok = false; out.why = 'bridge: ' + (e && e.message); }
    try { window.postMessage(out, '*'); } catch (e) {}
  });

  // Zeile im ExtJS-Grid programmatisch wählen (synthetische Klicks ignoriert Ext).
  function extSelectRow(po) {
    var Ext = window.Ext;
    if (!Ext || !Ext.ComponentQuery) return { ok: false, why: 'Ext nicht erreichbar' };
    po = String(po);
    var views = [];
    try { views = Ext.ComponentQuery.query('tableview').concat(Ext.ComponentQuery.query('gridview')); }
    catch (e) { return { ok: false, why: 'query: ' + (e && e.message) }; }
    for (var i = 0; i < views.length; i++) {
      var view = views[i], store = null;
      try { store = view.getStore && view.getStore(); } catch (e) {}
      if (!store || !store.getCount) continue;
      var rec = null;
      try {
        var idx = store.findBy(function (r) {
          var dd = (r && r.data) || {};
          for (var k in dd) { if (String(dd[k]) === po) return true; }
          return false;
        });
        if (idx >= 0) rec = store.getAt(idx);
      } catch (e) {}
      if (!rec) continue;
      try {
        var sm = (view.ownerGrid && view.ownerGrid.getSelectionModel && view.ownerGrid.getSelectionModel())
          || (view.getSelectionModel && view.getSelectionModel());
        if (sm && sm.select) { try { sm.deselectAll && sm.deselectAll(); } catch (e) {} sm.select(rec); return { ok: true, why: 'Ext-Select' }; }
      } catch (e) { return { ok: false, why: 'select: ' + (e && e.message) }; }
    }
    return { ok: false, why: 'PO nicht im Store' };
  }
})();
