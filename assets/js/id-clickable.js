/**
 * UniWeb — make entity IDs clickable everywhere in the page body.
 * Matches TXN/LNK/CT/TKT/RFD/DSP/STL/BAT/INV/PACK/PWL/ORD/UW… codes in text nodes.
 */
(function () {
  'use strict';
  var RE = /\b((?:TXN|LNK|CT|TKT|RFD|DSP|STL|BAT|INV|PACK|PWL|ORD|EVT|JRN|UW)[A-F0-9]{6,})\b/gi;
  var SKIP = { A: 1, SCRIPT: 1, STYLE: 1, TEXTAREA: 1, INPUT: 1, SELECT: 1, OPTION: 1, CODE: 1, PRE: 1, BUTTON: 1 };

  function hub(id) {
    return 'id_go.php?id=' + encodeURIComponent(id);
  }

  function wrapTextNode(node) {
    var text = node.nodeValue;
    if (!text || !RE.test(text)) return;
    RE.lastIndex = 0;
    var frag = document.createDocumentFragment();
    var last = 0;
    var m;
    while ((m = RE.exec(text))) {
      if (m.index > last) {
        frag.appendChild(document.createTextNode(text.slice(last, m.index)));
      }
      var id = m[1];
      var a = document.createElement('a');
      a.href = hub(id);
      a.className = 'uw-id-link font-mono text-sky-400 hover:underline';
      a.setAttribute('data-uw-id', id);
      a.title = 'Open ' + id;
      a.textContent = id;
      frag.appendChild(a);
      last = m.index + id.length;
    }
    if (last < text.length) {
      frag.appendChild(document.createTextNode(text.slice(last)));
    }
    node.parentNode.replaceChild(frag, node);
  }

  function walk(root) {
    var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
      acceptNode: function (n) {
        var p = n.parentElement;
        if (!p || SKIP[p.tagName]) return NodeFilter.FILTER_REJECT;
        if (p.closest && (p.closest('a') || p.closest('[data-uw-id-skip]'))) {
          return NodeFilter.FILTER_REJECT;
        }
        if (!n.nodeValue || !/\b(?:TXN|LNK|CT|TKT|RFD|DSP|STL|BAT|INV|PACK|PWL|ORD|UW)/i.test(n.nodeValue)) {
          return NodeFilter.FILTER_REJECT;
        }
        return NodeFilter.FILTER_ACCEPT;
      }
    });
    var nodes = [];
    while (walker.nextNode()) nodes.push(walker.currentNode);
    nodes.forEach(wrapTextNode);
  }

  function upgradePlain() {
    document.querySelectorAll('[data-uw-id]:not(a)').forEach(function (el) {
      var id = el.getAttribute('data-uw-id');
      if (!id) return;
      var a = document.createElement('a');
      a.href = hub(id);
      a.className = (el.className || '') + ' uw-id-link text-sky-400 hover:underline';
      a.setAttribute('data-uw-id', id);
      a.textContent = el.textContent;
      el.parentNode.replaceChild(a, el);
    });
  }

  function run() {
    var root = document.querySelector('main') || document.body;
    if (!root) return;
    try {
      walk(root);
      upgradePlain();
    } catch (e) { /* ignore */ }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', run);
  } else {
    run();
  }
})();
