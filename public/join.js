// Zeigt den Teilen-Code aus der URL (/join/<code>) gut lesbar in Vierergruppen.
(function () {
  var code = decodeURIComponent(location.pathname.replace(/^\/join\/?/, '')).replace(/[^0-9A-Za-z]/g, '').toUpperCase();
  if (code) {
    document.getElementById('code').textContent = code.match(/.{1,4}/g).join('-');
    // Die Web-App tritt der Liste ueber denselben Pfad bei. Bei verschluesselten
    // Listen stehen Listenschluessel und Listen-ID im Fragment (#k=…&l=…,
    // CLAUDE.md 14): Es geht nie an den Server und muss deshalb hier an den
    // Verweis gehaengt werden.
    var key = /^#k=[A-Za-z0-9_-]+&l=[0-9a-fA-F-]{36}$/.test(location.hash) ? location.hash : '';
    document.getElementById('web').href = '/app/join/' + encodeURIComponent(code) + key;
  }
})();
