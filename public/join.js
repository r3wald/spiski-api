// Zeigt den Teilen-Code aus der URL (/join/<code>) gut lesbar in Vierergruppen.
(function () {
  var code = decodeURIComponent(location.pathname.replace(/^\/join\/?/, '')).replace(/[^0-9A-Za-z]/g, '').toUpperCase();
  if (code) {
    document.getElementById('code').textContent = code.match(/.{1,4}/g).join('-');
    // Die Web-App tritt der Liste ueber denselben Pfad bei.
    document.getElementById('web').href = '/app/join/' + encodeURIComponent(code);
  }
})();
